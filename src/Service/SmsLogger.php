<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Writes SMS log entries to the kwtsms_sms_log table and to Drupal watchdog.
 *
 * Every outgoing SMS send should call logSend() to record the result.
 * Debug, info, and error methods wrap Drupal's logger channel and respect the
 * debug_logging configuration flag for debug-level messages.
 */
class SmsLogger {

  /**
   * Constructs an SmsLogger instance.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The kwtsms logger channel.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Inserts one row into kwtsms_sms_log.
   *
   * Required keys in $data: recipient, message, sender_id, status.
   * All other keys are optional and fall back to schema defaults.
   * The api_response value, if present, has username and password stripped.
   *
   * @param array $data
   *   Associative array of column values to store.
   */
  public function logSend(array $data): void {
    $defaults = [
      'template_id'       => NULL,
      'direction'         => 'outgoing',
      'msg_id'            => NULL,
      'api_response'      => NULL,
      'error_code'        => NULL,
      'error_description' => NULL,
      'points_charged'    => 0,
      'balance_after'     => 0,
      'test_mode'         => 0,
      'event_type'        => 'manual',
      'uid'               => 0,
      'created'           => \Drupal::time()->getRequestTime(),
    ];

    $row = array_merge($defaults, $data);

    if (isset($row['api_response']) && $row['api_response'] !== NULL) {
      $row['api_response'] = $this->stripCredentials((string) $row['api_response']);
    }

    $this->database->insert('kwtsms_sms_log')
      ->fields($row)
      ->execute();
  }

  /**
   * Logs a debug message to watchdog if debug_logging is enabled.
   *
   * @param string $message
   *   The log message, optionally with @placeholder tokens.
   * @param array $context
   *   Contextual data for message placeholders.
   */
  public function debug(string $message, array $context = []): void {
    $debugEnabled = (bool) $this->configFactory
      ->get('kwtsms.settings')
      ->get('debug_logging');

    if ($debugEnabled) {
      $this->logger->debug($message, $context);
    }
  }

  /**
   * Logs an informational message to watchdog.
   *
   * @param string $message
   *   The log message, optionally with @placeholder tokens.
   * @param array $context
   *   Contextual data for message placeholders.
   */
  public function info(string $message, array $context = []): void {
    $this->logger->info($message, $context);
  }

  /**
   * Logs an error message to watchdog.
   *
   * @param string $message
   *   The log message, optionally with @placeholder tokens.
   * @param array $context
   *   Contextual data for message placeholders.
   */
  public function error(string $message, array $context = []): void {
    $this->logger->error($message, $context);
  }

  /**
   * Returns log rows from kwtsms_sms_log with optional filtering.
   *
   * Supported filter keys:
   *  - status (string): exact match on the status column.
   *  - event_type (string): exact match on the event_type column.
   *  - date_from (int): Unix timestamp; only rows with created >= value.
   *  - date_to (int): Unix timestamp; only rows with created <= value.
   *
   * Results are ordered by created DESC.
   *
   * @param array $filters
   *   Optional filter criteria.
   * @param int $limit
   *   Maximum number of rows to return.
   * @param int $offset
   *   Number of rows to skip for pagination.
   *
   * @return object[]
   *   Array of stdClass objects, one per matched log row.
   */
  public function getLogs(array $filters = [], int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('kwtsms_sms_log', 'l')
      ->fields('l')
      ->orderBy('l.created', 'DESC')
      ->range($offset, $limit);

    if (!empty($filters['status'])) {
      $query->condition('l.status', $filters['status']);
    }

    if (!empty($filters['event_type'])) {
      $query->condition('l.event_type', $filters['event_type']);
    }

    if (!empty($filters['date_from'])) {
      $query->condition('l.created', (int) $filters['date_from'], '>=');
    }

    if (!empty($filters['date_to'])) {
      $query->condition('l.created', (int) $filters['date_to'], '<=');
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Returns aggregate send counts for today, last 7 days, and last 30 days.
   *
   * Only rows with status = 'sent' are counted.
   *
   * @return array{today: int, week: int, month: int}
   *   Associative array with keys 'today', 'week', and 'month'.
   */
  public function getStats(): array {
    $now = \Drupal::time()->getRequestTime();

    // Start of today (midnight in server time).
    $todayStart = (int) strtotime('today midnight', $now);

    return [
      'today' => $this->countSince($todayStart),
      'week'  => $this->countSince($now - (7 * 86400)),
      'month' => $this->countSince($now - (30 * 86400)),
    ];
  }

  /**
   * Returns daily sent counts grouped by calendar date.
   *
   * Fetches all sent rows since the cutoff and groups them in PHP to avoid
   * database-specific date functions.
   *
   * @param int $days
   *   Number of days to look back (default 30).
   *
   * @return array<int, array{date: string, count: int}>
   *   Array of associative arrays, each with 'date' (YYYY-MM-DD) and 'count'.
   *   Ordered by date ascending.
   */
  public function getDailyStats(int $days = 30): array {
    $cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);

    $rows = $this->database->select('kwtsms_sms_log', 'l')
      ->fields('l', ['created'])
      ->condition('l.status', 'sent')
      ->condition('l.created', $cutoff, '>=')
      ->execute()
      ->fetchAll();

    $counts = [];
    foreach ($rows as $row) {
      $date = date('Y-m-d', (int) $row->created);
      $counts[$date] = ($counts[$date] ?? 0) + 1;
    }

    ksort($counts);

    $result = [];
    foreach ($counts as $date => $count) {
      $result[] = ['date' => $date, 'count' => $count];
    }

    return $result;
  }

  /**
   * Truncates the kwtsms_sms_log table and writes an info log entry.
   */
  public function clearLogs(): void {
    $this->database->truncate('kwtsms_sms_log')->execute();
    $this->info('SMS logs cleared by admin.');
  }

  /**
   * Counts sent rows with created >= $since.
   *
   * @param int $since
   *   Unix timestamp lower bound (inclusive).
   *
   * @return int
   *   Row count.
   */
  private function countSince(int $since): int {
    return (int) $this->database->select('kwtsms_sms_log', 'l')
      ->condition('l.status', 'sent')
      ->condition('l.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Strips API credentials from a JSON string.
   *
   * Decodes the JSON, removes the 'username' and 'password' keys if present,
   * then re-encodes to JSON. Returns the original string if it cannot be
   * decoded as a JSON object.
   *
   * @param string $json
   *   A JSON-encoded string, typically the raw API response.
   *
   * @return string
   *   The JSON string with credentials removed.
   */
  private function stripCredentials(string $json): string {
    $decoded = json_decode($json, TRUE);

    if (!is_array($decoded)) {
      return $json;
    }

    unset($decoded['username'], $decoded['password']);

    return json_encode($decoded) ?: $json;
  }

}
