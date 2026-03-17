<?php

namespace Drupal\dkan_mcp\Tools;

use Drupal\Core\Database\Connection;

/**
 * MCP tools for accessing Drupal watchdog logs.
 */
class LogTools {

  /**
   * Severity level labels matching RFC 5424.
   */
  protected const SEVERITY_LABELS = [
    0 => 'Emergency',
    1 => 'Alert',
    2 => 'Critical',
    3 => 'Error',
    4 => 'Warning',
    5 => 'Notice',
    6 => 'Info',
    7 => 'Debug',
  ];

  public function __construct(
    protected Connection $database,
  ) {}

  /**
   * Get recent watchdog log entries with optional filters.
   */
  public function getRecentLogs(
    ?string $type = NULL,
    ?int $severity = NULL,
    int $limit = 25,
    int $offset = 0,
  ): array {
    try {
      if ($error = $this->checkWatchdogTable()) {
        return $error;
      }

      $limit = max(1, min($limit, 100));

      $query = $this->database->select('watchdog', 'w')
        ->fields('w', [
          'wid', 'uid', 'type', 'message', 'variables',
          'severity', 'location', 'timestamp',
        ]);

      if ($type !== NULL) {
        $query->condition('w.type', $type);
      }
      if ($severity !== NULL) {
        $query->condition('w.severity', $severity, '<=');
      }

      // Clone before adding range for count query.
      $countQuery = $query->countQuery();
      $total = (int) $countQuery->execute()->fetchField();

      $query->orderBy('w.wid', 'DESC')
        ->range($offset, $limit);

      $entries = [];
      foreach ($query->execute() as $row) {
        $entries[] = $this->formatEntry($row);
      }

      return [
        'entries' => $entries,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
      ];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * List distinct log types with entry counts.
   */
  public function getLogTypes(): array {
    try {
      if ($error = $this->checkWatchdogTable()) {
        return $error;
      }

      $query = $this->database->select('watchdog', 'w');
      $query->addField('w', 'type');
      $query->addExpression('COUNT(*)', 'entry_count');
      $query->groupBy('w.type');
      $query->orderBy('entry_count', 'DESC');

      $types = [];
      foreach ($query->execute() as $row) {
        $types[] = [
          'type' => $row->type,
          'count' => (int) $row->entry_count,
        ];
      }

      return ['types' => $types];
    }
    catch (\Exception $e) {
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * Format a watchdog row into a readable entry.
   */
  protected function formatEntry(object $row): array {
    // phpcs:ignore -- Watchdog variables are serialized string arrays.
    $variables = @unserialize($row->variables, ['allowed_classes' => FALSE]);
    if (!is_array($variables)) {
      $variables = [];
    }
    // Filter out non-string values (e.g., __PHP_Incomplete_Class from
    // serialized objects) that would crash strtr().
    $variables = array_filter($variables, 'is_string');

    $message = $variables
      ? strtr($row->message, $variables)
      : $row->message;

    return [
      'wid' => (int) $row->wid,
      'type' => $row->type,
      'severity' => (int) $row->severity,
      'severity_label' => self::SEVERITY_LABELS[(int) $row->severity] ?? 'Unknown',
      'message' => $message,
      'timestamp' => (int) $row->timestamp,
      'location' => $row->location,
      'uid' => (int) $row->uid,
    ];
  }

  /**
   * Check if the watchdog table exists.
   *
   * @return array|null
   *   Error array if table doesn't exist, NULL if it does.
   */
  protected function checkWatchdogTable(): ?array {
    if (!$this->database->schema()->tableExists('watchdog')) {
      return ['error' => 'The dblog module is not enabled. Enable it with: drush en dblog'];
    }
    return NULL;
  }

}
