<?php

declare(strict_types=1);

namespace Drupal\kwtsms\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\SmsLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CronSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly KwtsmsGateway $gateway,
    private readonly SmsLogger $smsLogger,
    private readonly Connection $database,
  ) {}

  public static function getSubscribedEvents(): array {
    // Cron is handled via hook_cron in kwtsms.module.
    return [];
  }

  /**
   * Run daily sync and OTP cleanup.
   * Called from kwtsms_cron() in kwtsms.module.
   */
  public function onCron(): void {
    // Daily sync: only if last sync > 23 hours ago.
    $lastSync = $this->gateway->getCacheTimestamp('balance');
    $now = \Drupal::time()->getRequestTime();

    if ($lastSync === NULL || ($now - $lastSync) > 82800) {
      $result = $this->gateway->sync();
      $this->smsLogger->info('Cron sync: @status', [
        '@status' => $result['success'] ? 'OK' : 'failed',
      ]);
    }

    // Clean expired OTPs (older than 24 hours).
    $cutoff = $now - 86400;
    $deleted = $this->database->delete('kwtsms_otp')
      ->condition('expires', $cutoff, '<')
      ->execute();
    if ($deleted > 0) {
      $this->smsLogger->debug('Cron: cleaned @count expired OTPs.', ['@count' => $deleted]);
    }
  }

}
