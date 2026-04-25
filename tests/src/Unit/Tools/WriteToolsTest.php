<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\datastore\DatastoreService;
use Drupal\dkan_mcp\Tools\WriteTools;
use Drupal\metastore\Exception\CannotChangeUuidException;
use Drupal\metastore\Exception\MissingObjectException;
use Drupal\metastore\Exception\UnmodifiedObjectException;
use Drupal\metastore\MetastoreService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WriteToolsTest extends TestCase {

  protected function createTools(
    ?ModuleInstallerInterface $installer = NULL,
    ?ModuleHandlerInterface $handler = NULL,
    ?MetastoreService $metastore = NULL,
    ?DatastoreService $datastore = NULL,
  ): WriteTools {
    $installer = $installer ?? $this->createMock(ModuleInstallerInterface::class);
    $handler = $handler ?? $this->createMock(ModuleHandlerInterface::class);
    $metastore = $metastore ?? $this->createMock(MetastoreService::class);
    $datastore = $datastore ?? $this->createMock(DatastoreService::class);
    return new WriteTools($installer, $handler, $metastore, $datastore, new NullLogger());
  }

  public function testClearCache(): void {
    $tools = $this->createTools();
    $result = $tools->clearCache();

    $this->assertEquals('success', $result['status']);
    $this->assertArrayHasKey('message', $result);
  }

  public function testEnableModuleSuccess(): void {
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->with('test_module')->willReturn(FALSE);

    $installer = $this->createMock(ModuleInstallerInterface::class);
    $installer->expects($this->once())
      ->method('install')
      ->with(['test_module']);

    $tools = $this->createTools(installer: $installer, handler: $handler);
    $result = $tools->enableModule('test_module');

    $this->assertEquals('success', $result['status']);
  }

  public function testEnableModuleAlreadyEnabled(): void {
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->with('test_module')->willReturn(TRUE);

    $installer = $this->createMock(ModuleInstallerInterface::class);
    $installer->expects($this->never())->method('install');

    $tools = $this->createTools(installer: $installer, handler: $handler);
    $result = $tools->enableModule('test_module');

    $this->assertEquals('already_enabled', $result['status']);
  }

  public function testEnableModuleError(): void {
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->willReturn(FALSE);

    $installer = $this->createMock(ModuleInstallerInterface::class);
    $installer->method('install')->willThrowException(new \Exception('Module not found'));

    $tools = $this->createTools(installer: $installer, handler: $handler);
    $result = $tools->enableModule('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Module not found', $result['error']);
  }

  public function testDisableModuleSuccess(): void {
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->with('test_module')->willReturn(TRUE);

    $installer = $this->createMock(ModuleInstallerInterface::class);
    $installer->expects($this->once())
      ->method('uninstall')
      ->with(['test_module']);

    $tools = $this->createTools(installer: $installer, handler: $handler);
    $result = $tools->disableModule('test_module');

    $this->assertEquals('success', $result['status']);
  }

  public function testDisableModuleNotEnabled(): void {
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->with('test_module')->willReturn(FALSE);

    $installer = $this->createMock(ModuleInstallerInterface::class);
    $installer->expects($this->never())->method('uninstall');

    $tools = $this->createTools(installer: $installer, handler: $handler);
    $result = $tools->disableModule('test_module');

    $this->assertEquals('not_enabled', $result['status']);
  }

  public function testCreateTestDataset(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->once())
      ->method('post')
      ->with('dataset', $this->callback(function ($data) {
        $decoded = json_decode((string) $data);
        return !empty($decoded->identifier)
          && $decoded->title === 'Test Data'
          && $decoded->distribution[0]->downloadURL === 'https://example.com/data.csv'
          && $decoded->{'@type'} === 'dcat:Dataset'
          && !empty($decoded->modified)
          && preg_match('/^\d{4}-\d{2}-\d{2}$/', $decoded->modified)
          && is_array($decoded->keyword)
          && count($decoded->keyword) >= 1;
      }))
      ->willReturn('test-uuid-1234');
    $metastore->expects($this->once())
      ->method('publish')
      ->with('dataset', 'test-uuid-1234');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->createTestDataset('Test Data', 'https://example.com/data.csv');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test-uuid-1234', $result['identifier']);
  }

  public function testCreateTestDatasetError(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('post')->willThrowException(new \Exception('Validation failed'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->createTestDataset('Bad', 'not-a-url');

    $this->assertArrayHasKey('error', $result);
  }

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

  public function testImportResourceWithErrors(): void {
    $errorResult = new class {

      public function getStatus(): string {
        return 'error';
      }

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

  public function testImportResourceError(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('import')->willThrowException(new \Exception('Resource not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->importResource('bad__id');

    $this->assertArrayHasKey('error', $result);
  }

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

  public function testUpdateDatasetCreatesNew(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('put')
      ->willReturn(['identifier' => 'new-uuid', 'new' => TRUE]);

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('new-uuid', '{"title":"New Dataset"}');

    $this->assertEquals('success', $result['status']);
    $this->assertTrue($result['new']);
  }

  public function testUpdateDatasetUnmodified(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('put')
      ->willThrowException(new UnmodifiedObjectException('No changes'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('test-uuid', '{"title":"Same"}');

    $this->assertEquals('unmodified', $result['status']);
    $this->assertEquals('test-uuid', $result['identifier']);
  }

  public function testUpdateDatasetCannotChangeUuid(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('put')
      ->willThrowException(new CannotChangeUuidException('UUID mismatch'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('test-uuid', '{"identifier":"different-uuid"}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('UUID mismatch', $result['error']);
  }

  public function testUpdateDatasetInvalidJson(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('put');

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->updateDataset('test-uuid', '{invalid json}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid JSON', $result['error']);
  }

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

  public function testPatchDatasetNonObjectJson(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->expects($this->never())->method('patch');

    $tools = $this->createTools(metastore: $metastore);

    $result = $tools->patchDataset('test-uuid', '[1,2,3]');
    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('JSON object', $result['error']);
  }

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

  public function testPatchDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('patch')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->patchDataset('missing-uuid', '{"title":"Patched"}');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('missing-uuid', $result['identifier']);
  }

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

  public function testDeleteDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('delete')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->deleteDataset('missing-uuid');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('missing-uuid', $result['identifier']);
  }

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

  public function testPublishDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('publish')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->publishDataset('missing-uuid');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('missing-uuid', $result['identifier']);
  }

  public function testPublishDatasetError(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('publish')
      ->willThrowException(new \Exception('Unexpected error'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->publishDataset('test-uuid');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Unexpected error', $result['error']);
  }

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

  public function testUnpublishDatasetNotFound(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('archive')
      ->willThrowException(new MissingObjectException('Not found'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->unpublishDataset('missing-uuid');

    $this->assertEquals('not_found', $result['status']);
    $this->assertEquals('missing-uuid', $result['identifier']);
  }

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

  public function testDropDatastoreError(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('drop')
      ->willThrowException(new \Exception('Table not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->dropDatastore('bad__id');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Table not found', $result['error']);
  }

}
