<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\kwtsms\Service\SmsLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams all SMS logs as a downloadable CSV file.
 */
class LogsExportController extends ControllerBase {

  /**
   * Constructs a LogsExportController instance.
   *
   * @param \Drupal\kwtsms\Service\SmsLogger $smsLogger
   *   The kwtSMS SMS logger service.
   */
  public function __construct(
    private readonly SmsLogger $smsLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.logger'),
    );
  }

  /**
   * Streams all SMS log rows as a CSV download.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   A streamed response that writes CSV rows directly to output.
   */
  public function export(): StreamedResponse {
    $filename = 'kwtsms-logs-' . date('Y-m-d') . '.csv';

    $smsLogger = $this->smsLogger;

    $response = new StreamedResponse(static function () use ($smsLogger): void {
      $handle = fopen('php://output', 'w');

      if ($handle === FALSE) {
        return;
      }

      // Write UTF-8 BOM so Excel opens the file correctly.
      fwrite($handle, "\xEF\xBB\xBF");

      // Header row.
      fputcsv($handle, [
        'Date',
        'Recipient',
        'Template',
        'Status',
        'Message',
        'Error Code',
        'Error Description',
        'Event Type',
        'Test Mode',
        'Points Charged',
        'Balance After',
      ]);

      // Fetch all logs in batches of 500 to avoid memory issues.
      $batchSize = 500;
      $offset = 0;

      do {
        $rows = $smsLogger->getLogs([], $batchSize, $offset);

        foreach ($rows as $log) {
          fputcsv($handle, [
            date('Y-m-d H:i:s', (int) ($log->created ?? 0)),
            (string) ($log->recipient ?? ''),
            (string) ($log->template_id ?? ''),
            (string) ($log->status ?? ''),
            (string) ($log->message ?? ''),
            (string) ($log->error_code ?? ''),
            (string) ($log->error_description ?? ''),
            (string) ($log->event_type ?? ''),
            (string) ($log->test_mode ?? ''),
            (string) ($log->points_charged ?? ''),
            (string) ($log->balance_after ?? ''),
          ]);
        }

        $offset += $batchSize;
      } while (count($rows) === $batchSize);

      fclose($handle);
    });

    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');

    return $response;
  }

}
