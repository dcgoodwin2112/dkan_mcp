<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * MCP tools for DKAN service introspection.
 */
class ServiceTools {

  public function __construct(
    protected ContainerInterface $container,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * List DKAN service IDs with class names.
   */
  public function listServices(?string $module = NULL, bool $brief = FALSE): array {
    $prefix = $module ? "dkan.{$module}." : 'dkan.';
    $ids = array_filter(
      $this->container->getServiceIds(),
      fn($id) => str_starts_with($id, $prefix)
    );
    sort($ids);

    if ($brief) {
      return ['services' => array_values($ids), 'total' => count($ids)];
    }

    $services = [];
    foreach ($ids as $id) {
      try {
        $service = $this->container->get($id);
        $services[] = ['id' => $id, 'class' => get_class($service)];
      }
      catch (\Exception $e) {
        $services[] = ['id' => $id, 'class' => NULL, 'error' => $e->getMessage()];
      }
    }

    return ['services' => $services, 'total' => count($services)];
  }

  /**
   * Get the full public API of any PHP class or interface.
   */
  public function getClassInfo(string $className, ?string $methods = NULL): array {
    if (!class_exists($className) && !interface_exists($className)) {
      return ['error' => "Class or interface not found: {$className}"];
    }

    $reflection = new \ReflectionClass($className);
    $patterns = $methods ? array_map('trim', explode(',', $methods)) : NULL;

    $methodList = [];
    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      if (str_starts_with($method->getName(), '_')) {
        continue;
      }
      if ($patterns && !$this->matchesAnyPattern($method->getName(), $patterns)) {
        continue;
      }
      $params = array_map(fn(\ReflectionParameter $p) => array_filter([
        'name' => $p->getName(),
        'type' => $p->getType() ? (string) $p->getType() : NULL,
        'optional' => $p->isOptional() ?: NULL,
      ], fn($v) => $v !== NULL), $method->getParameters());
      $methodList[] = [
        'name' => $method->getName(),
        'params' => array_values($params),
        'return_type' => $method->getReturnType() ? (string) $method->getReturnType() : NULL,
        'declared_in' => $method->getDeclaringClass()->getName(),
      ];
    }

    $parent = $reflection->getParentClass();
    $interfaces = array_values(array_map(
      fn(\ReflectionClass $i) => $i->getName(),
      $reflection->getInterfaces()
    ));

    return [
      'class' => $className,
      'is_abstract' => $reflection->isAbstract(),
      'is_interface' => $reflection->isInterface(),
      'parent' => $parent ? $parent->getName() : NULL,
      'interfaces' => $interfaces,
      'methods' => $methodList,
    ];
  }

  /**
   * Get detailed service info via reflection.
   */
  public function getServiceInfo(string $serviceId, ?string $methods = NULL, bool $includeYaml = TRUE): array {
    if (!$this->container->has($serviceId)) {
      return ['error' => "Service not found: {$serviceId}"];
    }

    try {
      $service = $this->container->get($serviceId);
    }
    catch (\Exception $e) {
      return ['error' => "Cannot instantiate service: {$e->getMessage()}"];
    }

    $class = get_class($service);
    $reflection = new \ReflectionClass($class);
    $patterns = $methods ? array_map('trim', explode(',', $methods)) : NULL;

    $constructorParams = [];
    $constructor = $reflection->getConstructor();
    if ($constructor) {
      foreach ($constructor->getParameters() as $param) {
        $type = $param->getType();
        $entry = [
          'name' => $param->getName(),
          'type' => $type instanceof \ReflectionNamedType ? $type->getName() : ($type ? (string) $type : NULL),
        ];
        if ($param->isOptional()) {
          $entry['optional'] = TRUE;
        }
        $constructorParams[] = $entry;
      }
    }

    $methodList = [];
    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      if (str_starts_with($method->getName(), '_')) {
        continue;
      }
      if ($method->getDeclaringClass()->getName() !== $class) {
        continue;
      }
      if ($patterns && !$this->matchesAnyPattern($method->getName(), $patterns)) {
        continue;
      }
      $params = array_map(fn(\ReflectionParameter $p) => array_filter([
        'name' => $p->getName(),
        'type' => $p->getType() ? (string) $p->getType() : NULL,
        'optional' => $p->isOptional() ?: NULL,
      ], fn($v) => $v !== NULL), $method->getParameters());
      $methodList[] = [
        'name' => $method->getName(),
        'params' => array_values($params),
        'return_type' => $method->getReturnType() ? (string) $method->getReturnType() : NULL,
      ];
    }

