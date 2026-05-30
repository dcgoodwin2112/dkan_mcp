<?php

namespace Drupal\Tests\dkan_mcp\Unit\Controller;

use Drupal\dkan_mcp\Controller\McpController;
use Drupal\dkan_mcp\Server\McpServerFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests the HTTP controller's tool-group subset.
 *
 * The controller bridges Symfony/PSR-7 and is not unit-testable without a
 * Drupal kernel. This test guards the one invariant that matters without a
 * running request: the HTTP transport exposes only read-only data-consumer
 * groups and never write tools.
 */
class McpControllerTest extends TestCase {

  /**
   * The read-only groups the HTTP endpoint is expected to expose.
   */
  private const EXPECTED_HTTP_GROUPS = [
    'metastore',
    'datastore',
    'search',
    'harvest_read',
    'resource',
    'status',
  ];

  /**
   * Groups that must never be reachable over HTTP.
   */
  private const FORBIDDEN_HTTP_GROUPS = [
    'write',
    'harvest_write',
  ];

  /**
   * Reads the private HTTP_TOOL_GROUPS constant via reflection.
   */
  protected function httpGroups(): array {
    return (new \ReflectionClass(McpController::class))->getConstant('HTTP_TOOL_GROUPS');
  }

  /**
   * The HTTP subset is exactly the expected read-only groups.
   */
  public function testHttpGroupsMatchExpected(): void {
    $groups = $this->httpGroups();
    sort($groups);
    $expected = self::EXPECTED_HTTP_GROUPS;
    sort($expected);
    $this->assertSame($expected, $groups);
  }

  /**
   * No write groups are exposed over HTTP.
   */
  public function testHttpExposesNoWriteGroups(): void {
    foreach (self::FORBIDDEN_HTTP_GROUPS as $forbidden) {
      $this->assertNotContains($forbidden, $this->httpGroups());
    }
  }

  /**
   * Every HTTP group is a real tool group known to the factory.
   */
  public function testHttpGroupsAreKnownToFactory(): void {
    $known = array_keys(
      (new \ReflectionClass(McpServerFactory::class))->getConstant('TOOL_GROUPS')
    );
    foreach ($this->httpGroups() as $group) {
      $this->assertContains($group, $known);
    }
  }

}
