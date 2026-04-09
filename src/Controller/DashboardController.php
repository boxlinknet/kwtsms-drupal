<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\SmsLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the kwtSMS admin dashboard.
 *
 * Shows gateway status, account balance, sender ID, test mode flag,
 * aggregate send stats, a 30-day bar chart, and quick-action links.
 */
class DashboardController extends ControllerBase {

  /**
   * Constructs a DashboardController instance.
   *
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   * @param \Drupal\kwtsms\Service\SmsLogger $smsLogger
   *   The kwtSMS SMS logger service.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrfToken
   *   The CSRF token generator service.
   */
  public function __construct(
    private readonly KwtsmsGateway $gateway,
    private readonly SmsLogger $smsLogger,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.gateway'),
      $container->get('kwtsms.logger'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Renders the dashboard page.
   *
   * @return array
   *   A render array using the kwtsms_dashboard theme hook.
   */
  public function page(): array {
    $config    = $this->config('kwtsms.settings');
    $connected = $this->gateway->isConnected();

    $balanceRaw = $this->gateway->getCachedValue('balance');
    $balance    = is_numeric($balanceRaw) ? (int) $balanceRaw : 0;

    $username = (string) ($config->get('api_username') ?? '');
    $senderId = (string) ($config->get('sender_id') ?? '');
    $testMode = (bool) $config->get('test_mode');
    $enabled  = (bool) $config->get('enabled');

    $stats      = $this->smsLogger->getStats();
    $dailyStats = $this->smsLogger->getDailyStats();

    $syncUrl = Url::fromRoute('kwtsms.gateway_sync', [], [
      'query' => ['token' => $this->csrfToken->get('kwtsms.gateway_sync')],
    ]);

    return [
      '#theme'    => 'kwtsms_dashboard',
      '#enabled'  => $enabled,
      '#connected' => $connected,
      '#username' => $username,
      '#balance'  => $balance,
      '#sender_id' => $senderId,
      '#test_mode' => $testMode,
      '#stats'    => $stats,
      '#sync_url' => $syncUrl,
      '#attached' => [
        'library'       => ['kwtsms/dashboard'],
        'drupalSettings' => [
          'kwtsms' => [
            'dailyStats' => $dailyStats,
          ],
        ],
      ],
    ];
  }

}