    $result = [
      'service_id' => $serviceId,
      'class' => $class,
      'constructor_params' => $constructorParams,
      'methods' => $methodList,
    ];

    if ($includeYaml) {
      $yamlDef = $this->findServiceYamlDefinition($serviceId);
      if ($yamlDef) {
        $result['yaml_definition'] = $yamlDef;
      }
    }

    return $result;
  }

  /**
   * Discover a service's API and optionally follow return types.
   */
  public function discoverApi(string $serviceId, ?string $method = NULL, int $depth = 1): array {
    $serviceInfo = $this->getServiceInfo($serviceId, $method, FALSE);
    if (isset($serviceInfo['error'])) {
      return $serviceInfo;
    }

    $result = ['service' => $serviceInfo];

    if ($method && $depth > 0) {
      $returnTypes = [];
      foreach ($serviceInfo['methods'] as $m) {
        $returnType = $m['return_type'] ?? NULL;
        if ($returnType && !$this->isScalarType($returnType)) {
          $returnTypes[$returnType] = TRUE;
        }
      }

      foreach (array_keys($returnTypes) as $returnType) {
        $classInfo = $this->getClassInfo($returnType);
        if (!isset($classInfo['error'])) {
          $result['return_types'][$returnType] = $classInfo;

          if ($depth > 1) {
            foreach ($classInfo['methods'] as $m) {
              $nestedType = $m['return_type'] ?? NULL;
              if ($nestedType && !$this->isScalarType($nestedType) && !isset($result['return_types'][$nestedType])) {
                $nestedInfo = $this->getClassInfo($nestedType);
                if (!isset($nestedInfo['error'])) {
                  $result['return_types'][$nestedType] = $nestedInfo;
                }
              }
            }
          }
        }
      }
    }

    return $result;
  }

  /**
   * Check if a method name matches any of the given glob patterns.
   */
  protected function matchesAnyPattern(string $name, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (fnmatch($pattern, $name)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check if a type name is a scalar/built-in type.
   */
  protected function isScalarType(string $type): bool {
    $type = ltrim($type, '?');
    return in_array($type, [
      'string', 'int', 'float', 'bool', 'array', 'void',
      'null', 'mixed', 'never', 'self', 'static', 'object',
      'callable', 'iterable', 'true', 'false',
    ], TRUE);
  }

  /**
   * Find a service's YAML definition from module services.yml files.
   */
  protected function findServiceYamlDefinition(string $serviceId): ?array {
    foreach ($this->moduleHandler->getModuleList() as $name => $module) {
      $path = $module->getPath() . '/' . $name . '.services.yml';
      if (!file_exists($path)) {
        continue;
      }
      try {
        $contents = file_get_contents($path);
        $parsed = Yaml::parse($contents);
      }
      catch (\Exception) {
        continue;
      }
      if (!isset($parsed['services'][$serviceId])) {
        continue;
      }
      $def = $parsed['services'][$serviceId];
      $result = [];
      if (isset($def['arguments'])) {
        $result['arguments'] = $def['arguments'];
      }
      if (isset($def['calls'])) {
        $result['calls'] = $def['calls'];
      }
      if (isset($def['tags'])) {
        $result['tags'] = $def['tags'];
      }
      return $result ?: NULL;
    }
    return NULL;
  }

}
