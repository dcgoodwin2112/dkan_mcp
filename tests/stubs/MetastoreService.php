<?php

namespace Drupal\metastore;

use RootedData\RootedJsonData;

/**
 * Stub for Drupal\metastore\MetastoreService.
 */
class MetastoreService {

  public function getAll(string $schema_id, ?int $start = NULL, ?int $length = NULL, $unpublished = FALSE): array {
    return [];
  }

  public function get(string $schema_id, string $identifier, bool $published = TRUE): RootedJsonData {
    return new RootedJsonData('{}');
  }

  public function count(string $schema_id, bool $unpublished = FALSE): int {
    return 0;
  }

  public function getSchemas() {
    return [];
  }

  public function getCatalog() {
    return new \stdClass();
  }

  public function post(string $schema_id, RootedJsonData $data): string {
    return '';
  }

}
