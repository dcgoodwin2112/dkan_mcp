<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\dkan_harvest\HarvestService;
use Psr\Log\LoggerInterface;

/**
 * MCP tools for DKAN harvest operations.
 */
class HarvestTools {

  public function __construct(
    protected HarvestService $harvest,
    protected LoggerInterface $logger,
  ) {}

  /**
   * List all registered harvest plan IDs.
   */
  public function listHarvestPlans(): array {
    $ids = $this->harvest->getAllHarvestIds();
    return ['plans' => $ids, 'total' => count($ids)];
  }

  /**
   * Get harvest plan configuration.
   *
   * @param string $planId
   *   Harvest plan ID.
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
   *
   * @param string $planId
   *   Harvest plan ID.
   */
  public function getHarvestRuns(string $planId): array {
    $plan = $this->harvest->getHarvestPlanObject($planId);
    if ($plan === NULL) {
      return ['error' => 'Harvest plan not found: ' . $planId];
    }
    // HarvestService has no method for listing all run results, so read them
    // from its public run repository. Each value is a JSON-encoded run result
    // keyed by run ID (newest first).
    $runs = $this->harvest->runRepository->retrieveAllRunsJson($planId);
    $decoded = [];
    foreach ($runs as $runId => $run) {
      $item = is_string($run) ? json_decode($run, TRUE) : $run;
      if (!is_array($item)) {
        continue;
      }
      // Strip the embedded plan config to reduce token waste.
      unset($item['plan']);
      $item['run_id'] = (string) $runId;
      $decoded[] = $item;
    }
    return ['runs' => $decoded, 'total' => count($decoded)];
  }

  /**
   * Get detailed result for a harvest run.
   *
   * @param string $planId
   *   Harvest plan ID.
   * @param string|null $runId
   *   Run ID/timestamp. Omit for the latest run.
   */
  public function getHarvestRunResult(string $planId, ?string $runId = NULL): array {
    $result = $this->harvest->getHarvestRunResult($planId, $runId);
    if (empty($result)) {
      $msg = 'No run result found for plan: ' . $planId;
      if ($runId !== NULL) {
        $msg .= ', run: ' . $runId;
      }
      return ['error' => $msg];
    }
    unset($result['plan']);
    return ['result' => $result];
  }

  /**
   * Register a new harvest plan.
   *
   * @param string $plan
   *   Harvest plan as a JSON string with identifier, extract, and load
   *   properties.
   */
  public function registerHarvest(string $plan): array {
    $decoded = json_decode($plan);
    if (!is_object($decoded)) {
      $message = json_last_error() !== JSON_ERROR_NONE
        ? 'Invalid JSON: ' . json_last_error_msg()
        : 'Plan must be a JSON object.';
      return ['error' => $message];
    }

    try {
      $this->harvest->registerHarvest($decoded);
      $planId = $decoded->identifier ?? 'unknown';
      $this->logger->notice('MCP: Harvest plan @id registered.', ['@id' => $planId]);
      return [
        'status' => 'success',
        'plan_id' => $planId,
        'message' => 'Harvest plan registered.',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to register harvest: @error', ['@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Execute a harvest run.
   *
   * @param string $planId
   *   Harvest plan ID.
   */
  public function runHarvest(string $planId): array {
    if ($this->harvest->getHarvestPlanObject($planId) === NULL) {
      return [
        'status' => 'not_found',
        'plan_id' => $planId,
        'message' => 'Harvest plan not found: ' . $planId,
      ];
    }

    try {
      $result = $this->harvest->runHarvest($planId);
      $this->logger->notice('MCP: Harvest plan @id executed.', ['@id' => $planId]);
      return [
        'status' => 'success',
        'plan_id' => $planId,
        'result' => $result,
        'message' => 'Harvest run completed.',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to run harvest @id: @error', ['@id' => $planId, '@error' => $e->getMessage()]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Remove a harvest plan.
   *
   * @param string $planId
   *   Harvest plan ID.
   */
  public function deregisterHarvest(string $planId): array {
    if ($this->harvest->getHarvestPlanObject($planId) === NULL) {
      return [
        'status' => 'not_found',
        'plan_id' => $planId,
        'message' => 'Harvest plan not found: ' . $planId,
      ];
    }

    try {
      $this->harvest->deregisterHarvest($planId);
      $this->logger->notice('MCP: Harvest plan @id deregistered.', ['@id' => $planId]);
      return [
        'status' => 'success',
        'plan_id' => $planId,
        'message' => 'Harvest plan deregistered.',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to deregister harvest @id: @error', [
        '@id' => $planId,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

}
