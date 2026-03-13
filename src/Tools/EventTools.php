<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * MCP tools for DKAN event introspection.
 */
class EventTools {

  /**
   * Classes with EVENT_ constants not registered as dkan.* services.
   */
  protected const SUPPLEMENTARY_CLASSES = [
    'Drupal\datastore\Service\ImportService',
    'Drupal\metastore\Plugin\QueueWorker\OrphanReferenceProcessor',
    'Drupal\common\Storage\AbstractDatabaseTable',
    'Drupal\datastore\SqlEndpoint\WebServiceApi',
  ];

  public function __construct(
    protected ContainerInterface $container,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * List DKAN event constants with string values and declaring classes.
   */
  public function listEvents(?string $module = NULL): array {
    $events = $this->discoverEvents($module);
    return ['events' => $events, 'total' => count($events)];
  }

  /**
   * Get event details including registered subscribers.
   */
  public function getEventInfo(string $eventName): array {
    $events = $this->discoverEvents();
    $match = NULL;
    foreach ($events as $event) {
      if ($event['event_name'] === $eventName) {
        $match = $event;
        break;
      }
    }

    if (!$match) {
      return ['error' => "Event not found: {$eventName}"];
    }

    $listeners = $this->eventDispatcher->getListeners($eventName);
    $subscribers = [];
    foreach ($listeners as $listener) {
      if (is_array($listener) && count($listener) === 2) {
        if (is_object($listener[0])) {
          $subscribers[] = [
            'class' => get_class($listener[0]),
            'method' => $listener[1],
          ];
        }
        elseif (is_string($listener[0])) {
          $subscribers[] = [
            'class' => $listener[0],
            'method' => $listener[1],
          ];
        }
      }
      elseif ($listener instanceof \Closure) {
        $ref = new \ReflectionFunction($listener);
        $subscribers[] = [
          'class' => $ref->getClosureScopeClass()?->getName() ?? 'Closure',
          'method' => '{closure}',
        ];
      }
    }

    $match['subscribers'] = $subscribers;
    return $match;
  }

  /**
   * Discover all DKAN EVENT_ constants via reflection.
   */
  protected function discoverEvents(?string $module = NULL): array {
    $seen = [];
    $events = [];

    // Scan dkan.* services.
    $ids = array_filter(
      $this->container->getServiceIds(),
      fn($id) => str_starts_with($id, 'dkan.')
    );
    foreach ($ids as $id) {
      try {
        $service = $this->container->get($id);
        $className = get_class($service);
        if (!isset($seen[$className])) {
          $seen[$className] = TRUE;
          $events = array_merge($events, $this->collectEventConstants($className));
        }
      }
      catch (\Exception) {
        // Skip services that can't be instantiated.
      }
    }

    // Scan supplementary classes not in the container.
    foreach (static::SUPPLEMENTARY_CLASSES as $className) {
      if (!isset($seen[$className]) && class_exists($className, FALSE)) {
        $seen[$className] = TRUE;
        $events = array_merge($events, $this->collectEventConstants($className));
      }
    }

    // Deduplicate by event_name (string value).
    $unique = [];
    foreach ($events as $event) {
      if (!isset($unique[$event['event_name']])) {
        $unique[$event['event_name']] = $event;
      }
    }
    $events = array_values($unique);

    // Filter by module.
    if ($module) {
      $events = array_values(array_filter(
        $events,
        fn($e) => $e['module'] === $module
      ));
    }

    // Sort by event_name.
    usort($events, fn($a, $b) => strcmp($a['event_name'], $b['event_name']));

    return $events;
  }

  /**
   * Collect EVENT_ constants from a class via reflection.
   */
  protected function collectEventConstants(string $className): array {
    try {
      $reflection = new \ReflectionClass($className);
    }
    catch (\ReflectionException) {
      return [];
    }

    $events = [];
    foreach ($reflection->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
      $name = $constant->getName();
      if (!str_starts_with($name, 'EVENT_')) {
        continue;
      }
      // Only include constants declared in this class, not inherited.
      if ($constant->getDeclaringClass()->getName() !== $className) {
        continue;
      }
      $value = $constant->getValue();
      if (!is_string($value)) {
        continue;
      }
      $events[] = [
        'constant' => $name,
        'event_name' => $value,
        'declaring_class' => $className,
        'module' => $this->extractModule($className),
      ];
    }

    return $events;
  }

  /**
   * Extract Drupal module name from a FQCN.
   */
  protected function extractModule(string $className): ?string {
    if (preg_match('/^Drupal\\\\([^\\\\]+)\\\\/', $className, $matches)) {
      return $matches[1];
    }
    return NULL;
  }

}
