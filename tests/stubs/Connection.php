<?php

namespace Drupal\Core\Database;

use Drupal\Core\Database\Query\SelectInterface;

/**
 * Stub for Drupal\Core\Database\Connection.
 */
abstract class Connection {

  /**
   * Select.
   */
  public function select(string $table, ?string $alias = NULL, array $options = []): SelectInterface {
    return new class implements SelectInterface {

      /**
       * Fields.
       */
      public function fields(string $table_alias, array $fields = []): SelectInterface {
        return $this;
      }

      /**
       * Condition.
       */
      public function condition(string $field, $value = NULL, ?string $operator = NULL): SelectInterface {
        return $this;
      }

      /**
       * Order by.
       */
      public function orderBy(string $field, string $direction = 'ASC'): SelectInterface {
        return $this;
      }

      /**
       * Range.
       */
      public function range(?int $start = NULL, ?int $length = NULL): SelectInterface {
        return $this;
      }

      /**
       * Execute.
       */
      public function execute(): StatementInterface {
        return new class implements StatementInterface {

          /**
           * Fetch field.
           */
          public function fetchField(int $index = 0): mixed {
            return 0;
          }

          /**
           * Fetch assoc.
           */
          public function fetchAssoc(): array|false {
            return [];
          }

          /**
           * Get iterator.
           */
          public function getIterator(): \ArrayIterator {
            return new \ArrayIterator([]);
          }

        };
      }

      /**
       * Count query.
       */
      public function countQuery(): SelectInterface {
        return $this;
      }

      /**
       * Fetch field.
       */
      public function fetchField(int $index = 0): mixed {
        return 0;
      }

      /**
       * Add expression.
       */
      public function addExpression(string $expression, ?string $alias = NULL, array $arguments = []): SelectInterface {
        return $this;
      }

      /**
       * Add field.
       */
      public function addField(string $table_alias, string $field, ?string $alias = NULL): SelectInterface {
        return $this;
      }

      /**
       * Group by.
       */
      public function groupBy(string $field): SelectInterface {
        return $this;
      }

    };
  }

  /**
   * Schema.
   */
  public function schema(): Schema {
    return new Schema();
  }

}
