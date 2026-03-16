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
  public function listServices(?string $module = NULL): array {
    $prefix = $module ? "dkan.{$module}." : 'dkan.';
    $ids = array_filter(
      $this->container->getServiceIds(),
      fn($id) => str_starts_with($id, $prefix)
    );
    sort($ids);

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
  public function getClassInfo(string $className): array {
    if (!class_exists($className) && !interface_exists($className)) {
      return ['error' => "Class or interface not found: {$className}"];
    }

    $reflection = new \ReflectionClass($className);

    $methods = [];
    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      if (str_starts_with($method->getName(), '_')) {
        continue;
      }
      $params = array_map(fn(\ReflectionParameter $p) => array_filter([
        'name' => $p->getName(),
        'type' => $p->getType() ? (string) $p->getType() : NULL,
        'optional' => $p->isOptional() ?: NULL,
      ], fn($v) => $v !== NULL), $method->getParameters());
      $methods[] = [
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
      'methods' => $methods,
    ];
  }

  /**
   * Get detailed service info via reflection.
   */
  public function getServiceInfo(string $serviceId): array {
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

    $methods = [];
    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      if (str_starts_with($method->getName(), '_')) {
        continue;
      }
      if ($method->getDeclaringClass()->getName() !== $class) {
        continue;
      }
      $params = array_map(fn(\ReflectionParameter $p) => array_filter([
        'name' => $p->getName(),
        'type' => $p->getType() ? (string) $p->getType() : NULL,
        'optional' => $p->isOptional() ?: NULL,
      ], fn($v) => $v !== NULL), $method->getParameters());
      $methods[] = [
        'name' => $method->getName(),
        'params' => array_values($params),
        'return_type' => $method->getReturnType() ? (string) $method->getReturnType() : NULL,
      ];
    }

    $result = [
      'service_id' => $serviceId,
      'class' => $class,
      'constructor_params' => $constructorParams,
      'methods' => $methods,
    ];

    $yamlDef = $this->findServiceYamlDefinition($serviceId);
    if ($yamlDef) {
      $result['yaml_definition'] = $yamlDef;
    }

    return $result;
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
