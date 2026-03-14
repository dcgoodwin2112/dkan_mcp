<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\datastore\DatastoreService;
use Drupal\dkan_mcp\Tools\WriteTools;
use Drupal\metastore\MetastoreService;
use PHPUnit\Framework\TestCase;

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
    return new WriteTools($installer, $handler, $metastore, $datastore);
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
        return $decoded->title === 'Test Data'
          && $decoded->distribution[0]->downloadURL === 'https://example.com/data.csv'
          && $decoded->{'@type'} === 'dcat:Dataset';
      }))
      ->willReturn('test-uuid-1234');

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

  public function testImportResourceError(): void {
    $datastore = $this->createMock(DatastoreService::class);
    $datastore->method('import')->willThrowException(new \Exception('Resource not found'));

    $tools = $this->createTools(datastore: $datastore);
    $result = $tools->importResource('bad__id');

    $this->assertArrayHasKey('error', $result);
  }

}
