<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\dkan_mcp\Tools\HarvestTools;
use Drupal\harvest\HarvestService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HarvestToolsTest extends TestCase {

  protected function createTools(HarvestService $harvest): HarvestTools {
    return new HarvestTools($harvest, new NullLogger());
  }

  public function testListHarvestPlans(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getAllHarvestIds')->willReturn(['plan_a', 'plan_b']);

    $tools = $this->createTools($harvest);
    $result = $tools->listHarvestPlans();

    $this->assertEquals(['plan_a', 'plan_b'], $result['plans']);
    $this->assertEquals(2, $result['total']);
  }

  public function testListHarvestPlansEmpty(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getAllHarvestIds')->willReturn([]);

    $tools = $this->createTools($harvest);
    $result = $tools->listHarvestPlans();

    $this->assertEmpty($result['plans']);
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
      'plan' => json_encode(['identifier' => 'plan_a', 'extract' => ['type' => 'index']]),
    ]);
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')->willReturn((object) ['identifier' => 'plan_a']);
    // Use object keys to simulate real DKAN response.
    $harvest->method('getAllHarvestRunInfo')->willReturn([42 => $runJson]);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestRuns('plan_a');

    $this->assertCount(1, $result['runs']);
    $this->assertEquals(1, $result['total']);
    $this->assertEquals('1700000000', $result['runs'][0]['identifier']);
    // Plan config should be stripped to reduce token waste.
    $this->assertArrayNotHasKey('plan', $result['runs'][0]);
    // Runs should be numerically indexed (array_values).
    $this->assertArrayHasKey(0, $result['runs']);
  }

  public function testGetHarvestRunResult(): void {
    $runResult = [
      'status' => ['extract' => 'SUCCESS', 'load' => ['dataset-1' => 'NEW']],
      'plan' => json_encode(['identifier' => 'plan_a', 'extract' => ['type' => 'index']]),
    ];
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestRunResult')->willReturn($runResult);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestRunResult('plan_a');

    $this->assertArrayHasKey('result', $result);
    $this->assertEquals('SUCCESS', $result['result']['status']['extract']);
    // Plan config should be stripped to reduce token waste.
    $this->assertArrayNotHasKey('plan', $result['result']);
  }

  public function testGetHarvestRunResultNotFound(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestRunResult')->willReturn([]);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestRunResult('nonexistent');

    $this->assertArrayHasKey('error', $result);
  }

  public function testGetHarvestRunResultNotFoundWithRunId(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestRunResult')->willReturn([]);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestRunResult('sample_content', 'nonexistent_run');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('sample_content', $result['error']);
    $this->assertStringContainsString('nonexistent_run', $result['error']);
  }

  public function testGetHarvestRunsErrorsOnInvalidPlan(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')->willReturn(NULL);

    $tools = $this->createTools($harvest);
    $result = $tools->getHarvestRuns('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Harvest plan not found', $result['error']);
  }

  public function testRegisterHarvestSuccess(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->expects($this->once())
      ->method('registerHarvest')
      ->with($this->callback(function ($plan) {
        return $plan->identifier === 'test_plan'
          && $plan->extract->type === 'index';
      }));

    $tools = $this->createTools($harvest);
    $plan = json_encode([
      'identifier' => 'test_plan',
      'extract' => ['type' => 'index', 'uri' => 'http://example.com/data.json'],
      'load' => ['type' => 'dataset'],
    ]);
    $result = $tools->registerHarvest($plan);

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test_plan', $result['plan_id']);
  }

  public function testRegisterHarvestInvalidJson(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->expects($this->never())->method('registerHarvest');

    $tools = $this->createTools($harvest);
    $result = $tools->registerHarvest('{invalid}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Invalid JSON', $result['error']);
  }

  public function testRegisterHarvestNonObject(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->expects($this->never())->method('registerHarvest');

    $tools = $this->createTools($harvest);
    $result = $tools->registerHarvest('"just a string"');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('JSON object', $result['error']);
  }

  public function testRegisterHarvestError(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('registerHarvest')
      ->willThrowException(new \Exception('Validation failed'));

    $tools = $this->createTools($harvest);
    $result = $tools->registerHarvest('{"identifier":"bad"}');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Validation failed', $result['error']);
  }

  public function testRunHarvestSuccess(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')
      ->with('test_plan')
      ->willReturn((object) ['identifier' => 'test_plan']);
    $harvest->expects($this->once())
      ->method('runHarvest')
      ->with('test_plan')
      ->willReturn(['status' => ['extract' => 'SUCCESS']]);

    $tools = $this->createTools($harvest);
    $result = $tools->runHarvest('test_plan');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test_plan', $result['plan_id']);
    $this->assertArrayHasKey('result', $result);
  }

  public function testRunHarvestNotFound(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')->willReturn(NULL);
    $harvest->expects($this->never())->method('runHarvest');

    $tools = $this->createTools($harvest);
    $result = $tools->runHarvest('nonexistent');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Harvest plan not found', $result['error']);
  }

  public function testRunHarvestError(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')
      ->willReturn((object) ['identifier' => 'test_plan']);
    $harvest->method('runHarvest')
      ->willThrowException(new \Exception('Extract failed'));

    $tools = $this->createTools($harvest);
    $result = $tools->runHarvest('test_plan');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Extract failed', $result['error']);
  }

  public function testDeregisterHarvestSuccess(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')
      ->with('test_plan')
      ->willReturn((object) ['identifier' => 'test_plan']);
    $harvest->expects($this->once())
      ->method('deregisterHarvest')
      ->with('test_plan');

    $tools = $this->createTools($harvest);
    $result = $tools->deregisterHarvest('test_plan');

    $this->assertEquals('success', $result['status']);
    $this->assertEquals('test_plan', $result['plan_id']);
  }

  public function testDeregisterHarvestNotFound(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')->willReturn(NULL);
    $harvest->expects($this->never())->method('deregisterHarvest');

    $tools = $this->createTools($harvest);
    $result = $tools->deregisterHarvest('nonexistent');

    $this->assertEquals('not_found', $result['status']);
    $this->assertStringContainsString('not found', $result['message']);
  }

  public function testDeregisterHarvestError(): void {
    $harvest = $this->createMock(HarvestService::class);
    $harvest->method('getHarvestPlanObject')
      ->willReturn((object) ['identifier' => 'test_plan']);
    $harvest->method('deregisterHarvest')
      ->willThrowException(new \Exception('Deregister failed'));

    $tools = $this->createTools($harvest);
    $result = $tools->deregisterHarvest('test_plan');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('Deregister failed', $result['error']);
  }

}
