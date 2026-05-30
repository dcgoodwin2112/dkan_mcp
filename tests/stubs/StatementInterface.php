<?php

namespace Drupal\Core\Database;

/**
 * Stub for Drupal\Core\Database\StatementInterface.
 */
interface StatementInterface extends \IteratorAggregate {

  /**
   * Fetch field.
   */
  public function fetchField(int $index = 0): mixed;

  /**
   * Fetch assoc.
   */
  public function fetchAssoc(): array|false;

  /**
   * Get iterator.
   */
  public function getIterator(): \ArrayIterator;

}
