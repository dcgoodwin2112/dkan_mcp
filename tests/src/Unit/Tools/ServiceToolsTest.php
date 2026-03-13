<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\dkan_mcp\Tools\ServiceTools;
use PHPUnit\Framework\TestCase;
use Drupal\Component\DependencyInjection\ContainerInterface;

class ServiceToolsTest extends TestCase {

  protected function createTools(ContainerInterface $container): ServiceTools {
    return new ServiceTools($container);
  }

  public function testListServicesFiltersByDkanPrefix(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('getServiceIds')->willReturn([
      'dkan.metastore.service',
      'dkan.datastore.service',
      'some.other.service',
      'json_form.something',
    ]);
    $dummyService = new \stdClass();
    $container->method('get')->willReturn($dummyService);

    $tools = $this->createTools($container);
    $result = $tools->listServices();

    $this->assertEquals(2, $result['total']);
    $ids = array_column($result['services'], 'id');
    $this->assertContains('dkan.metastore.service', $ids);
    $this->assertContains('dkan.datastore.service', $ids);
    $this->assertNotContains('some.other.service', $ids);
  }

  public function testListServicesFiltersByModule(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('getServiceIds')->willReturn([
      'dkan.metastore.service',
      'dkan.metastore.storage',
      'dkan.datastore.service',
    ]);
    $dummyService = new \stdClass();
    $container->method('get')->willReturn($dummyService);

    $tools = $this->createTools($container);
    $result = $tools->listServices('metastore');

    $this->assertEquals(2, $result['total']);
    $ids = array_column($result['services'], 'id');
    $this->assertContains('dkan.metastore.service', $ids);
    $this->assertContains('dkan.metastore.storage', $ids);
    $this->assertNotContains('dkan.datastore.service', $ids);
  }

  public function testListServicesSortedById(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('getServiceIds')->willReturn([
      'dkan.z.service',
      'dkan.a.service',
    ]);
    $container->method('get')->willReturn(new \stdClass());

    $tools = $this->createTools($container);
    $result = $tools->listServices();

    $this->assertEquals('dkan.a.service', $result['services'][0]['id']);
    $this->assertEquals('dkan.z.service', $result['services'][1]['id']);
  }

  public function testListServicesHandlesException(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('getServiceIds')->willReturn(['dkan.broken.service']);
    $container->method('get')->willThrowException(new \RuntimeException('Cannot instantiate'));

    $tools = $this->createTools($container);
    $result = $tools->listServices();

    $this->assertEquals(1, $result['total']);
    $this->assertNull($result['services'][0]['class']);
    $this->assertStringContainsString('Cannot instantiate', $result['services'][0]['error']);
  }

  public function testGetServiceInfoNotFound(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(FALSE);

    $tools = $this->createTools($container);
    $result = $tools->getServiceInfo('nonexistent.service');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('not found', $result['error']);
  }

  public function testGetServiceInfoReturnsMethodSignatures(): void {
    $service = new ServiceToolsTestDouble('hello', 42);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container);
    $result = $tools->getServiceInfo('dkan.test.service');

    $this->assertEquals('dkan.test.service', $result['service_id']);
    $this->assertEquals(ServiceToolsTestDouble::class, $result['class']);

    // Constructor params.
    $this->assertCount(2, $result['constructor_params']);
    $this->assertEquals('name', $result['constructor_params'][0]['name']);
    $this->assertEquals('string', $result['constructor_params'][0]['type']);
    $this->assertEquals('count', $result['constructor_params'][1]['name']);
    $this->assertEquals('int', $result['constructor_params'][1]['type']);

    // Public methods (excludes __construct, magic methods, inherited).
    $methodNames = array_column($result['methods'], 'name');
    $this->assertContains('doSomething', $methodNames);
    $this->assertContains('getCount', $methodNames);
    $this->assertNotContains('__construct', $methodNames);

    // Check method signature details.
    $doSomething = $this->findMethod($result['methods'], 'doSomething');
    $this->assertCount(2, $doSomething['params']);
    $this->assertEquals('input', $doSomething['params'][0]['name']);
    $this->assertEquals('string', $doSomething['params'][0]['type']);
    $this->assertEquals('array', $doSomething['return_type']);

    $getCount = $this->findMethod($result['methods'], 'getCount');
    $this->assertCount(0, $getCount['params']);
    $this->assertEquals('int', $getCount['return_type']);
  }

  public function testGetServiceInfoExcludesInheritedMethods(): void {
    $service = new ServiceToolsChildDouble('test', 1);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container);
    $result = $tools->getServiceInfo('dkan.test.child');

    $methodNames = array_column($result['methods'], 'name');
    $this->assertContains('childMethod', $methodNames);
    // Inherited methods from parent should not appear.
    $this->assertNotContains('doSomething', $methodNames);
    $this->assertNotContains('getCount', $methodNames);
  }

  protected function findMethod(array $methods, string $name): ?array {
    foreach ($methods as $method) {
      if ($method['name'] === $name) {
        return $method;
      }
    }
    return NULL;
  }

}

/**
 * Test double for reflection tests.
 */
class ServiceToolsTestDouble {

  public function __construct(
    protected string $name,
    protected int $count,
  ) {}

  public function doSomething(string $input, bool $flag = FALSE): array {
    return [];
  }

  public function getCount(): int {
    return $this->count;
  }

}

/**
 * Child class to test inherited method filtering.
 */
class ServiceToolsChildDouble extends ServiceToolsTestDouble {

  public function childMethod(): string {
    return 'child';
  }

}
