<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Handles the CSRF-protected gateway sync endpoint.
 *
 * Triggered from a tokenized URL (e.g. an admin link) and always redirects
 * back to the dashboard after attempting a sync.
 */
class GatewaySyncController extends ControllerBase {

  /**
   * Constructs a GatewaySyncController instance.
   *
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   */
  public function __construct(
    private readonly KwtsmsGateway $gateway,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.gateway'),
    );
  }

  /**
   * Syncs gateway data and redirects to the dashboard.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the kwtSMS dashboard route.
   */
  public function sync(): RedirectResponse {
    $result = $this->gateway->sync();

    if ($result['success']) {
      $balance = $result['balance'] ?? NULL;
      if ($balance !== NULL) {
        $this->messenger()->addStatus(
          $this->t('Sync complete. Balance: @balance credits.', ['@balance' => $balance])
        );
      }
      else {
        $this->messenger()->addStatus($this->t('Sync complete.'));
      }
    }
    else {
      $this->messenger()->addError($this->t('Sync failed. Gateway may not be connected.'));
    }

    return $this->redirect('kwtsms.dashboard');
  }

}
