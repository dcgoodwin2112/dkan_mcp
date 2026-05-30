<?php

namespace Drupal\dkan_query_tools\Tool;

// Three related tool-class doubles bundled in one file; one-class-per-file
// does not apply to throwaway test stubs.
// phpcs:disable Drupal.Classes.ClassFileName.NoMatch
// phpcs:disable Drupal.Commenting.FunctionComment

/**
 * Stub for Drupal\dkan_query_tools\Tool\MetastoreTools.
 *
 * The real class lives in the dkan_query_tools module and is not autoloadable
 * in this standalone test context. McpServerFactory registers its methods as
 * MCP tool handlers, so the public method signatures must exist for the SDK to
 * reflect them at build time. Bodies are irrelevant — the tools are never
 * invoked in these structural tests.
 */
class MetastoreTools {

  /**
   * Stub.
   */
  public function listDatasets(int $offset = 0, int $limit = 25): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getDataset(string $identifier): array {
    return [];
  }

  /**
   * Stub.
   */
  public function listDistributions(string $datasetId): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getDistribution(string $identifier): array {
    return [];
  }

  /**
   * Stub.
   */
  public function listSchemas(): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getCatalog(): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getSchema(string $schemaId): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getDataDictionary(string $datasetOrResourceId): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getDatasetInfo(string $uuid): array {
    return [];
  }

}

/**
 * Stub for Drupal\dkan_query_tools\Tool\DatastoreTools.
 */
class DatastoreTools {

  /**
   * Stub.
   */
  public function queryDatastore(
    string $resourceId,
    ?string $columns = NULL,
    ?string $conditions = NULL,
    ?string $sortField = NULL,
    string $sortDirection = 'asc',
    int $limit = 100,
    int $offset = 0,
    ?string $expressions = NULL,
    ?string $groupings = NULL,
    int $maxLimit = 500,
  ): array {
    return [];
  }

  /**
   * Stub.
   */
  public function queryDatastoreJoin(
    string $resourceId,
    string $joinResourceId,
    string $joinOn,
    ?string $columns = NULL,
    ?string $conditions = NULL,
    ?string $sortField = NULL,
    string $sortDirection = 'asc',
    int $limit = 100,
    int $offset = 0,
    ?string $expressions = NULL,
    ?string $groupings = NULL,
    int $maxLimit = 500,
  ): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getDatastoreSchema(string $resourceId, bool $includeDictionary = TRUE): array {
    return [];
  }

  /**
   * Stub.
   */
  public function searchColumns(string $searchTerm, string $searchIn = 'name', int $limit = 100): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getDatastoreStats(string $resourceId, ?string $columns = NULL): array {
    return [];
  }

  /**
   * Stub.
   */
  public function getImportStatus(string $resourceId): array {
    return [];
  }

}

/**
 * Stub for Drupal\dkan_query_tools\Tool\SearchTools.
 */
class SearchTools {

  /**
   * Stub.
   */
  public function searchDatasets(string $keyword, int $page = 1, int $pageSize = 10): array {
    return [];
  }

}
