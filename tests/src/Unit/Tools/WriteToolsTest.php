<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\dkan_datastore\DatastoreService;
use Drupal\dkan_mcp\Tools\WriteTools;
use Drupal\dkan_metastore\Exception\CannotChangeUuidException;
use Drupal\dkan_metastore\Exception\ExistingObjectException;
use Drupal\dkan_metastore\Exception\MissingObjectException;
use Drupal\dkan_metastore\Exception\UnmodifiedObjectException;
use Drupal\dkan_metastore\MetastoreService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests WriteTools.
 */
class WriteToolsTest extends TestCase {

  /**
   * Create tools.
   */
  protected function createTools(
    ?MetastoreService $metastore = NULL,
    ?DatastoreService $datastore = NULL,
  ): WriteTools {
    $metastore = $metastore ?? $this->createMock(MetastoreService::class);
    $datastore = $datastore ?? $this->createMock(DatastoreService::class);
    return new WriteTools($metastore, $datastore, new NullLogger());
  }

  /**
   * Tests import resource.
   */
  public function testImportResource(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->expects($this->once())
      ->method('import')
      ->with('abc123', FALSE, '456')
      ->willReturn(['status' => 'done']);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->importResource('abc123__456');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('abc123__456', $result['resource_id']);
    $this->assertEquals(['status' => 'done'], $result['import_result']);
  }

  /**
   * Tests import resource deferred.
   */
  public function testImportResourceDeferred(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->expects($this->once())
      ->method('import')
      ->with('abc123', TRUE, '456')
      ->willReturn(['status' => 'queued']);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->importResource('abc123__456', TRUE);

    $this->assertEquals('success', $result['status']);
    $this->assertStringContainsString('queued', $result['message']);
  }

  /**
   * Tests import resource with errors.
   */
  public function testImportResourceWithErrors(): void {
    $errorResult = new class {

      /**
       * Get status.
       */
      public function getStatus(): string {
        return 'error';
      }

      /**
       * Get error.
       */
      public function getError(): string {
        return 'File not found';
      }

    };

    $datastore = $this->createMock(DatastoreService::class);
    $datastore->expects($this->once())
      ->method('import')
      ->with('abc123', FALSE, '456')
      ->willReturn(['ImportService' => $errorResult]);

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->importResource('abc123__456');

    $this->assertEquals('error', $result['status']);
    $this->assertNotEmpty($result['errors']);
    $this->assertStringContainsString('File not found', $result['errors'][0]);
    $this->assertEquals('Import completed with errors.', $result['message']);
  }

