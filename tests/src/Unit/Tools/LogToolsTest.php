<?php

namespace Drupal\Tests\dkan_mcp\Unit\Tools;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\dkan_mcp\Tools\LogTools;
use PHPUnit\Framework\TestCase;

class LogToolsTest extends TestCase {

  protected function createTools(?Connection $connection = NULL): LogTools {
    $connection = $connection ?? $this->createConnectionMock();
    return new LogTools($connection);
  }

  protected function createConnectionMock(
    bool $tableExists = TRUE,
    array $rows = [],
    int $total = 0,
  ): Connection {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->with('watchdog')->willReturn($tableExists);

    // Count query result: needs to be Traversable with fetchField().
    $countResult = $this->createMock(StatementInterface::class);
    $countResult->method('fetchField')->willReturn($total);

    // Count query mock.
    $countQuery = $this->createMock(SelectInterface::class);
    $countQuery->method('execute')->willReturn($countResult);

    // Main query mock.
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('addField')->willReturnSelf();
    $select->method('groupBy')->willReturnSelf();
    $select->method('countQuery')->willReturn($countQuery);

    // Main query result: iterable StatementInterface.
    $mainResult = $this->createMock(StatementInterface::class);
    $mainResult->method('getIterator')->willReturn(new \ArrayIterator($rows));
    $select->method('execute')->willReturn($mainResult);

    $connection = $this->createMock(Connection::class);
    $connection->method('schema')->willReturn($schema);
    $connection->method('select')->willReturn($select);

    return $connection;
  }

  protected function makeRow(array $overrides = []): object {
    return (object) array_merge([
      'wid' => 1,
      'uid' => 0,
      'type' => 'system',
      'message' => 'Test message',
      'variables' => serialize([]),
      'severity' => 6,
      'location' => 'http://example.com',
      'timestamp' => 1710600000,
    ], $overrides);
  }

  public function testGetRecentLogsBasic(): void {
    $rows = [
      $this->makeRow(['wid' => 2, 'type' => 'dkan', 'severity' => 3]),
      $this->makeRow(['wid' => 1, 'type' => 'php', 'severity' => 4]),
    ];

    $tools = $this->createTools($this->createConnectionMock(rows: $rows, total: 2));
    $result = $tools->getRecentLogs();

    $this->assertCount(2, $result['entries']);
    $this->assertEquals(2, $result['total']);
    $this->assertEquals(25, $result['limit']);
    $this->assertEquals(0, $result['offset']);

    $entry = $result['entries'][0];
    $this->assertArrayHasKey('wid', $entry);
    $this->assertArrayHasKey('type', $entry);
    $this->assertArrayHasKey('severity', $entry);
    $this->assertArrayHasKey('severity_label', $entry);
    $this->assertArrayHasKey('message', $entry);
    $this->assertArrayHasKey('timestamp', $entry);
    $this->assertArrayHasKey('location', $entry);
    $this->assertArrayHasKey('uid', $entry);
  }

  public function testGetRecentLogsFilterByType(): void {
    $countResult = $this->createMock(StatementInterface::class);
    $countResult->method('fetchField')->willReturn(0);
    $countQuery = $this->createMock(SelectInterface::class);
    $countQuery->method('execute')->willReturn($countResult);

    $mainResult = $this->createMock(StatementInterface::class);
    $mainResult->method('getIterator')->willReturn(new \ArrayIterator([]));

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($mainResult);
    $select->method('countQuery')->willReturn($countQuery);

    // Verify condition is called with type.
    $select->expects($this->once())
      ->method('condition')
      ->with('w.type', 'dkan')
      ->willReturnSelf();

    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);

    $connection = $this->createMock(Connection::class);
    $connection->method('schema')->willReturn($schema);
    $connection->method('select')->willReturn($select);

