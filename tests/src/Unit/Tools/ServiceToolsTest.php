<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\dkan_mcp\Tools\ServiceTools;
use PHPUnit\Framework\TestCase;
use Drupal\Component\DependencyInjection\ContainerInterface;

class ServiceToolsTest extends TestCase {

  protected function createTools(
    ContainerInterface $container,
    ?ModuleHandlerInterface $moduleHandler = NULL,
  ): ServiceTools {
    if (!$moduleHandler) {
      $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
      $moduleHandler->method('getModuleList')->willReturn([]);
    }
    return new ServiceTools($container, $moduleHandler);
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

  public function testGetClassInfoClassNotFound(): void {
    $container = $this->createMock(ContainerInterface::class);
    $tools = $this->createTools($container);
    $result = $tools->getClassInfo('Nonexistent\\ClassName');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('not found', $result['error']);
  }

  public function testGetClassInfoReturnsAllPublicMethods(): void {
    $container = $this->createMock(ContainerInterface::class);
    $tools = $this->createTools($container);
    $result = $tools->getClassInfo(ClassInfoTestDouble::class);

    $this->assertEquals(ClassInfoTestDouble::class, $result['class']);
    $this->assertFalse($result['is_abstract']);
    $this->assertFalse($result['is_interface']);
    $this->assertContains(ClassInfoTestInterface::class, $result['interfaces']);

    $methodNames = array_column($result['methods'], 'name');
    $this->assertContains('ownMethod', $methodNames);
    $this->assertContains('interfaceMethod', $methodNames);

    // Check declared_in for own method.
    $ownMethod = $this->findMethod($result['methods'], 'ownMethod');
    $this->assertEquals(ClassInfoTestDouble::class, $ownMethod['declared_in']);
    $this->assertEquals('string', $ownMethod['params'][0]['type']);
    $this->assertEquals('array', $ownMethod['return_type']);
  }

  public function testGetClassInfoIncludesInheritedMethods(): void {
    $container = $this->createMock(ContainerInterface::class);
    $tools = $this->createTools($container);
    $result = $tools->getClassInfo(ServiceToolsChildDouble::class);

    $this->assertEquals(ServiceToolsTestDouble::class, $result['parent']);

    $methodNames = array_column($result['methods'], 'name');
    // Unlike getServiceInfo, getClassInfo includes inherited methods.
    $this->assertContains('childMethod', $methodNames);
    $this->assertContains('doSomething', $methodNames);
    $this->assertContains('getCount', $methodNames);

    // Verify declared_in tracks origin.
    $doSomething = $this->findMethod($result['methods'], 'doSomething');
    $this->assertEquals(ServiceToolsTestDouble::class, $doSomething['declared_in']);
  }

  public function testGetClassInfoWorksOnInterface(): void {
    $container = $this->createMock(ContainerInterface::class);
    $tools = $this->createTools($container);
    $result = $tools->getClassInfo(ClassInfoTestInterface::class);

    $this->assertTrue($result['is_interface']);
    $methodNames = array_column($result['methods'], 'name');
    $this->assertContains('interfaceMethod', $methodNames);
  }

  public function testGetServiceInfoIncludesYamlDefinition(): void {
    $service = new ServiceToolsTestDouble('hello', 42);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    // Create a temp services.yml with calls and tags.
    $tmpDir = sys_get_temp_dir() . '/dkan_mcp_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/testmod.services.yml', <<<YAML
services:
  dkan.test.service:
    class: Some\\Class
    arguments:
      - '@some.dep'
    calls:
      - [setStorage, ['@storage.service']]
    tags:
      - { name: event_subscriber }
YAML
    );

    $ext = $this->createMock(Extension::class);
    $ext->method('getPath')->willReturn($tmpDir);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('getModuleList')->willReturn(['testmod' => $ext]);

    $tools = $this->createTools($container, $moduleHandler);
    $result = $tools->getServiceInfo('dkan.test.service');

    $this->assertArrayHasKey('yaml_definition', $result);
    $this->assertEquals(['@some.dep'], $result['yaml_definition']['arguments']);
    $this->assertEquals([['setStorage', ['@storage.service']]], $result['yaml_definition']['calls']);
    $this->assertEquals([['name' => 'event_subscriber']], $result['yaml_definition']['tags']);

    // Cleanup.
    unlink($tmpDir . '/testmod.services.yml');
    rmdir($tmpDir);
  }

  public function testGetServiceInfoNoYamlDefinitionWhenNotFound(): void {
    $service = new ServiceToolsTestDouble('hello', 42);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('getModuleList')->willReturn([]);

    $tools = $this->createTools($container, $moduleHandler);
    $result = $tools->getServiceInfo('dkan.test.service');

    $this->assertArrayNotHasKey('yaml_definition', $result);
  }

