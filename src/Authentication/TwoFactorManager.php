<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Authentication;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Determines whether a given account requires two-factor authentication.
 *
 * Business logic for 2FA role matching is isolated here so the hook and
 * the form can both call a single authoritative check.
 */
class TwoFactorManager {

  /**
   * Constructs a TwoFactorManager instance.
   *
   * @param \Drupal\kwtsms\Authentication\OtpAuthProvider $otpProvider
   *   The OTP auth provider service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user proxy.
   */
  public function __construct(
    private readonly OtpAuthProvider $otpProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Determines whether two-factor authentication is required for an account.
   *
   * Returns TRUE only when the OTP mode is set to '2fa' and the account holds
   * at least one role that is listed in the otp_roles configuration.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to evaluate.
   *
   * @return bool
   *   TRUE when 2FA should be enforced for this account.
   */
  public function shouldRequire2fa(AccountInterface $account): bool {
    $config = $this->configFactory->get('kwtsms.settings');

    if ($config->get('otp_mode') !== '2fa') {
      return FALSE;
    }

    $configuredRoles = (array) ($config->get('otp_roles') ?? []);
    if (empty($configuredRoles)) {
      return FALSE;
    }

    $accountRoles = $account->getRoles();
    foreach ($accountRoles as $role) {
      if (in_array($role, $configuredRoles, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
