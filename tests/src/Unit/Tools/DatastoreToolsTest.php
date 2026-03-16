<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\common\Storage\DatabaseTableInterface;
use Drupal\datastore\DatastoreService;
use Drupal\datastore\Service\Query;
use Drupal\dkan_mcp\Tools\DatastoreTools;
use PHPUnit\Framework\TestCase;
use RootedData\RootedJsonData;

class DatastoreToolsTest extends TestCase {

  protected function createTools(?DatastoreService $datastore = NULL, ?Query $query = NULL): DatastoreTools {
    $datastore = $datastore ?? $this->createMock(DatastoreService::class);
    $query = $query ?? $this->createMock(Query::class);
    return new DatastoreTools($datastore, $query);
  }

  public function testQueryDatastoreBasic(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['name' => 'Alice', 'age' => '30']],
      'count' => 1,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('test-resource');

    $this->assertArrayHasKey('results', $result);
    $this->assertCount(1, $result['results']);
    $this->assertEquals(1, $result['result_count']);
    $this->assertEquals(1, $result['total_rows']);
    $this->assertArrayNotHasKey('schema', $result);
    $this->assertArrayNotHasKey('count', $result);
  }

  public function testQueryDatastoreWithFilters(): void {
    $queryResult = new RootedJsonData(json_encode([
      'results' => [['state' => 'CA']],
      'count' => 1,
    ]));

    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(query: $queryService);
    $conditions = json_encode([['property' => 'state', 'value' => 'CA', 'operator' => '=']]);
    $result = $tools->queryDatastore('test', 'state', $conditions, 'state', 'asc', 50, 0);

    $this->assertArrayHasKey('results', $result);
    $this->assertEquals(50, $result['limit']);
  }

  public function testQueryDatastoreClampLimit(): void {
    $queryResult = new RootedJsonData('{"results":[],"count":0}');
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willReturn($queryResult);

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('test', limit: 9999);
    $this->assertEquals(500, $result['limit']);
  }

  public function testQueryDatastoreError(): void {
    $queryService = $this->createMock(Query::class);
    $queryService->method('runQuery')->willThrowException(new \Exception('Resource not found'));

    $tools = $this->createTools(query: $queryService);
    $result = $tools->queryDatastore('nonexistent');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Resource not found', $result['error']);
  }

  public function testGetDatastoreSchema(): void {
    $storage = $this->createMock(DatabaseTableInterface::class);
    $storage->method('getSchema')->willReturn([
      'fields' => [
        'record_number' => ['type' => 'serial'],
        'name' => ['type' => 'varchar', 'description' => 'Full name'],
        'age' => ['type' => 'int'],
      ],
    ]);

    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willReturn($storage);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getDatastoreSchema('test-resource');

    $this->assertArrayHasKey('columns', $result);
    $this->assertCount(2, $result['columns']);
    $this->assertEquals('name', $result['columns'][0]['name']);
    $this->assertEquals('varchar', $result['columns'][0]['type']);
    $this->assertEquals('Full name', $result['columns'][0]['description']);
    // Column without description should not have the key.
    $this->assertArrayNotHasKey('description', $result['columns'][1]);
  }

  public function testGetDatastoreSchemaError(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('getStorage')->willThrowException(new \Exception('Not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getDatastoreSchema('bad-id');
    $this->assertArrayHasKey('error', $result);
  }

  public function testQueryDatastoreInvalidConditions(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastore('test-resource', conditions: 'not valid json');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid conditions', $result['error']);
  }

  public function testQueryDatastoreConditionsObject(): void {
    $tools = $this->createTools();
    $result = $tools->queryDatastore('test-resource', conditions: '{"property":"x","value":"y","operator":"="}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('must be a JSON array', $result['error']);
  }

  public function testGetImportStatus(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->expects($this->once())
      ->method('summary')
      ->with('abc123__456')
      ->willReturn([
        'numOfRows' => 100,
        'numOfColumns' => 5,
      ]);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getImportStatus('abc123__456');

    $this->assertEquals('abc123__456', $result['resource_id']);
    $this->assertEquals('done', $result['status']);
    $this->assertEquals(100, $result['num_of_rows']);
    $this->assertEquals(5, $result['num_of_columns']);
  }

  public function testGetImportStatusWithObject(): void {
    $summary = (object) ['numOfRows' => 50, 'numOfColumns' => 3];
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willReturn($summary);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getImportStatus('abc123__456');

    $this->assertEquals('done', $result['status']);
    $this->assertEquals(50, $result['num_of_rows']);
    $this->assertEquals(3, $result['num_of_columns']);
  }

  public function testGetImportStatusNotImported(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('summary')->willThrowException(new \Exception('Resource not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->getImportStatus('nonexistent__123');

    $this->assertEquals('nonexistent__123', $result['resource_id']);
    $this->assertEquals('not_imported', $result['status']);
    $this->assertArrayHasKey('error', $result);
  }

}
