<?php

namespace Drupal\dkan_harvest\Entity;

/**
 * Stub for Drupal\dkan_harvest\Entity\HarvestRunRepository.
 */
class HarvestRunRepository {

  /**
   * Retrieve all run results for a given plan.
   *
   * @return array
   *   JSON-encoded result arrays, keyed by harvest run identifier.
   */
  public function retrieveAllRunsJson(string $plan_id): array {
    return [];
  }

}