  public function testListServicesBrief(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('getServiceIds')->willReturn([
      'dkan.metastore.service',
      'dkan.datastore.service',
      'some.other.service',
    ]);

    $tools = $this->createTools($container);
    $result = $tools->listServices(NULL, TRUE);

    $this->assertEquals(2, $result['total']);
    $this->assertContains('dkan.metastore.service', $result['services']);
    $this->assertContains('dkan.datastore.service', $result['services']);
    // Brief mode returns plain strings, not arrays with 'id'/'class'.
    $this->assertIsString($result['services'][0]);
  }

  public function testGetServiceInfoMethodsFilter(): void {
    $service = new ServiceToolsTestDouble('hello', 42);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container);
    $result = $tools->getServiceInfo('dkan.test.service', 'get*');

    $methodNames = array_column($result['methods'], 'name');
    $this->assertContains('getCount', $methodNames);
    $this->assertNotContains('doSomething', $methodNames);
  }

  public function testGetServiceInfoMultiplePatterns(): void {
    $service = new ServiceToolsTestDouble('hello', 42);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container);
    $result = $tools->getServiceInfo('dkan.test.service', 'get*, do*');

    $methodNames = array_column($result['methods'], 'name');
    $this->assertContains('getCount', $methodNames);
    $this->assertContains('doSomething', $methodNames);
  }

  public function testGetServiceInfoExcludeYaml(): void {
    $service = new ServiceToolsTestDouble('hello', 42);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    // Set up a module handler that would return YAML.
    $tmpDir = sys_get_temp_dir() . '/dkan_mcp_test_' . uniqid();
    mkdir($tmpDir);
    file_put_contents($tmpDir . '/testmod.services.yml', "services:\n  dkan.test.service:\n    class: Some\\Class\n    arguments: ['@dep']\n");

    $ext = $this->createMock(\Drupal\Core\Extension\Extension::class);
    $ext->method('getPath')->willReturn($tmpDir);

    $moduleHandler = $this->createMock(\Drupal\Core\Extension\ModuleHandlerInterface::class);
    $moduleHandler->method('getModuleList')->willReturn(['testmod' => $ext]);

    $tools = $this->createTools($container, $moduleHandler);

    // With includeYaml=FALSE, yaml_definition should not appear.
    $result = $tools->getServiceInfo('dkan.test.service', NULL, FALSE);
    $this->assertArrayNotHasKey('yaml_definition', $result);

    // With includeYaml=TRUE (default), it should appear.
    $result = $tools->getServiceInfo('dkan.test.service', NULL, TRUE);
    $this->assertArrayHasKey('yaml_definition', $result);

    unlink($tmpDir . '/testmod.services.yml');
    rmdir($tmpDir);
  }

  public function testGetClassInfoMethodsFilter(): void {
    $container = $this->createMock(ContainerInterface::class);
    $tools = $this->createTools($container);
    $result = $tools->getClassInfo(ClassInfoTestDouble::class, 'own*');

    $methodNames = array_column($result['methods'], 'name');
    $this->assertContains('ownMethod', $methodNames);
    $this->assertNotContains('interfaceMethod', $methodNames);
  }

  public function testDiscoverApi(): void {
    $service = new ServiceToolsTestDouble('hello', 42);

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container);
    $result = $tools->discoverApi('dkan.test.service');

    $this->assertArrayHasKey('service', $result);
    $this->assertEquals('dkan.test.service', $result['service']['service_id']);
    // No YAML in discover mode.
    $this->assertArrayNotHasKey('yaml_definition', $result['service']);
  }

  public function testDiscoverApiWithMethod(): void {
    $service = new DiscoverApiTestService();

    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(TRUE);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container);
    $result = $tools->discoverApi('dkan.test.service', 'getWidget', 1);

    $this->assertArrayHasKey('service', $result);
    // Should have followed return type.
    $this->assertArrayHasKey('return_types', $result);
    $this->assertArrayHasKey(DiscoverApiTestWidget::class, $result['return_types']);

    $widgetInfo = $result['return_types'][DiscoverApiTestWidget::class];
    $methodNames = array_column($widgetInfo['methods'], 'name');
    $this->assertContains('getName', $methodNames);
  }

  public function testDiscoverApiServiceNotFound(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('has')->willReturn(FALSE);

    $tools = $this->createTools($container);
    $result = $tools->discoverApi('nonexistent.service');

    $this->assertArrayHasKey('error', $result);
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

/**
 * Interface for getClassInfo tests.
 */
interface ClassInfoTestInterface {

  public function interfaceMethod(): string;

}

/**
 * Test double for getClassInfo tests.
 */
class ClassInfoTestDouble implements ClassInfoTestInterface {

  public function interfaceMethod(): string {
    return 'interface';
  }

  public function ownMethod(string $input): array {
    return [];
  }

}

/**
 * Test double for discoverApi return type following.
 */
class DiscoverApiTestService {

  public function getWidget(): DiscoverApiTestWidget {
    return new DiscoverApiTestWidget();
  }

  public function getCount(): int {
    return 0;
  }

}

/**
 * Return type class for discoverApi tests.
 */
class DiscoverApiTestWidget {

  public function getName(): string {
    return 'widget';
  }

  public function getValue(): int {
    return 42;
  }

}
