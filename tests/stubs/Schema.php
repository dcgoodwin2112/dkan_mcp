<?php

namespace Drupal\Core\Database;

/**
 * Stub for Drupal\Core\Database\Schema.
 */
class Schema {

  /**
   * Table exists.
   */
  public function tableExists(string $table): bool {
    return TRUE;
  }

}
