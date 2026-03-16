<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\datastore\DatastoreService;
use Drupal\metastore\MetastoreService;
use Drupal\metastore\ResourceMapper;

/**
 * MCP tools for DKAN resource reference introspection.
 */
class ResourceTools {

  /**
   * Known resource perspectives.
   */
  protected const PERSPECTIVES = ['source', 'local_file', 'local_url'];

  public function __construct(
    protected MetastoreService $metastore,
    protected ResourceMapper $resourceMapper,
    protected DatastoreService $datastoreService,
  ) {}

  /**
   * Trace the full reference chain for a resource.
   */
  public function resolveResource(string $id): array {
    try {
      $distributionUuid = NULL;

      if (str_contains($id, '__')) {
        // Resource ID format: identifier__version.
        [$identifier, $version] = explode('__', $id, 2);
      }
      else {
        // Distribution UUID — fetch and extract %Ref:downloadURL.
        $distributionUuid = $id;
        $extracted = $this->extractResourceFromDistribution($id);
        if (isset($extracted['error'])) {
          return $extracted;
        }
        $identifier = $extracted['identifier'];
        $version = $extracted['version'];
      }

      // Look up perspectives.
      $perspectives = [];
      foreach (self::PERSPECTIVES as $perspectiveName) {
        try {
          $resource = $this->resourceMapper->get($identifier, $perspectiveName, $version);
          if ($resource) {
            $perspectives[] = [
              'perspective' => $perspectiveName,
              'file_path' => $resource->getFilePath(),
              'mime_type' => $resource->getMimeType(),
            ];
          }
        }
        catch (\Exception) {
          // Skip perspectives that fail to load.
        }
      }

      // Get datastore table name from storage if available.
      $datastoreTable = NULL;
      try {
        $storage = $this->datastoreService->getStorage($identifier, $version);
        $datastoreTable = $storage->getTableName();
      }
      catch (\Exception) {
        // Storage not available (resource not yet imported).
      }

      // Get import status.
      $importStatus = $this->getImportStatus($identifier, $version);

      return [
        'distribution_uuid' => $distributionUuid,
        'resource_identifier' => $identifier,
        'resource_version' => $version,
        'resource_id' => $identifier . '__' . $version,
        'perspectives' => $perspectives,
        'datastore_table' => $datastoreTable,
        'import_status' => $importStatus,
      ];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Extract resource identifier and version from a distribution's metadata.
   */
  protected function extractResourceFromDistribution(string $uuid): array {
    try {
      $distribution = $this->metastore->get('distribution', $uuid);
    }
    catch (\Exception $e) {
      return ['error' => "Distribution not found: {$uuid}"];
    }

    $data = json_decode((string) $distribution);
    if (!isset($data->data->{'%Ref:downloadURL'}[0]->data)) {
      return ['error' => "Distribution {$uuid} has no resource reference (%Ref:downloadURL)"];
    }

    $ref = $data->data->{'%Ref:downloadURL'}[0]->data;
    $identifier = $ref->identifier ?? NULL;
    $version = $ref->version ?? NULL;

    if (!$identifier || !$version) {
      return ['error' => "Distribution {$uuid} has incomplete resource reference"];
    }

    return ['identifier' => $identifier, 'version' => (string) $version];
  }

  /**
   * Get import status for a resource.
   */
  protected function getImportStatus(string $identifier, string $version): string {
    try {
      $resourceId = $identifier . '__' . $version;
      $summary = $this->datastoreService->summary($resourceId);
      $numOfRows = is_object($summary) ? ($summary->numOfRows ?? 0) : ($summary['numOfRows'] ?? 0);
      return $numOfRows > 0 ? 'done' : 'pending';
    }
    catch (\Throwable) {
      return 'not_imported';
    }
  }

}
