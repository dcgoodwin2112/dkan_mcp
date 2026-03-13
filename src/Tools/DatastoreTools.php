<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\datastore\DatastoreService;
use Drupal\datastore\Service\Query;

/**
 * MCP tools for DKAN datastore operations.
 */
class DatastoreTools {

  public function __construct(
    protected DatastoreService $datastoreService,
    protected Query $queryService,
  ) {}

  /**
   * Query a datastore resource with filters, sorting, and pagination.
   */
  public function queryDatastore(
    string $resourceId,
    ?string $columns = NULL,
    ?string $conditions = NULL,
    ?string $sortField = NULL,
    string $sortDirection = 'asc',
    int $limit = 100,
    int $offset = 0,
  ): array {
    $limit = min(max($limit, 1), 500);

    $query = [
      'resources' => [['id' => $resourceId, 'alias' => 't']],
      'limit' => $limit,
      'offset' => $offset,
      'count' => TRUE,
      'results' => TRUE,
      'schema' => TRUE,
      'keys' => TRUE,
    ];

    if ($columns) {
      $columnList = array_map('trim', explode(',', $columns));
      $query['properties'] = $columnList;
    }

    if ($conditions) {
      $parsed = json_decode($conditions, TRUE);
      if (is_array($parsed)) {
        $query['conditions'] = $parsed;
      }
    }

    if ($sortField) {
      $query['sorts'] = [
        [
          'property' => $sortField,
          'order' => strtolower($sortDirection) === 'desc' ? 'desc' : 'asc',
        ],
      ];
    }

    try {
      $datastoreQuery = new \Drupal\datastore\Service\DatastoreQuery(
        json_encode($query),
        $limit,
      );
      $result = $this->queryService->runQuery($datastoreQuery);
      $decoded = json_decode((string) $result, TRUE);

      return [
        'results' => $decoded['results'] ?? [],
        'count' => $decoded['count'] ?? 0,
        'schema' => $decoded['schema'] ?? [],
        'limit' => $limit,
        'offset' => $offset,
      ];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get the schema (column names and types) for a datastore resource.
   */
  public function getDatastoreSchema(string $resourceId): array {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $storage = $this->datastoreService->getStorage($identifier, $version);
      $schema = $storage->getSchema();

      $columns = [];
      if (isset($schema['fields'])) {
        foreach ($schema['fields'] as $name => $definition) {
          if ($name === 'record_number') {
            continue;
          }
          $columns[] = [
            'name' => $name,
            'type' => $definition['type'] ?? 'unknown',
            'description' => $definition['description'] ?? NULL,
          ];
        }
      }

      return ['resource_id' => $resourceId, 'columns' => $columns];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Get import status for a datastore resource.
   */
  public function getImportStatus(string $resourceId): array {
    try {
      [$identifier, $version] = $this->parseResourceId($resourceId);
      $summary = $this->datastoreService->summary($identifier);
      return ['resource_id' => $resourceId, 'status' => $summary];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Parse a resource_id string into [identifier, version].
   *
   * @return array{string, string|null}
   */
  protected function parseResourceId(string $resourceId): array {
    if (str_contains($resourceId, '__')) {
      $parts = explode('__', $resourceId, 2);
      return [$parts[0], $parts[1]];
    }
    return [$resourceId, NULL];
  }

}
