<?php

namespace Drupal\dkan_metastore;

use Drupal\dkan_common\DataResource;

/**
 * Stub for Drupal\dkan_metastore\ResourceMapper.
 */
class ResourceMapper {

  /**
   * Get.
   */
  public function get(
    string $identifier,
    string $perspective = DataResource::DEFAULT_SOURCE_PERSPECTIVE,
    ?string $version = NULL,
  ): ?DataResource {
    return NULL;
  }

}
