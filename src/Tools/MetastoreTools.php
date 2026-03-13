<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\common\DatasetInfo;
use Drupal\metastore\MetastoreService;

/**
 * MCP tools for DKAN metastore operations.
 */
class MetastoreTools {

  public function __construct(
    protected MetastoreService $metastore,
    protected DatasetInfo $datasetInfo,
  ) {}

  /**
   * List dataset summaries with pagination.
   */
  public function listDatasets(int $offset = 0, int $limit = 25): array {
    $limit = min(max($limit, 1), 100);
    $datasets = $this->metastore->getAll('dataset', $offset, $limit);
    $total = $this->metastore->count('dataset');

    $items = [];
    foreach ($datasets as $dataset) {
      $data = json_decode((string) $dataset);
      $items[] = [
        'identifier' => $data->identifier ?? NULL,
        'title' => $data->title ?? NULL,
        'description' => isset($data->description) ? mb_substr($data->description, 0, 200) : NULL,
        'distributions' => isset($data->distribution) ? count($data->distribution) : 0,
      ];
    }

    return [
      'datasets' => $items,
      'total' => $total,
      'offset' => $offset,
      'limit' => $limit,
    ];
  }

  /**
   * Get full dataset metadata by UUID.
   */
  public function getDataset(string $identifier): array {
    try {
      $dataset = $this->metastore->get('dataset', $identifier);
      return ['dataset' => json_decode((string) $dataset, TRUE)];
    }
    catch (\Exception $e) {
      return ['error' => 'Dataset not found: ' . $identifier];
    }
  }

  /**
   * List distributions for a dataset.
   */
  public function listDistributions(string $datasetId): array {
    try {
      $dataset = $this->metastore->get('dataset', $datasetId);
      $data = json_decode((string) $dataset);
      $distributions = [];

      if (isset($data->distribution)) {
        foreach ($data->distribution as $dist) {
          // Extract the resource identifier from %Ref:downloadURL for
          // use with datastore tools (query_datastore, get_datastore_schema).
          $resourceId = NULL;
          if (isset($dist->{'%Ref:downloadURL'}[0]->data)) {
            $ref = $dist->{'%Ref:downloadURL'}[0]->data;
            $resourceId = ($ref->identifier ?? '') . '__' . ($ref->version ?? '');
          }
          $distributions[] = [
            'identifier' => $dist->identifier ?? NULL,
            'resource_id' => $resourceId,
            'title' => $dist->title ?? NULL,
            'mediaType' => $dist->mediaType ?? NULL,
            'downloadURL' => $dist->downloadURL ?? NULL,
          ];
        }
      }

      return ['distributions' => $distributions];
    }
    catch (\Exception $e) {
      return ['error' => 'Dataset not found: ' . $datasetId];
    }
  }

  /**
   * Get distribution metadata by UUID.
   */
  public function getDistribution(string $identifier): array {
    try {
      $distribution = $this->metastore->get('distribution', $identifier);
      return ['distribution' => json_decode((string) $distribution, TRUE)];
    }
    catch (\Exception $e) {
      return ['error' => 'Distribution not found: ' . $identifier];
    }
  }

  /**
   * List available schema IDs.
   */
  public function listSchemas(): array {
    return ['schemas' => $this->metastore->getSchemas()];
  }

  /**
   * Get the full DCAT catalog.
   */
  public function getCatalog(): array {
    $catalog = $this->metastore->getCatalog();
    return ['catalog' => json_decode(json_encode($catalog), TRUE)];
  }

  /**
   * Get aggregated dataset info: distributions, resources, import status.
   */
  public function getDatasetInfo(string $uuid): array {
    try {
      $info = $this->datasetInfo->gather($uuid);
      if (isset($info['notice'])) {
        return ['error' => $info['notice']];
      }
      return ['dataset_info' => $info];
    }
    catch (\Exception $e) {
      return ['error' => 'Failed to gather dataset info: ' . $e->getMessage()];
    }
  }

}
