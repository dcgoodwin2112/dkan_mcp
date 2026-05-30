<?php

namespace Drupal\dkan_harvest;

/**
 * Stub for Drupal\dkan_harvest\HarvestService.
 */
class HarvestService {

  /**
   * Get all harvest ids.
   */
  public function getAllHarvestIds(bool $has_run_record = FALSE): array {
    return [];
  }

  /**
   * Get harvest plan object.
   */
  public function getHarvestPlanObject(string $plan_id): ?object {
    return NULL;
  }

  /**
   * Get all harvest run info.
   */
  public function getAllHarvestRunInfo(string $plan_id): array {
    return [];
  }

  /**
   * Get harvest run result.
   */
  public function getHarvestRunResult(string $plan_id, ?string $timestamp = NULL): array {
    return [];
  }

  /**
   * Get run ids for harvest.
   */
  public function getRunIdsForHarvest(string $plan_id): array {
    return [];
  }

  /**
   * Register harvest.
   */
  public function registerHarvest(object $plan): string {
    return $plan->identifier ?? '';
  }

  /**
   * Run harvest.
   */
  public function runHarvest(string $plan_id): array {
    return [];
  }

  /**
   * Deregister harvest.
   */
  public function deregisterHarvest(string $plan_id): bool {
    return TRUE;
  }

}
