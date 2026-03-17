<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\common\DatasetInfo;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\MemoryQueue;
use Drupal\dkan_mcp\Tools\StatusTools;
use Drupal\harvest\HarvestService;
use Drupal\metastore\MetastoreService;
use PHPUnit\Framework\TestCase;
use RootedData\RootedJsonData;

class StatusToolsTest extends TestCase {

  protected function createTools(
    ?MetastoreService $metastore = NULL,
    ?DatasetInfo $datasetInfo = NULL,
    ?HarvestService $harvest = NULL,
    ?ModuleHandlerInterface $moduleHandler = NULL,
    ?ModuleExtensionList $moduleList = NULL,
    ?QueueFactory $queueFactory = NULL,
    ?QueueWorkerManagerInterface $queueWorkerManager = NULL,
  ): StatusTools {
    $metastore = $metastore ?? $this->createMock(MetastoreService::class);
    $datasetInfo = $datasetInfo ?? $this->createMock(DatasetInfo::class);
    $harvest = $harvest ?? $this->createMock(HarvestService::class);

    if (!$moduleHandler) {
      $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
      $moduleHandler->method('moduleExists')->willReturn(TRUE);
    }

    if (!$moduleList) {
      $moduleList = $this->createMock(ModuleExtensionList::class);
      $moduleList->method('getAllInstalledInfo')->willReturn([
        'dkan' => ['version' => '2.22.0'],
        'system' => ['version' => '10.6.0'],
      ]);
    }

    $queueFactory = $queueFactory ?? new QueueFactory();
    $queueWorkerManager = $queueWorkerManager ?? $this->createMock(QueueWorkerManagerInterface::class);

    return new StatusTools($metastore, $datasetInfo, $harvest, $moduleHandler, $moduleList, $queueFactory, $queueWorkerManager);
  }

  public function testGetSiteStatusBasic(): void {
    $dataset1 = new RootedJsonData(json_encode([
      'identifier' => 'uuid-1',
      'title' => 'Test Dataset',
      'distribution' => [
        ['mediaType' => 'text/csv'],
      ],
    ]));
    $dataset2 = new RootedJsonData(json_encode([
      'identifier' => 'uuid-2',
      'title' => 'Another Dataset',
      'distribution' => [
        ['mediaType' => 'text/csv'],
        ['mediaType' => 'application/zip'],
      ],
    ]));

    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('count')->with('dataset')->willReturn(2);
    $metastore->method('getAll')->willReturn([$dataset1, $dataset2]);

    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datasetInfo->method('gather')->willReturn([
      'latest_revision' => ['distributions' => [
        ['importer_status' => 'done'],
      ]],
    ]);

    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getAllHarvestIds')->willReturn(['plan-1']);

    $tools = $this->createTools(metastore: $metastore, datasetInfo: $datasetInfo, harvest: $harvest);
    $result = $tools->getSiteStatus();

    $this->assertEquals(2, $result['datasets']['total']);
    $this->assertEquals(3, $result['distributions']['total']);
    $this->assertEquals(1, $result['harvest']['plans']);
    $this->assertArrayHasKey('dkan', $result);
    $this->assertArrayHasKey('drupal', $result);
    $this->assertArrayNotHasKey('sampled', $result);
  }

  public function testGetSiteStatusDistributionFormats(): void {
    $dataset = new RootedJsonData(json_encode([
      'identifier' => 'uuid-1',
      'distribution' => [
        ['mediaType' => 'text/csv'],
        ['mediaType' => 'text/csv'],
        ['mediaType' => 'application/zip'],
        ['format' => 'json'],
      ],
    ]));

    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('count')->willReturn(1);
    $metastore->method('getAll')->willReturn([$dataset]);

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->getSiteStatus();

    $this->assertEquals(2, $result['distributions']['by_format']['csv']);
    $this->assertEquals(1, $result['distributions']['by_format']['zip']);
    $this->assertEquals(1, $result['distributions']['by_format']['json']);
    $this->assertEquals(4, $result['distributions']['total']);
  }

  public function testGetSiteStatusImportCounts(): void {
    $dataset = new RootedJsonData(json_encode([
      'identifier' => 'uuid-1',
      'distribution' => [['mediaType' => 'text/csv']],
    ]));

    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('count')->willReturn(1);
    $metastore->method('getAll')->willReturn([$dataset]);

    $datasetInfo = $this->createMock(DatasetInfo::class);
    $datasetInfo->method('gather')->willReturn([
      'latest_revision' => ['distributions' => [
        ['importer_status' => 'done'],
        ['importer_status' => 'error'],
        ['importer_status' => 'waiting'],
      ]],
    ]);

    $tools = $this->createTools(metastore: $metastore, datasetInfo: $datasetInfo);
    $result = $tools->getSiteStatus();

    $this->assertEquals(1, $result['imports']['done']);
    $this->assertEquals(1, $result['imports']['error']);
    $this->assertEquals(1, $result['imports']['pending']);
  }

