<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\dkan_mcp\Tools\HarvestTools;
use Drupal\harvest\HarvestService;
use PHPUnit\Framework\TestCase;

class HarvestToolsTest extends TestCase {

  protected function createTools(HarvestService $harvest): HarvestTools {
    return new HarvestTools($harvest);
  }

  public function testListHarvestPlans(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getAllHarvestIds')->willReturn(['plan_a', 'plan_b']);

    $tools = $this->createTools($harvest);
    $result = $tools->listHarvestPlans();

    $this->assertEquals(['plan_a', 'plan_b'], $result['plan_ids']);
    $this->assertEquals(2, $result['total']);
  }

  public function testListHarvestPlansEmpty(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getAllHarvestIds')->willReturn([]);

    $tools = $this->createTools($harvest);
    $result = $tools->listHarvestPlans();

    $this->assertEmpty($result['plan_ids']);
    $this->assertEquals(0, $result['total']);
  }

  public function testGetHarvestPlan(): void {
    $plan = (object) [
      'identifier' => 'plan_a',
      'extract' => (object) ['type' => 'index', 'uri' => 'http://example.com/data.json'],
    ];
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')->willReturn($plan);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestPlan('plan_a');

    $this->assertArrayHasKey('plan', $result);
    $this->assertEquals('plan_a', $result['plan']['identifier']);
    $this->assertEquals('http://example.com/data.json', $result['plan']['extract']['uri']);
  }

  public function testGetHarvestPlanNotFound(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')->willReturn(NULL);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestPlan('nonexistent');

    $this->assertArrayHasKey('error', $result);
  }

  public function testGetHarvestRuns(): void {
    $runJson = json_encode([
      'status' => ['extract' => 'SUCCESS'],
      'identifier' => '1700000000',
    ]);
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getAllHarvestRunInfo')->willReturn([$runJson]);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestRuns('plan_a');

    $this->assertCount(1, $result['runs']);
    $this->assertEquals(1, $result['total']);
    $this->assertEquals('1700000000', $result['runs'][0]['identifier']);
  }

  public function testGetHarvestRunResult(): void {
    $runResult = [
      'status' => ['extract' => 'SUCCESS', 'load' => ['dataset-1' => 'NEW']],
    ];
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestRunResult')->willReturn($runResult);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestRunResult('plan_a');

    $this->assertArrayHasKey('result', $result);
    $this->assertEquals('SUCCESS', $result['result']['status']['extract']);
  }

  public function testGetHarvestRunResultNotFound(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestRunResult')->willReturn([]);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestRunResult('nonexistent');

    $this->assertArrayHasKey('error', $result);
  }

}
