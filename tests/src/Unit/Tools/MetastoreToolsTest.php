<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\common\DatasetInfo;
use Drupal\dkan_mcp\Tools\MetastoreTools;
use Drupal\metastore\MetastoreService;
use PHPUnit\Framework\TestCase;
use RootedData\RootedJsonData;

class MetastoreToolsTest extends TestCase {

  protected function createTools(MetastoreService $metastore, ?DatasetInfo $datasetInfo = NULL): MetastoreTools {
    $datasetInfo = $datasetInfo ?? $this->createMock(DatasetInfo::class);
    return new MetastoreTools($metastore, $datasetInfo);
  }

  public function testListDatasets(): void {
    $dataset1 = new RootedJsonData(json_encode([
      'identifier' => 'abc-123',
      'title' => 'Test Dataset',
      'description' => 'A test dataset',
      'distribution' => [['downloadURL' => 'http://example.com/data.csv']],
    ]));

    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([$dataset1]);
    $metastore->method('count')->willReturn(1);

    $tools = $this->createTools($metastore);
    $result = $tools->listDatasets(0, 25);

    $this->assertArrayHasKey('datasets', $result);
    $this->assertArrayHasKey('total', $result);
    $this->assertEquals(1, $result['total']);
    $this->assertCount(1, $result['datasets']);
    $this->assertEquals('abc-123', $result['datasets'][0]['identifier']);
    $this->assertEquals('Test Dataset', $result['datasets'][0]['title']);
    $this->assertEquals(1, $result['datasets'][0]['distributions']);
  }

  public function testListDatasetsClampLimit(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getAll')->willReturn([]);
    $metastore->method('count')->willReturn(0);

    $tools = $this->createTools($metastore);
    $result = $tools->listDatasets(0, 999);
    $this->assertEquals(100, $result['limit']);
  }

  public function testGetDataset(): void {
    $data = [
      'identifier' => 'abc-123',
      'title' => 'Test',
      'description' => 'Full description',
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($data)));

    $tools = $this->createTools($metastore);
    $result = $tools->getDataset('abc-123');

    $this->assertArrayHasKey('dataset', $result);
    $this->assertEquals('abc-123', $result['dataset']['identifier']);
  }

  public function testGetDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willThrowException(new \Exception('Not found'));

    $tools = $this->createTools($metastore);
    $result = $tools->getDataset('nonexistent');
    $this->assertArrayHasKey('error', $result);
  }

  public function testListDistributions(): void {
    $data = [
      'identifier' => 'abc-123',
      'distribution' => [
        [
          'identifier' => 'dist-1',
          'title' => 'CSV File',
          'mediaType' => 'text/csv',
          'downloadURL' => 'http://example.com/data.csv',
        ],
      ],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($data)));

    $tools = $this->createTools($metastore);
    $result = $tools->listDistributions('abc-123');

    $this->assertCount(1, $result['distributions']);
    $this->assertEquals('dist-1', $result['distributions'][0]['identifier']);
    $this->assertEquals('text/csv', $result['distributions'][0]['mediaType']);
  }

  public function testGetDistribution(): void {
    $data = ['identifier' => 'dist-1', 'mediaType' => 'text/csv'];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('get')->willReturn(new RootedJsonData(json_encode($data)));

    $tools = $this->createTools($metastore);
    $result = $tools->getDistribution('dist-1');

    $this->assertArrayHasKey('distribution', $result);
    $this->assertEquals('dist-1', $result['distribution']['identifier']);
  }

  public function testListSchemas(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getSchemas')->willReturn(['dataset', 'distribution', 'keyword']);

    $tools = $this->createTools($metastore);
    $result = $tools->listSchemas();

    $this->assertEquals(['dataset', 'distribution', 'keyword'], $result['schemas']);
  }

  public function testGetCatalog(): void {
    $catalog = (object) ['@type' => 'dcat:Catalog', 'dataset' => []];
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('getCatalog')->willReturn($catalog);

    $tools = $this->createTools($metastore);
    $result = $tools->getCatalog();

    $this->assertArrayHasKey('catalog', $result);
    $this->assertEquals('dcat:Catalog', $result['catalog']['@type']);
  }

  public function testGetDatasetInfo(): void {
    $info = [
      'latest_revision' => [
        'uuid' => 'abc-123',
        'title' => 'Test Dataset',
        'distributions' => [
          [
            'distribution_uuid' => 'dist-1',
            'resource_id' => 'res-hash',
            'resource_version' => '1234567890',
          ],
        ],
      ],
    ];
    $metastore = $this->createMock(MetastoreService::class);
    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datasetInfo->method('gather')->willReturn($info);

    $tools = $this->createTools($metastore, $datasetInfo);
    $result = $tools->getDatasetInfo('abc-123');

    $this->assertArrayHasKey('dataset_info', $result);
    $this->assertEquals('abc-123', $result['dataset_info']['latest_revision']['uuid']);
  }

  public function testGetDatasetInfoNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datasetInfo->method('gather')->willReturn(['notice' => 'Not found']);

    $tools = $this->createTools($metastore, $datasetInfo);
    $result = $tools->getDatasetInfo('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertEquals('Not found', $result['error']);
  }

}