  public function testGetSiteStatusNoHarvest(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('count')->willReturn(0);
    $metastore->method('getAll')->willReturn([]);

    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getAllHarvestIds')->willReturn([]);

    $tools = $this->createTools(metastore: $metastore, harvest: $harvest);
    $result = $tools->getSiteStatus();

    $this->assertEquals(0, $result['harvest']['plans']);
    $this->assertEquals(0, $result['datasets']['total']);
  }

  public function testGetSiteStatusServiceError(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('count')->willThrowException(new \Exception('Database connection failed'));

    $tools = $this->createTools(metastore: $metastore);
    $result = $tools->getSiteStatus();

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Database connection failed', $result['error']);
  }

  public function testGetSiteStatusModuleChecks(): void {
    $metastore = $this->createMock(MetastoreService::class);
    $metastore->method('count')->willReturn(0);
    $metastore->method('getAll')->willReturn([]);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(
      fn($module) => in_array($module, ['metastore', 'datastore', 'common']),
    );

    $tools = $this->createTools(metastore: $metastore, moduleHandler: $moduleHandler);
    $result = $tools->getSiteStatus();

    $this->assertEquals('enabled', $result['dkan']['modules']['metastore']);
    $this->assertEquals('enabled', $result['dkan']['modules']['datastore']);
    $this->assertEquals('not_enabled', $result['dkan']['modules']['harvest']);
    $this->assertEquals('enabled', $result['dkan']['modules']['common']);
    $this->assertEquals('not_enabled', $result['dkan']['modules']['metastore_search']);
  }

  public function testGetQueueStatusAllQueues(): void {
    $queueFactory = new QueueFactory();
    $queueFactory->setQueue('datastore_import', new MemoryQueue(5));
    $queueFactory->setQueue('localize_import', new MemoryQueue(3));
    $queueFactory->setQueue('system_queue', new MemoryQueue(10));

    $queueWorkerManager = $this->createMock(QueueWorkerManagerInterface::class);
    $queueWorkerManager->method('getDefinitions')->willReturn([
      'datastore_import' => [
        'title' => 'Datastore Import',
        'provider' => 'datastore',
        'cron' => ['time' => 180],
      ],
      'localize_import' => [
        'title' => 'Localize Import',
        'provider' => 'common',
        'cron' => ['time' => 60, 'lease_time' => 30],
      ],
      'system_queue' => [
        'title' => 'System Queue',
        'provider' => 'system',
      ],
    ]);

    $tools = $this->createTools(queueFactory: $queueFactory, queueWorkerManager: $queueWorkerManager);
    $result = $tools->getQueueStatus();

    $this->assertCount(2, $result['queues']);
    $this->assertEquals('datastore_import', $result['queues'][0]['name']);
    $this->assertEquals(5, $result['queues'][0]['items']);
    $this->assertEquals(180, $result['queues'][0]['cron_time']);
    $this->assertEquals('localize_import', $result['queues'][1]['name']);
    $this->assertEquals(3, $result['queues'][1]['items']);
    $this->assertEquals(30, $result['queues'][1]['lease_time']);
  }

  public function testGetQueueStatusSpecificQueue(): void {
    $queueFactory = new QueueFactory();
    $queueFactory->setQueue('datastore_import', new MemoryQueue(7));

    $queueWorkerManager = $this->createMock(QueueWorkerManagerInterface::class);
    $queueWorkerManager->method('getDefinition')
      ->with('datastore_import')
      ->willReturn([
        'title' => 'Datastore Import',
        'provider' => 'datastore',
        'cron' => ['time' => 180],
      ]);

    $tools = $this->createTools(queueFactory: $queueFactory, queueWorkerManager: $queueWorkerManager);
    $result = $tools->getQueueStatus('datastore_import');

    $this->assertCount(1, $result['queues']);
    $this->assertEquals('datastore_import', $result['queues'][0]['name']);
    $this->assertEquals(7, $result['queues'][0]['items']);
    $this->assertEquals('Datastore Import', $result['queues'][0]['title']);
  }

  public function testGetQueueStatusUnknownQueue(): void {
    $queueWorkerManager = $this->createMock(QueueWorkerManagerInterface::class);
    $queueWorkerManager->method('getDefinition')
      ->with('nonexistent')
      ->willThrowException(new PluginNotFoundException('nonexistent'));

    $tools = $this->createTools(queueWorkerManager: $queueWorkerManager);
    $result = $tools->getQueueStatus('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('nonexistent', $result['error']);
  }

  public function testGetQueueStatusEmptyQueues(): void {
    $queueWorkerManager = $this->createMock(QueueWorkerManagerInterface::class);
    $queueWorkerManager->method('getDefinitions')->willReturn([
      'datastore_import' => [
        'title' => 'Datastore Import',
        'provider' => 'datastore',
      ],
      'post_import' => [
        'title' => 'Post Import',
        'provider' => 'datastore',
      ],
    ]);

    $tools = $this->createTools(queueWorkerManager: $queueWorkerManager);
    $result = $tools->getQueueStatus();

    $this->assertCount(2, $result['queues']);
    $this->assertEquals(0, $result['queues'][0]['items']);
    $this->assertEquals(0, $result['queues'][1]['items']);
  }

}
