<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\datastore\DatastoreService;
use Drupal\metastore\MetastoreService;
use RootedData\RootedJsonData;

/**
 * MCP tools for write operations (cache, modules, datasets, imports).
 */
class WriteTools {

  public function __construct(
    protected ModuleInstallerInterface $moduleInstaller,
    protected ModuleHandlerInterface $moduleHandler,
    protected MetastoreService $metastoreService,
    protected DatastoreService $datastoreService,
  ) {}

  /**
   * Flush all Drupal caches.
   */
  public function clearCache(): array {
    try {
      foreach (Cache::getBins() as $bin) {
        $bin->deleteAll();
      }
      return [
        'status' => 'success',
        'message' => 'All cache bins cleared. For a full container rebuild (services.yml changes), restart the MCP server.',
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Enable a Drupal module.
   */
  public function enableModule(string $moduleName): array {
    try {
      if ($this->moduleHandler->moduleExists($moduleName)) {
        return [
          'status' => 'already_enabled',
          'message' => "Module '{$moduleName}' is already enabled.",
        ];
      }

      $this->moduleInstaller->install([$moduleName]);
      return [
        'status' => 'success',
        'message' => "Module '{$moduleName}' enabled. Restart the MCP server if the module registers new services or routes.",
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Uninstall a Drupal module.
   */
  public function disableModule(string $moduleName): array {
    try {
      if (!$this->moduleHandler->moduleExists($moduleName)) {
        return [
          'status' => 'not_enabled',
          'message' => "Module '{$moduleName}' is not enabled.",
        ];
      }

      $this->moduleInstaller->uninstall([$moduleName]);
      return [
        'status' => 'success',
        'message' => "Module '{$moduleName}' uninstalled. Restart the MCP server if the module had registered services or routes.",
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Create a minimal test dataset with one distribution.
   */
  public function createTestDataset(string $title, string $downloadUrl): array {
    try {
      $uuid = (new UuidGenerator())->generate();
      $dataset = (object) [
        'identifier' => $uuid,
        'title' => $title,
        'description' => "Test dataset: {$title}",
        'accessLevel' => 'public',
        'distribution' => [
          (object) [
            'downloadURL' => $downloadUrl,
            'mediaType' => 'text/csv',
            'title' => "{$title} distribution",
          ],
        ],
        '@type' => 'dcat:Dataset',
      ];

      $identifier = $this->metastoreService->post('dataset', new RootedJsonData(json_encode($dataset)));
      $this->metastoreService->publish('dataset', $identifier);

      return [
        'status' => 'success',
        'identifier' => $identifier,
        'message' => "Dataset created and published. Distribution references may need cron to fully resolve. Use get_dataset_info to check status, then list_distributions to get resource_id.",
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Trigger datastore import for a resource.
   */
  public function importResource(string $resourceId, bool $deferred = FALSE): array {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);

      $result = $this->datastoreService->import($identifier, $deferred, $version);

      $hasError = FALSE;
      $errors = [];
      foreach ($result as $key => $resultObj) {
        if (is_object($resultObj) && method_exists($resultObj, 'getStatus')
            && $resultObj->getStatus() === 'error') {
          $hasError = TRUE;
          $errors[] = $key . ': ' . $resultObj->getError();
        }
      }

      return [
        'status' => $hasError ? 'error' : 'success',
        'resource_id' => $resourceId,
        'import_result' => $result,
        'errors' => $errors ?: NULL,
        'message' => $deferred
          ? 'Import queued. Use get_import_status to check progress.'
          : ($hasError ? 'Import completed with errors.' : 'Import completed. Use get_import_status to verify.'),
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Parse a resource_id into [identifier, version].
   *
   * @return array{string, string|null}
   *   The identifier and version.
   */
  protected function parseResourceId(string $resourceId): array {
    if (str_contains($resourceId, '__')) {
      $parts = explode('__', $resourceId, 2);
      return [$parts[0], $parts[1]];
    }
    return [$resourceId, NULL];
  }

}
