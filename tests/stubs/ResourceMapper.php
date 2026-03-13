<?php

namespace Drupal\metastore;

use Drupal\common\DataResource;

/**
 * Stub for Drupal\metastore\ResourceMapper.
 */
class ResourceMapper {

  public function get(
    string $identifier,
    string $perspective = DataResource::DEFAULT_SOURCE_PERSPECTIVE,
    ?string $version = NULL,
  ): ?DataResource {
    return NULL;
  }

}
