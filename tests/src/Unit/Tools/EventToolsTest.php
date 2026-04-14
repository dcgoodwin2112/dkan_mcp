<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\dkan_mcp\Tools\EventTools;
use PHPUnit\Framework\TestCase;
use Drupal\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EventToolsTest extends TestCase {

  protected function createTools(
    ContainerInterface $container,
    EventDispatcherInterface $dispatcher,
  ): EventTools {
    return new EventTools($container, $dispatcher);
  }

  protected function createDefaultMocks(): array {
    $container = $this->createMock(ContainerInterface::class);
    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    return [$container, $dispatcher];
  }

  public function testListEventsDiscoversFromServices(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->listEvents();

    $constants = array_column($result['events'], 'constant');
    $this->assertContains('EVENT_SOMETHING', $constants);
    $this->assertContains('EVENT_OTHER', $constants);
    $this->assertNotContains('NOT_AN_EVENT', $constants);
    $this->assertEquals(2, $result['total']);
  }

  public function testListEventsFiltersByModule(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $serviceA = new EventToolsTestServiceA();
    $serviceB = new EventToolsTestServiceB();

    $container->method('getServiceIds')->willReturn(['dkan.test.a', 'dkan.test.b']);
    $container->method('get')->willReturnMap([
      ['dkan.test.a', $serviceA],
      ['dkan.test.b', $serviceB],
    ]);

    $tools = $this->createTools($container, $dispatcher);

    // All test doubles are in Drupal\Tests\... namespace, so module = "Tests".
    // ServiceA has 2 events, ServiceB has 1 — but all share module "Tests".
    $result = $tools->listEvents('Tests');
    $this->assertEquals(3, $result['total']);

    // Filter by nonexistent module returns empty.
    $result = $tools->listEvents('nonexistent');
    $this->assertEquals(0, $result['total']);
  }

  public function testListEventsSortedByName(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->listEvents();

    $names = array_column($result['events'], 'event_name');
    $sorted = $names;
    sort($sorted);
    $this->assertEquals($sorted, $names);
  }

  public function testListEventsHandlesServiceException(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();

    $container->method('getServiceIds')->willReturn(['dkan.broken.service']);
    $container->method('get')->willThrowException(new \RuntimeException('Broken'));

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->listEvents();

    $this->assertEquals(0, $result['total']);
    $this->assertEmpty($result['events']);
  }

  public function testListEventsDeduplicates(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a', 'dkan.test.a2']);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->listEvents();

    $names = array_column($result['events'], 'event_name');
    $this->assertEquals(array_values(array_unique($names)), $names);
  }

  public function testGetEventInfoReturnsSubscribers(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $subscriber = new EventToolsTestSubscriber();
    $dispatcher->method('getListeners')
      ->with('test_event_something')
      ->willReturn([[$subscriber, 'onSomething']]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('test_event_something');

    $this->assertEquals('test_event_something', $result['event_name']);
    $this->assertEquals('EVENT_SOMETHING', $result['constant']);
    $this->assertCount(1, $result['subscribers']);
    $this->assertEquals(EventToolsTestSubscriber::class, $result['subscribers'][0]['class']);
    $this->assertEquals('onSomething', $result['subscribers'][0]['method']);
  }

  public function testGetEventInfoNotFound(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $container->method('getServiceIds')->willReturn([]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('nonexistent_event');

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('not found', $result['error']);
  }

  public function testGetEventInfoNoSubscribers(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $dispatcher->method('getListeners')->willReturn([]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('test_event_something');

    $this->assertEmpty($result['subscribers']);
  }

  public function testExtractModule(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->listEvents();

    // Test doubles are in Drupal\Tests\... namespace.
    foreach ($result['events'] as $event) {
      $this->assertEquals('Tests', $event['module']);
    }
  }

  public function testGetEventInfoIncludesEventClass(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $subscriber = new EventToolsTypedSubscriber();
    $dispatcher->method('getListeners')
      ->with('test_event_something')
      ->willReturn([[$subscriber, 'onSomething']]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('test_event_something');

    $this->assertArrayHasKey('event_class', $result);
    $this->assertEquals(EventToolsTestEvent::class, $result['event_class']);
    $this->assertArrayHasKey('event_methods', $result);
    $methodNames = array_column($result['event_methods'], 'name');
    $this->assertContains('getData', $methodNames);
    $this->assertContains('getResourceId', $methodNames);
  }

  public function testGetEventInfoNoEventClassWhenUntypedSubscriber(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $subscriber = new EventToolsTestSubscriber();
    $dispatcher->method('getListeners')
      ->with('test_event_something')
      ->willReturn([[$subscriber, 'onSomething']]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('test_event_something');

    $this->assertArrayNotHasKey('event_class', $result);
    $this->assertArrayNotHasKey('event_methods', $result);
  }

  public function testGetEventInfoIncludesDispatchPayload(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestPayloadService();

    $container->method('getServiceIds')->willReturn(['dkan.test.payload']);
    $container->method('get')->willReturn($service);

    $subscriber = new EventToolsTestSubscriber();
    $dispatcher->method('getListeners')
      ->with('dkan_metastore_dataset_update')
      ->willReturn([[$subscriber, 'onSomething']]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('dkan_metastore_dataset_update');

    $this->assertArrayHasKey('dispatch_payload', $result);
    $this->assertEquals(
      'Drupal\metastore\MetastoreItemInterface',
      $result['dispatch_payload']['type']
    );
    $this->assertEquals(
      'Drupal\metastore\LifeCycle\LifeCycle::datasetUpdate',
      $result['dispatch_payload']['dispatch_site']
    );
    $this->assertArrayHasKey('methods', $result['dispatch_payload']);
  }

  public function testGetEventInfoNoDispatchPayloadForUnmappedEvent(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $dispatcher->method('getListeners')->willReturn([]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('test_event_something');

    $this->assertArrayNotHasKey('dispatch_payload', $result);
  }

  public function testListEventsBrief(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->listEvents(NULL, TRUE);

    $this->assertEquals(2, $result['total']);
    // Brief mode returns plain string event names.
    $this->assertIsString($result['events'][0]);
    $this->assertContains('test_event_something', $result['events']);
    $this->assertContains('test_event_other', $result['events']);
  }

  public function testGetEventInfoFields(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);
    $dispatcher->method('getListeners')->willReturn([]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('test_event_something', 'constant,module');

    $this->assertArrayHasKey('constant', $result);
    $this->assertArrayHasKey('module', $result);
    $this->assertEquals('EVENT_SOMETHING', $result['constant']);
    // Fields not in the filter should be excluded.
    $this->assertArrayNotHasKey('event_name', $result);
    $this->assertArrayNotHasKey('declaring_class', $result);
    $this->assertArrayNotHasKey('subscribers', $result);
  }

  public function testGetEventInfoFieldsOmittedReturnsAll(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestServiceA();

    $container->method('getServiceIds')->willReturn(['dkan.test.a']);
    $container->method('get')->willReturn($service);
    $dispatcher->method('getListeners')->willReturn([]);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->getEventInfo('test_event_something');

    // Without fields filter, all keys should be present.
    $this->assertArrayHasKey('constant', $result);
    $this->assertArrayHasKey('event_name', $result);
    $this->assertArrayHasKey('declaring_class', $result);
    $this->assertArrayHasKey('subscribers', $result);
  }

  public function testInheritedConstantsExcluded(): void {
    [$container, $dispatcher] = $this->createDefaultMocks();
    $service = new EventToolsTestChildService();

    $container->method('getServiceIds')->willReturn(['dkan.test.child']);
    $container->method('get')->willReturn($service);

    $tools = $this->createTools($container, $dispatcher);
    $result = $tools->listEvents();

    $constants = array_column($result['events'], 'constant');
    $this->assertContains('EVENT_CHILD_THING', $constants);
    $this->assertNotContains('EVENT_SOMETHING', $constants);
    $this->assertNotContains('EVENT_OTHER', $constants);
  }

}

// Test doubles — all in same namespace for autoloading.

class EventToolsTestServiceA {

  const EVENT_SOMETHING = 'test_event_something';
  const EVENT_OTHER = 'test_event_other';
  const NOT_AN_EVENT = 'not_event';

}

class EventToolsTestServiceB {

  const EVENT_B_THING = 'test_event_b_thing';

}

class EventToolsTestChildService extends EventToolsTestServiceA {

  const EVENT_CHILD_THING = 'test_event_child';

}

class EventToolsTestSubscriber {

  public function onSomething(): void {}

}

class EventToolsTestEvent {

  public function getData(): array {
    return [];
  }

  public function getResourceId(): string {
    return '';
  }

}

class EventToolsTestPayloadService {

  const EVENT_DATASET_UPDATE = 'dkan_metastore_dataset_update';

}

class EventToolsTypedSubscriber {

  public function onSomething(EventToolsTestEvent $event): void {}

}
