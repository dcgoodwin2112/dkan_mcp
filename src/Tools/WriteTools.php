<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\dkan_datastore\DatastoreService;
use Drupal\dkan_metastore\Exception\CannotChangeUuidException;
use Drupal\dkan_metastore\Exception\ExistingObjectException;
use Drupal\dkan_metastore\Exception\MissingObjectException;
use Drupal\dkan_metastore\Exception\UnmodifiedObjectException;
use Drupal\dkan_metastore\MetastoreService;
use Psr\Log\LoggerInterface;
use RootedData\RootedJsonData;

/**
 * MCP tools for DKAN data write operations (datasets, metastore, imports).
 */
class WriteTools {

  public function __construct(
    protected MetastoreService $metastoreService,
    protected DatastoreService $datastoreService,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Trigger datastore import for a resource.
   *
   * @param string $resourceId
   *   Resource ID in identifier__version format (from list_distributions).
   * @param bool $deferred
   *   Queue for background processing instead of running inline.
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
      $this->logger->error('MCP: Import failed for resource @id: @error', [
        '@id' => $resourceId,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Full replacement of dataset metadata (PUT semantics).
   *
   * @param string $identifier
   *   Dataset UUID.
   * @param string $metadata
   *   Complete dataset metadata as a JSON string.
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
      $this->logger->error('MCP: Failed to update dataset @id: @error', [
        '@id' => $identifier,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Partial update via JSON Merge Patch (RFC 7396).
   *
   * @param string $identifier
   *   Dataset UUID.
   * @param string $metadata
   *   JSON object with only the fields to change.
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
      $this->logger->error('MCP: Failed to patch dataset @id: @error', [
        '@id' => $identifier,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Remove a dataset and cascade-delete distributions and datastore tables.
   *
   * @param string $identifier
   *   Dataset UUID.
   */
  public function deleteDataset(string $identifier): array {
    try {
      $this->metastoreService->delete('dataset', $identifier);
      $this->logger->notice('MCP: Dataset @id deleted.', ['@id' => $identifier]);
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
      $this->logger->error('MCP: Failed to delete dataset @id: @error', [
        '@id' => $identifier,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Publish a dataset to make it publicly visible.
   *
   * @param string $identifier
   *   Dataset UUID.
   */
  public function publishDataset(string $identifier): array {
    try {
      $this->metastoreService->publish('dataset', $identifier);
      $this->logger->notice('MCP: Dataset @id published.', ['@id' => $identifier]);
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
      $this->logger->error('MCP: Failed to publish dataset @id: @error', [
        '@id' => $identifier,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Archive (unpublish) a dataset.
   *
   * @param string $identifier
   *   Dataset UUID.
   */
  public function unpublishDataset(string $identifier): array {
    try {
      $this->metastoreService->archive('dataset', $identifier);
      $this->logger->notice('MCP: Dataset @id unpublished.', ['@id' => $identifier]);
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
      $this->logger->error('MCP: Failed to unpublish dataset @id: @error', [
        '@id' => $identifier,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Create a metastore item under any schema (dataset, data-dictionary, etc.).
   *
   * @param string $schemaId
   *   Metastore schema ID (e.g. data-dictionary, distribution, theme, keyword).
   * @param string $metadata
   *   Complete item metadata as a JSON object string, including identifier and
   *   the schema's required fields.
   */
  public function postMetastoreItem(string $schemaId, string $metadata): array {
    if (!is_object(json_decode($metadata))) {
      $message = json_last_error() !== JSON_ERROR_NONE
        ? 'Invalid JSON: ' . json_last_error_msg()
        : 'Metadata must be a JSON object, not a scalar or array.';
      return ['error' => $message];
    }

    try {
      $identifier = $this->metastoreService->post($schemaId, new RootedJsonData($metadata));
      $this->logger->notice('MCP: Metastore item @schema/@id created.', [
        '@schema' => $schemaId,
        '@id' => $identifier,
      ]);
      return [
        'status' => 'success',
        'schema_id' => $schemaId,
        'identifier' => $identifier,
        'message' => "Created {$schemaId} item with identifier {$identifier}.",
      ];
    }
    catch (ExistingObjectException $e) {
      return [
        'status' => 'already_exists',
        'schema_id' => $schemaId,
        'message' => $e->getMessage(),
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to post @schema item: @error', [
        '@schema' => $schemaId,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Partial update of any metastore item via JSON Merge Patch (RFC 7396).
   *
   * @param string $schemaId
   *   Metastore schema ID (e.g. data-dictionary, distribution, theme, keyword).
   * @param string $identifier
   *   Item identifier (UUID).
   * @param string $metadata
   *   JSON object string with only the fields to change.
   */
  public function patchMetastoreItem(string $schemaId, string $identifier, string $metadata): array {
    if (!is_object(json_decode($metadata))) {
      $message = json_last_error() !== JSON_ERROR_NONE
        ? 'Invalid JSON: ' . json_last_error_msg()
        : 'Metadata must be a JSON object, not a scalar or array.';
      return ['error' => $message];
    }

    try {
      $this->metastoreService->patch($schemaId, $identifier, $metadata);
      return [
        'status' => 'success',
        'schema_id' => $schemaId,
        'identifier' => $identifier,
        'message' => "Patched {$schemaId} item {$identifier}.",
      ];
    }
    catch (MissingObjectException $e) {
      return [
        'status' => 'not_found',
        'schema_id' => $schemaId,
        'identifier' => $identifier,
        'message' => "{$schemaId} item '{$identifier}' not found.",
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to patch @schema/@id: @error', [
        '@schema' => $schemaId,
        '@id' => $identifier,
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Drop a datastore table for a resource.
   *
   * @param string $resourceId
   *   Resource ID in identifier__version format (from list_distributions).
   */
  public function dropDatastore(string $resourceId): array {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $this->datastoreService->drop($identifier, $version);
      $this->logger->notice('MCP: Datastore dropped for resource @id.', ['@id' => $resourceId]);
      return [
        'status' => 'success',
        'resource_id' => $resourceId,
        'message' => 'Datastore table dropped.',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('MCP: Failed to drop datastore for resource @id: @error', [
        '@id' => $resourceId,
        '@error' => $e->getMessage(),
      ]);
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
