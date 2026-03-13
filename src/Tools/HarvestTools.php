<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\harvest\HarvestService;

/**
 * MCP tools for DKAN harvest operations.
 */
class HarvestTools {

  public function __construct(
    protected HarvestService $harvest,
  ) {}

  /**
   * List all registered harvest plan IDs.
   */
  public function listHarvestPlans(): array {
    $ids = $this->harvest->getAllHarvestIds();
    return ['plan_ids' => $ids, 'total' => count($ids)];
  }

  /**
   * Get harvest plan configuration.
   */
  public function getHarvestPlan(string $planId): array {
    $plan = $this->harvest->getHarvestPlanObject($planId);
    if ($plan === NULL) {
      return ['error' => 'Harvest plan not found: ' . $planId];
    }
    return ['plan' => json_decode(json_encode($plan), TRUE)];
  }

  /**
   * List all runs for a harvest plan.
   */
  public function getHarvestRuns(string $planId): array {
    $runs = $this->harvest->getAllHarvestRunInfo($planId);
    $decoded = array_map(fn($run) => is_string($run) ? json_decode($run, TRUE) : $run, $runs);
    return ['runs' => $decoded, 'total' => count($decoded)];
  }

  /**
   * Get detailed result for a harvest run.
   */
  public function getHarvestRunResult(string $planId, ?string $runId = NULL): array {
    $result = $this->harvest->getHarvestRunResult($planId, $runId);
    if (empty($result)) {
      return ['error' => 'No run result found for plan: ' . $planId];
    }
    return ['result' => $result];
  }

}
