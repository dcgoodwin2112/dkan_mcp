<?php

/**
 * @file
 * Stubs for Drupal queue infrastructure.
 */

namespace Drupal\Core\Queue {

  /**
   * Stub for Drupal\Core\Queue\QueueFactory.
   */
  class QueueFactory {

    protected array $queues = [];

    public function get(string $name): QueueInterface {
      return $this->queues[$name] ?? new MemoryQueue();
    }

    /**
     * Test helper: set a queue instance for a given name.
     */
    public function setQueue(string $name, QueueInterface $queue): void {
      $this->queues[$name] = $queue;
    }

  }

  /**
   * Stub for Drupal\Core\Queue\QueueInterface.
   */
  interface QueueInterface {

    public function numberOfItems(): int;

  }

  /**
   * Stub for Drupal\Core\Queue\QueueWorkerManagerInterface.
   */
  interface QueueWorkerManagerInterface {

    public function getDefinitions(): array;

    public function getDefinition(string $plugin_id): array;

  }

  /**
   * In-memory queue implementation for tests.
   */
  class MemoryQueue implements QueueInterface {

    protected int $items;

    public function __construct(int $items = 0) {
      $this->items = $items;
    }

    public function numberOfItems(): int {
      return $this->items;
    }

  }

}

namespace Drupal\Component\Plugin\Exception {

  /**
   * Stub for Drupal\Component\Plugin\Exception\PluginNotFoundException.
   */
  class PluginNotFoundException extends \Exception {}

}
