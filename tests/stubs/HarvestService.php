<?php

namespace Drupal\harvest;

/**
 * Stub for Drupal\harvest\HarvestService.
 */
class HarvestService {

  public function getAllHarvestIds(bool $has_run_record = FALSE): array {
    return [];
  }

  public function getHarvestPlanObject(string $plan_id): ?object {
    return NULL;
  }

  public function getAllHarvestRunInfo(string $plan_id): array {
    return [];
  }

  public function getHarvestRunResult(string $plan_id, ?string $timestamp = NULL): array {
    return [];
  }

  public function getRunIdsForHarvest(string $plan_id): array {
    return [];
  }

  public function registerHarvest(object $plan): string {
    return $plan->identifier ?? '';
  }

  public function runHarvest(string $plan_id): array {
    return [];
  }

  public function deregisterHarvest(string $plan_id): bool {
    return TRUE;
  }

}
