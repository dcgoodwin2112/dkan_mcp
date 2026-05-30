<?php

namespace Drupal\dkan_common\Storage;

/**
 * Stub for Drupal\dkan_common\Storage\DatabaseTableInterface.
 */
interface DatabaseTableInterface {

  /**
   * Get schema.
   */
  public function getSchema(): array;

  /**
   * Get table name.
   */
  public function getTableName(): string;

}
