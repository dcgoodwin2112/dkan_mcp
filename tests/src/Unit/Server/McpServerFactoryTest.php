<?php

namespace Drupal\Tests\dkan_mcp\Unit\Server;

use Drupal\dkan_mcp\Server\McpServerFactory;
use Drupal\dkan_mcp\Tools\HarvestTools;
use Drupal\dkan_mcp\Tools\ResourceTools;
use Drupal\dkan_mcp\Tools\StatusTools;
use Drupal\dkan_mcp\Tools\WriteTools;
use Drupal\dkan_query_tools\Tool\DatastoreTools;
use Drupal\dkan_query_tools\Tool\MetastoreTools;
use Drupal\dkan_query_tools\Tool\SearchTools;
use Mcp\Server;
use PHPUnit\Framework\TestCase;

/**
 * Tests tool-group registration in McpServerFactory.
 *
 * These are structural/smoke tests: they guard the declarative TOOL_GROUPS
 * spec (the Tier 1B reduction) and verify that every group builds an MCP server
 * without throwing. Exact per-tool counts are verified against the live
 * transports rather than the SDK's internal registry, which is not cleanly
 * introspectable.
 */
class McpServerFactoryTest extends TestCase {

  /**
   * Groups expected after the Tier 1B reduction.
   */
  private const EXPECTED_GROUPS = [
    'metastore',
    'metastore_dev',
    'datastore',
    'search',
    'harvest_read',
    'harvest_write',
    'resource',
    'write',
    'status',
  ];

  /**
   * Groups removed in Tier 1B that must no longer be registered.
   */
  private const REMOVED_GROUPS = [
    'service',
    'event',
    'permission',
    'drupal',
    'log',
  ];

  /**
   * Tool groups exposed over HTTP (read-only subset).
   */
  private const HTTP_GROUPS = [
    'metastore',
    'datastore',
    'search',
    'harvest_read',
    'resource',
    'status',
  ];

  /**
   * Builds a factory with mocked tool services.
   */
  protected function createFactory(): McpServerFactory {
    return new McpServerFactory(
      $this->createMock(MetastoreTools::class),
      $this->createMock(DatastoreTools::class),
      $this->createMock(SearchTools::class),
      $this->createMock(HarvestTools::class),
      $this->createMock(ResourceTools::class),
      $this->createMock(WriteTools::class),
      $this->createMock(StatusTools::class),
    );
  }

  /**
   * Reads the private TOOL_GROUPS constant via reflection.
   */
  protected function toolGroups(): array {
    return (new \ReflectionClass(McpServerFactory::class))->getConstant('TOOL_GROUPS');
  }

  /**
   * The TOOL_GROUPS map contains exactly the expected groups.
   */
  public function testToolGroupsMatchExpected(): void {
    $groups = array_keys($this->toolGroups());
    sort($groups);
    $expected = self::EXPECTED_GROUPS;
    sort($expected);
    $this->assertSame($expected, $groups);
  }

  /**
   * Removed groups are no longer present in the map.
   */
  public function testRemovedGroupsAbsent(): void {
    $groups = array_keys($this->toolGroups());
    foreach (self::REMOVED_GROUPS as $removed) {
      $this->assertNotContains($removed, $groups);
    }
  }

  /**
   * Every tool spec names an existing handler class + method, plus a name.
   */
  public function testEverySpecResolvesToHandler(): void {
    $names = [];
    foreach ($this->toolGroups() as $group => $specs) {
      $this->assertNotEmpty($specs, "Group '{$group}' has no tool specs.");
      foreach ($specs as $spec) {
        $this->assertArrayHasKey('name', $spec);
        $this->assertArrayHasKey('class', $spec);
        $this->assertArrayHasKey('method', $spec);
        $this->assertArrayHasKey('readOnly', $spec);
        $this->assertArrayHasKey('description', $spec);
        $this->assertTrue(
          method_exists($spec['class'], $spec['method']),
          "Tool '{$spec['name']}' maps to missing method {$spec['class']}::{$spec['method']}()."
        );
        $names[] = $spec['name'];
      }
    }
    // Tool names are unique across all groups.
    $this->assertSame($names, array_unique($names));
    // Tier 1B reduced the surface to 35 tools.
    $this->assertCount(35, $names);
  }

  /**
   * Builds a server with all groups when no argument is given.
   */
  public function testCreateAllGroups(): void {
    $this->assertInstanceOf(Server::class, $this->createFactory()->create());
  }

  /**
   * Builds a server from the HTTP subset of groups.
   */
  public function testCreateHttpSubset(): void {
    $server = $this->createFactory()->create(self::HTTP_GROUPS);
    $this->assertInstanceOf(Server::class, $server);
  }

  /**
   * Builds a server with no tools for an empty group list.
   */
  public function testCreateEmptyGroups(): void {
    $this->assertInstanceOf(Server::class, $this->createFactory()->create([]));
  }

  /**
   * Unknown groups are silently ignored.
   */
  public function testCreateUnknownGroupIgnored(): void {
    $server = $this->createFactory()->create(['does_not_exist']);
    $this->assertInstanceOf(Server::class, $server);
  }

  /**
   * Each group builds in isolation, exercising its register method.
   */
  public function testEachGroupBuildsIndividually(): void {
    $factory = $this->createFactory();
    foreach (array_keys($this->toolGroups()) as $group) {
      $this->assertInstanceOf(
        Server::class,
        $factory->create([$group]),
        "Group '{$group}' failed to build."
      );
    }
  }

}
