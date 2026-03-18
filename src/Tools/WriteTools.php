<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\datastore\DatastoreService;
use Drupal\metastore\Exception\CannotChangeUuidException;
use Drupal\metastore\Exception\MissingObjectException;
use Drupal\metastore\Exception\UnmodifiedObjectException;
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
   * Full replacement of dataset metadata (PUT semantics).
   */
  public function updateDataset(string $identifier, string $metadata): array {
    if (!is_object(json_decode($metadata))) {
      $message = json_last_error() !== JSON_ERROR_NONE
        ? 'Invalid JSON: ' . json_last_error_msg()
        : 'Metadata must be a JSON object, not a scalar or array.';
      return ['error' => $message];
    }

    try {
      $result = $this->metastoreService->put('dataset', $identifier, new RootedJsonData($metadata));
      return [
        'status' => 'success',
        'identifier' => $result['identifier'],
        'new' => $result['new'] ?? FALSE,
      ];
    }
    catch (CannotChangeUuidException $e) {
      return ['error' => $e->getMessage()];
    }
    catch (UnmodifiedObjectException $e) {
      return [
        'status' => 'unmodified',
        'identifier' => $identifier,
        'message' => 'No changes detected in the provided metadata.',
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Partial update via JSON Merge Patch (RFC 7396).
   */
  public function patchDataset(string $identifier, string $metadata): array {
    if (!is_object(json_decode($metadata))) {
      $message = json_last_error() !== JSON_ERROR_NONE
        ? 'Invalid JSON: ' . json_last_error_msg()
        : 'Metadata must be a JSON object, not a scalar or array.';
      return ['error' => $message];
    }

    try {
      $this->metastoreService->patch('dataset', $identifier, $metadata);
      return [
        'status' => 'success',
        'identifier' => $identifier,
        'message' => 'Dataset patched successfully.',
      ];
    }
    catch (MissingObjectException $e) {
      return [
        'status' => 'not_found',
        'identifier' => $identifier,
        'message' => "Dataset '{$identifier}' not found.",
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Remove a dataset and cascade-delete distributions and datastore tables.
   */
  public function deleteDataset(string $identifier): array {
    try {
      $this->metastoreService->delete('dataset', $identifier);
      return [
        'status' => 'success',
        'identifier' => $identifier,
        'message' => 'Dataset deleted. Associated distributions and datastore tables have been cascade-deleted.',
      ];
    }
    catch (MissingObjectException $e) {
      return [
        'status' => 'not_found',
        'identifier' => $identifier,
        'message' => "Dataset '{$identifier}' not found.",
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Publish a dataset to make it publicly visible.
   */
  public function publishDataset(string $identifier): array {
    try {
      $this->metastoreService->publish('dataset', $identifier);
      return [
        'status' => 'success',
        'identifier' => $identifier,
        'message' => 'Dataset published.',
      ];
    }
    catch (MissingObjectException $e) {
      return [
        'status' => 'not_found',
        'identifier' => $identifier,
        'message' => "Dataset '{$identifier}' not found.",
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Archive (unpublish) a dataset.
   */
  public function unpublishDataset(string $identifier): array {
    try {
      $this->metastoreService->archive('dataset', $identifier);
      return [
        'status' => 'success',
        'identifier' => $identifier,
        'message' => 'Dataset unpublished (archived).',
      ];
    }
    catch (MissingObjectException $e) {
      return [
        'status' => 'not_found',
        'identifier' => $identifier,
        'message' => "Dataset '{$identifier}' not found.",
      ];
    }
    catch (\Throwable $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Drop a datastore table for a resource.
   */
  public function dropDatastore(string $resourceId): array {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $this->datastoreService->drop($identifier, $version);
      return [
        'status' => 'success',
        'resource_id' => $resourceId,
        'message' => 'Datastore table dropped.',
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
