<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\common\DataResource;
use Drupal\datastore\DatastoreService;
use Drupal\dkan_mcp\Tools\ResourceTools;
use Drupal\metastore\MetastoreService;
use Drupal\metastore\ResourceMapper;
use PHPUnit\Framework\TestCase;
use RootedData\RootedJsonData;

class ResourceToolsTest extends TestCase {

  protected function createTools(
    MetastoreService $metastore,
    ResourceMapper $resourceMapper,
    DatastoreService $datastoreService,
  ): ResourceTools {
    return new ResourceTools($metastore, $resourceMapper, $datastoreService);
  }

  protected function createDefaultMocks(): array {
    return [
      $this->createMock(MetastoreService::class),
      $this->createMock(ResourceMapper::class),
      $this->createMock(DatastoreService::class),
    ];
  }

  protected function createDataResource(string $filePath, string $mimeType): DataResource {
    $resource = $this->createMock(DataResource::class);
    $resource->method('getFilePath')->willReturn($filePath);
    $resource->method('getMimeType')->willReturn($mimeType);
    return $resource;
  }

  protected function createDistributionJson(string $identifier, string $version): string {
    return json_encode([
      'identifier' => 'dist-uuid-123',
      'downloadURL' => $identifier . '__' . $version,
      '%Ref:downloadURL' => [
        [
          'identifier' => 'ref-id',
          'data' => [
            'identifier' => $identifier,
            'version' => $version,
            'filePath' => 'https://example.com/data.csv',
            'perspective' => 'source',
            'mimeType' => 'text/csv',
          ],
        ],
      ],
    ]);
  }

  public function testResolveFromResourceId(): void {
    [$metastore, $resourceMapper, $datastoreService] = $this->createDefaultMocks();

    $sourceResource = $this->createDataResource('https://example.com/data.csv', 'text/csv');
    $localResource = $this->createDataResource('public://resources/abc123_111/data.csv', 'text/csv');

    $resourceMapper->method('get')->willReturnMap([
      ['abc123', 'source', '111', $sourceResource],
      ['abc123', 'local_file', '111', $localResource],
      ['abc123', 'local_url', '111', NULL],
    ]);

    $datastoreService->method('summary')->willReturn(['numOfRows' => 100]);

    $tools = $this->createTools($metastore, $resourceMapper, $datastoreService);
    $result = $tools->resolveResource('abc123__111');

    $this->assertNull($result['distribution_uuid']);
    $this->assertEquals('abc123', $result['resource_identifier']);
    $this->assertEquals('111', $result['resource_version']);
    $this->assertEquals('abc123__111', $result['resource_id']);
    $this->assertCount(2, $result['perspectives']);
    $this->assertEquals('source', $result['perspectives'][0]['perspective']);
    $this->assertEquals('https://example.com/data.csv', $result['perspectives'][0]['file_path']);
    $this->assertEquals('local_file', $result['perspectives'][1]['perspective']);
  }

  public function testResolveFromDistributionUuid(): void {
    [$metastore, $resourceMapper, $datastoreService] = $this->createDefaultMocks();

    $json = $this->createDistributionJson('abc123', '111');
    $metastore->method('get')
      ->with('distribution', 'dist-uuid-123')
      ->willReturn(new RootedJsonData($json));

    $resourceMapper->method('get')->willReturn(NULL);
    $datastoreService->method('summary')->willThrowException(new \RuntimeException('Not found'));

    $tools = $this->createTools($metastore, $resourceMapper, $datastoreService);
    $result = $tools->resolveResource('dist-uuid-123');

    $this->assertEquals('dist-uuid-123', $result['distribution_uuid']);
    $this->assertEquals('abc123', $result['resource_identifier']);
    $this->assertEquals('111', $result['resource_version']);
    $this->assertEquals('abc123__111', $result['resource_id']);
  }

  public function testResolveNoPerspectives(): void {
    [$metastore, $resourceMapper, $datastoreService] = $this->createDefaultMocks();

    $resourceMapper->method('get')->willReturn(NULL);
    $datastoreService->method('summary')->willThrowException(new \RuntimeException('Not found'));

    $tools = $this->createTools($metastore, $resourceMapper, $datastoreService);
    $result = $tools->resolveResource('abc123__111');

    $this->assertEmpty($result['perspectives']);
  }

  public function testResolveDistributionNotFound(): void {
    [$metastore, $resourceMapper, $datastoreService] = $this->createDefaultMocks();

    $metastore->method('get')->willThrowException(new \RuntimeException('Not found'));

    $tools = $this->createTools($metastore, $resourceMapper, $datastoreService);
    $result = $tools->resolveResource('nonexistent-uuid');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('not found', $result['error']);
  }

  public function testResolveDatastoreTableName(): void {
    [$metastore, $resourceMapper, $datastoreService] = $this->createDefaultMocks();

    $resourceMapper->method('get')->willReturn(NULL);
    $datastoreService->method('summary')->willThrowException(new \RuntimeException('Not found'));

    $tools = $this->createTools($metastore, $resourceMapper, $datastoreService);
    $result = $tools->resolveResource('abc123__111');

    $expectedTable = 'datastore_' . md5('abc123__111__source');
    $this->assertEquals($expectedTable, $result['datastore_table']);
  }

  public function testResolveImportNotStarted(): void {
    [$metastore, $resourceMapper, $datastoreService] = $this->createDefaultMocks();

    $resourceMapper->method('get')->willReturn(NULL);
    $datastoreService->method('summary')->willThrowException(new \RuntimeException('No import'));

    $tools = $this->createTools($metastore, $resourceMapper, $datastoreService);
    $result = $tools->resolveResource('abc123__111');

    $this->assertEquals('not_imported', $result['import_status']);
  }

  public function testResolveDistributionNoRefDownloadUrl(): void {
    [$metastore, $resourceMapper, $datastoreService] = $this->createDefaultMocks();

    $json = json_encode(['identifier' => 'dist-uuid', 'downloadURL' => 'https://example.com/data.csv']);
    $metastore->method('get')->willReturn(new RootedJsonData($json));

    $tools = $this->createTools($metastore, $resourceMapper, $datastoreService);
    $result = $tools->resolveResource('dist-uuid');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('%Ref:downloadURL', $result['error']);
  }

}
