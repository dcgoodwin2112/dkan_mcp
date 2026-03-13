<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\Component\DependencyInjection\ContainerInterface;

/**
 * MCP tools for DKAN service introspection.
 */
class ServiceTools {

  public function __construct(
    protected ContainerInterface $container,
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

    return [
      'service_id' => $serviceId,
      'class' => $class,
      'constructor_params' => $constructorParams,
      'methods' => $methods,
    ];
  }

}
