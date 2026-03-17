<?php

namespace Drupal\dkan_mcp\Tools;

use Composer\InstalledVersions;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\common\DatasetInfo;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\harvest\HarvestService;
use Drupal\metastore\MetastoreService;

/**
 * MCP tools for DKAN site health and status overview.
 */
class StatusTools {

  /**
   * Key DKAN modules to check.
   */
  protected const DKAN_MODULES = [
    'metastore',
    'datastore',
    'harvest',
    'common',
    'metastore_search',
  ];

  /**
   * DKAN modules that provide queue workers.
   */
  protected const DKAN_QUEUE_MODULES = [
    'datastore',
    'metastore',
    'common',
    'harvest',
  ];

  /**
   * Max datasets to iterate for distribution/import stats.
   */
  protected const MAX_DATASETS = 100;

  public function __construct(
    protected MetastoreService $metastore,
    protected DatasetInfo $datasetInfo,
    protected HarvestService $harvest,
    protected ModuleHandlerInterface $moduleHandler,
    protected ModuleExtensionList $moduleList,
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $queueWorkerManager,
  ) {}

  /**
   * Get a high-level overview of the DKAN site.
   */
  public function getSiteStatus(): array {
    try {
      return $this->buildStatus();
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get queue item counts for DKAN-related queues.
   */
  public function getQueueStatus(?string $queueName = NULL): array {
    try {
      if ($queueName !== NULL) {
        return $this->buildSingleQueueStatus($queueName);
      }
      return $this->buildAllQueueStatus();
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Build status for a single named queue.
   */
  protected function buildSingleQueueStatus(string $queueName): array {
    try {
      $definition = $this->queueWorkerManager->getDefinition($queueName);
    }
    catch (PluginNotFoundException $e) {
      return ['error' => "Queue worker '$queueName' not found."];
    }

    $queue = $this->queueFactory->get($queueName);

    return [
      'queues' => [$this->formatQueueInfo($queueName, $definition, $queue->numberOfItems())],
    ];
  }

  /**
   * Build status for all DKAN queue workers.
   */
  protected function buildAllQueueStatus(): array {
    $definitions = $this->queueWorkerManager->getDefinitions();
    $queues = [];

    foreach ($definitions as $name => $definition) {
      $provider = $definition['provider'] ?? '';
      if (!in_array($provider, self::DKAN_QUEUE_MODULES, TRUE)) {
        continue;
      }
      $queue = $this->queueFactory->get($name);
      $queues[] = $this->formatQueueInfo($name, $definition, $queue->numberOfItems());
    }

    return ['queues' => $queues];
  }

  /**
   * Format a single queue's info array.
   */
  protected function formatQueueInfo(string $name, array $definition, int $items): array {
    $info = [
      'name' => $name,
      'items' => $items,
      'title' => (string) ($definition['title'] ?? $name),
    ];
    if (isset($definition['cron']['time'])) {
      $info['cron_time'] = $definition['cron']['time'];
    }
    if (isset($definition['cron']['lease_time'])) {
      $info['lease_time'] = $definition['cron']['lease_time'];
    }
    return $info;
  }

  /**
   * Build the full status array.
   */
  protected function buildStatus(): array {
    $datasetCount = $this->metastore->count('dataset');
    $sampled = $datasetCount > self::MAX_DATASETS;
    $datasets = $this->metastore->getAll('dataset', 0, self::MAX_DATASETS);
    $retrievedCount = count($datasets);

    $byFormat = [];
    $distTotal = 0;
    $imports = ['done' => 0, 'pending' => 0, 'error' => 0];

    foreach ($datasets as $dataset) {
      $decoded = json_decode((string) $dataset, TRUE);
      $distributions = $decoded['distribution'] ?? [];
      foreach ($distributions as $dist) {
        $distTotal++;
        $format = $this->extractFormat($dist);
        if ($format) {
          $byFormat[$format] = ($byFormat[$format] ?? 0) + 1;
        }
      }

      // Gather import status via DatasetInfo.
      $identifier = $decoded['identifier'] ?? NULL;
      if ($identifier) {
        $this->collectImportStatus($identifier, $imports);
      }
    }

    ksort($byFormat);

    $harvestIds = $this->harvest->getAllHarvestIds();

    $dkanModules = [];
    foreach (self::DKAN_MODULES as $module) {
      $dkanModules[$module] = $this->moduleHandler->moduleExists($module) ? 'enabled' : 'not_enabled';
    }

    $dkanVersion = $this->getDkanVersion();
    $drupalVersion = $this->getDrupalVersion();

    $datasetStatus = ['total' => $datasetCount];
    if ($retrievedCount < $datasetCount && !$sampled) {
      $datasetStatus['retrievable'] = $retrievedCount;
      $datasetStatus['invalid'] = $datasetCount - $retrievedCount;
    }

    $status = [
      'datasets' => $datasetStatus,
      'distributions' => [
        'total' => $distTotal,
        'by_format' => $byFormat,
      ],
      'imports' => $imports,
      'harvest' => ['plans' => count($harvestIds)],
      'dkan' => [
        'version' => $dkanVersion,
        'modules' => $dkanModules,
      ],
      'drupal' => ['version' => $drupalVersion],
    ];

    if ($sampled) {
      $status['sampled'] = TRUE;
      $status['sample_size'] = self::MAX_DATASETS;
    }

    return $status;
  }

  /**
   * Extract format string from a distribution object.
   */
  protected function extractFormat(array $dist): ?string {
    // Try mediaType first (e.g., "text/csv"), then format field.
    if (!empty($dist['mediaType'])) {
      $mediaType = strtolower($dist['mediaType']);
      // Extract subtype: "text/csv" → "csv", "application/zip" → "zip".
      $parts = explode('/', $mediaType);
      return end($parts);
    }
    if (!empty($dist['format'])) {
      return strtolower($dist['format']);
    }
    return NULL;
  }

  /**
   * Collect import status counts from DatasetInfo.
   */
  protected function collectImportStatus(string $uuid, array &$imports): void {
    try {
      $info = $this->datasetInfo->gather($uuid);
      $distributions = $info['latest_revision']['distributions'] ?? [];
      foreach ($distributions as $distInfo) {
        $status = $distInfo['importer_status'] ?? NULL;
        if ($status === 'done') {
          $imports['done']++;
        }
        elseif ($status === 'error') {
          $imports['error']++;
        }
        else {
          $imports['pending']++;
        }
      }
    }
    catch (\Exception) {
      // Skip datasets that can't be gathered.
    }
  }

  /**
   * Get DKAN module version.
   */
  protected function getDkanVersion(): string {
    // Module info.yml rarely has version set for Composer-installed modules.
    // Fall back to Composer's installed version data.
    try {
      $allInfo = $this->moduleList->getAllInstalledInfo();
      if (!empty($allInfo['dkan']['version'])) {
        return $allInfo['dkan']['version'];
      }
    }
    catch (\Exception) {
      // Continue to Composer fallback.
    }
    try {
      if (class_exists(InstalledVersions::class)) {
        $version = InstalledVersions::getPrettyVersion('getdkan/dkan');
        if ($version) {
          return $version;
        }
      }
    }
    catch (\Exception) {
      // Package not installed via Composer.
    }
    return 'unknown';
  }

  /**
   * Get Drupal core version.
   */
  protected function getDrupalVersion(): string {
    if (defined('\Drupal::VERSION')) {
      return \Drupal::VERSION;
    }
    try {
      $allInfo = $this->moduleList->getAllInstalledInfo();
      return $allInfo['system']['version'] ?? 'unknown';
    }
    catch (\Exception) {
      return 'unknown';
    }
  }

}
