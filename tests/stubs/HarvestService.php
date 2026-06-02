<?php

namespace Drupal\dkan_harvest;

use Drupal\dkan_harvest\Entity\HarvestRunRepository;

/**
 * Stub for Drupal\dkan_harvest\HarvestService.
 */
class HarvestService {

  /**
   * Run repository (public property on the real service).
   *
   * @var \Drupal\dkan_harvest\Entity\HarvestRunRepository
   */
  public HarvestRunRepository $runRepository;

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
