<?php

/**
 * @file
 * Stubs for Drupal queue infrastructure.
 *
 * These doubles are interdependent (MemoryQueue implements QueueInterface) and
 * are loaded together via a require glob with no autoloader, so they must live
 * in a single file. One-class-per-file does not apply here.
 */

// phpcs:disable Drupal.Classes.ClassFileName.NoMatch

namespace Drupal\Core\Queue {

  /**
   * Stub for Drupal\Core\Queue\QueueFactory.
   */
  class QueueFactory {

    /**
     * Queues keyed by name.
     *
     * @var array
     */
    protected array $queues = [];

    /**
     * Get.
     */
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

    /**
     * Number of items.
     */
    public function numberOfItems(): int;

  }

  /**
   * Stub for Drupal\Core\Queue\QueueWorkerManagerInterface.
   */
  interface QueueWorkerManagerInterface {

    /**
     * Get definitions.
     */
    public function getDefinitions(): array;

    /**
     * Get definition.
     */
    public function getDefinition(string $plugin_id): array;

  }

  /**
   * In-memory queue implementation for tests.
   */
  class MemoryQueue implements QueueInterface {

    /**
     * Number of items in the queue.
     *
     * @var int
     */
    protected int $items;

    public function __construct(int $items = 0) {
      $this->items = $items;
    }

    /**
     * Number of items.
     */
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