  /**
   * Tests import resource error.
   */
  public function testImportResourceError(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('import')->willThrowException(new \Exception('Resource not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->importResource('bad__id');

    $this->assertArrayHasKey('error', $result);
  }

  /**
   * Tests update dataset success.
   */
  public function testUpdateDatasetSuccess(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('put')
      ->willReturn(['identifier' => 'test-uuid', 'new' => FALSE]);

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('test-uuid', '{"title":"Updated"}');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test-uuid', $result['identifier']);
    $this->assertFalse($result['new']);
  }

  /**
   * Tests update dataset creates new.
   */
  public function testUpdateDatasetCreatesNew(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('put')
      ->willReturn(['identifier' => 'new-uuid', 'new' => TRUE]);

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('new-uuid', '{"title":"New Dataset"}');

    $this->assertEquals('success', $result['status']);
    $this->assertTrue($result['new']);
  }

  /**
   * Tests update dataset unmodified.
   */
  public function testUpdateDatasetUnmodified(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('put')
      ->willThrowException(new UnmodifiedObjectException('No changes'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('test-uuid', '{"title":"Same"}');

    $this->assertEquals('unmodified', $result['status']);
    $this->assertEquals('test-uuid', $result['identifier']);
  }

  /**
   * Tests update dataset cannot change uuid.
   */
  public function testUpdateDatasetCannotChangeUuid(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('put')
      ->willThrowException(new CannotChangeUuidException('UUID mismatch'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('test-uuid', '{"identifier":"different-uuid"}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('UUID mismatch', $result['error']);
  }

  /**
   * Tests update dataset invalid json.
   */
  public function testUpdateDatasetInvalidJson(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('put');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('test-uuid', '{invalid json}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid JSON', $result['error']);
  }

  /**
   * Tests update dataset non object json.
   */
  public function testUpdateDatasetNonObjectJson(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('put');

    $tools = $this->createTools(metastore: $metastore);

    $result = $tools->updateDataset('test-uuid', '"just a string"');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('JSON object', $result['error']);

    $result2 = $tools->updateDataset('test-uuid', '[1,2,3]');
    $this->assertArrayHasKey('error', $result2);
    $this->assertStringContainsString('JSON object', $result2['error']);
  }

  /**
   * Tests patch dataset non object json.
   */
  public function testPatchDatasetNonObjectJson(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('patch');

    $tools = $this->createTools(metastore: $metastore);

    $result = $tools->patchDataset('test-uuid', '[1,2,3]');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('JSON object', $result['error']);
  }

  /**
   * Tests patch dataset success.
   */
  public function testPatchDatasetSuccess(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('patch')
      ->with('dataset', 'test-uuid', '{"title":"Patched"}')
      ->willReturn('test-uuid');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->patchDataset('test-uuid', '{"title":"Patched"}');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test-uuid', $result['identifier']);
  }

  /**
   * Tests patch dataset not found.
   */
  public function testPatchDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('patch')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->patchDataset('missing-uuid', '{"title":"Patched"}');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('missing-uuid', $result['identifier']);
  }

  /**
   * Tests delete dataset success.
   */
  public function testDeleteDatasetSuccess(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('delete')
      ->with('dataset', 'test-uuid')
      ->willReturn('test-uuid');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->deleteDataset('test-uuid');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test-uuid', $result['identifier']);
    $this->assertStringContainsString('cascade', $result['message']);
  }

  /**
   * Tests delete dataset not found.
   */
  public function testDeleteDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('delete')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->deleteDataset('missing-uuid');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('missing-uuid', $result['identifier']);
  }

  /**
   * Tests publish dataset success.
   */
  public function testPublishDatasetSuccess(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('publish')
      ->with('dataset', 'test-uuid');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->publishDataset('test-uuid');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test-uuid', $result['identifier']);
  }

  /**
   * Tests publish dataset not found.
   */
  public function testPublishDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('publish')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->publishDataset('missing-uuid');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('missing-uuid', $result['identifier']);
  }

  /**
   * Tests publish dataset error.
   */
  public function testPublishDatasetError(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('publish')
      ->willThrowException(new \Exception('Unexpected error'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->publishDataset('test-uuid');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Unexpected error', $result['error']);
  }

  /**
   * Tests unpublish dataset success.
   */
  public function testUnpublishDatasetSuccess(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('archive')
      ->with('dataset', 'test-uuid');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->unpublishDataset('test-uuid');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test-uuid', $result['identifier']);
    $this->assertStringContainsString('unpublished', $result['message']);
  }

  /**
   * Tests unpublish dataset not found.
   */
  public function testUnpublishDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('archive')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->unpublishDataset('missing-uuid');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('missing-uuid', $result['identifier']);
  }

  /**
   * Tests drop datastore success.
   */
  public function testDropDatastoreSuccess(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->expects($this->once())
      ->method('drop')
      ->with('abc123', '456');

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->dropDatastore('abc123__456');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('abc123__456', $result['resource_id']);
  }

  /**
   * Tests drop datastore error.
   */
  public function testDropDatastoreError(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('drop')
      ->willThrowException(new \Exception('Table not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->dropDatastore('bad__id');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Table not found', $result['error']);
  }

  /**
   * Tests post metastore item success.
   */
  public function testPostMetastoreItemSuccess(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('post')
      ->with('data-dictionary', $this->callback(function ($data) {
        $decoded = json_decode((string) $data);
        return $decoded->identifier === 'dict-uuid-1'
          && $decoded->data->title === 'Test Dictionary';
      }))
      ->willReturn('dict-uuid-1');

    $tools = $this->createTools(metastore: $metastore);
    $json = '{"identifier":"dict-uuid-1","data":{"title":"Test Dictionary","fields":[{"name":"col","type":"string"}]}}';
    $result = $tools->postMetastoreItem('data-dictionary', $json);

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('data-dictionary', $result['schema_id']);
    $this->assertEquals('dict-uuid-1', $result['identifier']);
  }

  /**
   * Tests post metastore item already exists.
   */
  public function testPostMetastoreItemAlreadyExists(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('post')
      ->willThrowException(new ExistingObjectException('data-dictionary/dict-uuid-1 already exists.'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->postMetastoreItem('data-dictionary', '{"identifier":"dict-uuid-1","data":{"title":"x"}}');

    $this->assertEquals('already_exists', $result['status']);
    $this->assertEquals('data-dictionary', $result['schema_id']);
  }

  /**
   * Tests post metastore item invalid json.
   */
  public function testPostMetastoreItemInvalidJson(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('post');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->postMetastoreItem('data-dictionary', '{not json}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid JSON', $result['error']);
  }

  /**
   * Tests post metastore item non object json.
   */
  public function testPostMetastoreItemNonObjectJson(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('post');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->postMetastoreItem('data-dictionary', '[1,2,3]');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('JSON object', $result['error']);
  }

  /**
   * Tests post metastore item error.
   */
  public function testPostMetastoreItemError(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('post')
      ->willThrowException(new \Exception('Schema validation failed'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->postMetastoreItem('data-dictionary', '{"identifier":"x","data":{}}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Schema validation failed', $result['error']);
  }

  /**
   * Tests patch metastore item success.
   */
  public function testPatchMetastoreItemSuccess(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('patch')
      ->with('data-dictionary', 'dict-uuid-1', '{"data":{"title":"Updated"}}')
      ->willReturn('dict-uuid-1');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->patchMetastoreItem('data-dictionary', 'dict-uuid-1', '{"data":{"title":"Updated"}}');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('data-dictionary', $result['schema_id']);
    $this->assertEquals('dict-uuid-1', $result['identifier']);
  }

  /**
   * Tests patch metastore item not found.
   */
  public function testPatchMetastoreItemNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('patch')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->patchMetastoreItem('data-dictionary', 'missing', '{"data":{"title":"x"}}');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('data-dictionary', $result['schema_id']);
    $this->assertEquals('missing', $result['identifier']);
  }

  /**
   * Tests patch metastore item non object json.
   */
  public function testPatchMetastoreItemNonObjectJson(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('patch');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->patchMetastoreItem('data-dictionary', 'dict-uuid-1', '"scalar"');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('JSON object', $result['error']);
  }

}