    $tools = new LogTools($connection);
    $tools->getRecentLogs(type: 'dkan');
  }

  public function testGetRecentLogsFilterBySeverity(): void {
    $countResult = $this->createMock(StatementInterface::class);
    $countResult->method('fetchField')->willReturn(0);
    $countQuery = $this->createMock(SelectInterface::class);
    $countQuery->method('execute')->willReturn($countResult);

    $mainResult = $this->createMock(StatementInterface::class);
    $mainResult->method('getIterator')->willReturn(new \ArrayIterator([]));

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($mainResult);
    $select->method('countQuery')->willReturn($countQuery);

    // Verify severity condition uses <= operator.
    $select->expects($this->once())
      ->method('condition')
      ->with('w.severity', 3, '<=')
      ->willReturnSelf();

    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);

    $connection = $this->createMock(Connection::class);
    $connection->method('schema')->willReturn($schema);
    $connection->method('select')->willReturn($select);

    $tools = new LogTools($connection);
    $tools->getRecentLogs(severity: 3);
  }

  public function testGetRecentLogsLimitClamped(): void {
    $tools = $this->createTools($this->createConnectionMock(rows: [], total: 0));
    $result = $tools->getRecentLogs(limit: 200);

    $this->assertEquals(100, $result['limit']);
  }

  public function testGetRecentLogsFormatsMessage(): void {
    $row = $this->makeRow([
      'message' => 'Cannot find @id',
      'variables' => serialize(['@id' => 'abc123']),
    ]);

    $tools = $this->createTools($this->createConnectionMock(rows: [$row], total: 1));
    $result = $tools->getRecentLogs();

    $this->assertEquals('Cannot find abc123', $result['entries'][0]['message']);
  }

  public function testGetRecentLogsCorruptVariables(): void {
    $row = $this->makeRow([
      'message' => 'Raw message here',
      'variables' => 'not_serialized_data',
    ]);

    $tools = $this->createTools($this->createConnectionMock(rows: [$row], total: 1));
    $result = $tools->getRecentLogs();

    $this->assertEquals('Raw message here', $result['entries'][0]['message']);
  }

  public function testGetRecentLogsVariablesWithObjects(): void {
    // Simulate variables containing serialized objects (which unserialize
    // with allowed_classes: FALSE produces __PHP_Incomplete_Class).
    $row = $this->makeRow([
      'message' => '@obj caused an error',
      'variables' => serialize(['@obj' => new \stdClass()]),
    ]);

    $tools = $this->createTools($this->createConnectionMock(rows: [$row], total: 1));
    $result = $tools->getRecentLogs();

    // Should not crash — non-string values are filtered out, raw message used.
    $this->assertEquals('@obj caused an error', $result['entries'][0]['message']);
  }

  public function testGetRecentLogsDblogNotEnabled(): void {
    $tools = $this->createTools($this->createConnectionMock(tableExists: FALSE));
    $result = $tools->getRecentLogs();

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('dblog', $result['error']);
  }

  public function testGetRecentLogsEmpty(): void {
    $tools = $this->createTools($this->createConnectionMock(rows: [], total: 0));
    $result = $tools->getRecentLogs();

    $this->assertEquals([], $result['entries']);
    $this->assertEquals(0, $result['total']);
  }

  public function testGetLogTypesBasic(): void {
    $rows = [
      (object) ['type' => 'dkan', 'entry_count' => 42],
      (object) ['type' => 'php', 'entry_count' => 15],
    ];

    $tools = $this->createTools($this->createConnectionMock(rows: $rows));
    $result = $tools->getLogTypes();

    $this->assertCount(2, $result['types']);
    $this->assertEquals('dkan', $result['types'][0]['type']);
    $this->assertEquals(42, $result['types'][0]['count']);
    $this->assertEquals('php', $result['types'][1]['type']);
    $this->assertEquals(15, $result['types'][1]['count']);
  }

  public function testGetLogTypesDblogNotEnabled(): void {
    $tools = $this->createTools($this->createConnectionMock(tableExists: FALSE));
    $result = $tools->getLogTypes();

    $this->assertArrayHasKey('error', $result);
    $this->assertStringContainsString('dblog', $result['error']);
  }

  public function testGetLogTypesEmpty(): void {
    $tools = $this->createTools($this->createConnectionMock(rows: []));
    $result = $tools->getLogTypes();

    $this->assertEquals(['types' => []], $result);
  }

}
