<?php

namespace Drupal\Core\Database\Query;

use Drupal\Core\Database\StatementInterface;

/**
 * Stub for Drupal\Core\Database\Query\SelectInterface.
 */
interface SelectInterface {

  /**
   * Fields.
   */
  public function fields(string $table_alias, array $fields = []): SelectInterface;

  /**
   * Condition.
   */
  public function condition(string $field, $value = NULL, ?string $operator = NULL): SelectInterface;

  /**
   * Order by.
   */
  public function orderBy(string $field, string $direction = 'ASC'): SelectInterface;

  /**
   * Range.
   */
  public function range(?int $start = NULL, ?int $length = NULL): SelectInterface;

  /**
   * Execute.
   */
  public function execute(): StatementInterface;

  /**
   * Count query.
   */
  public function countQuery(): SelectInterface;

  /**
   * Fetch field.
   */
  public function fetchField(int $index = 0): mixed;

  /**
   * Add expression.
   */
  public function addExpression(string $expression, ?string $alias = NULL, array $arguments = []): SelectInterface;

  /**
   * Add field.
   */
  public function addField(string $table_alias, string $field, ?string $alias = NULL): SelectInterface;

  /**
   * Group by.
   */
  public function groupBy(string $field): SelectInterface;

}
