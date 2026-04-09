<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Authentication;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\PhoneNormalizer;
use Drupal\kwtsms\Service\SmsLogger;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Manages OTP lifecycle: generation, verification, rate limiting, and lockout.
 *
 * OTP codes are stored as bcrypt hashes only. Plain codes are returned to the
 * caller for delivery and are never persisted.
 */
class OtpAuthProvider {

  /**
   * OTP expiry in seconds (5 minutes).
   */
  private const OTP_TTL = 300;

  /**
   * Constructs an OtpAuthProvider instance.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   * @param \Drupal\kwtsms\Service\PhoneNormalizer $phoneNormalizer
   *   The phone normalizer service.
   * @param \Drupal\kwtsms\Service\SmsLogger $smsLogger
   *   The SMS logger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly KwtsmsGateway $gateway,
    private readonly PhoneNormalizer $phoneNormalizer,
    private readonly SmsLogger $smsLogger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Generates a new OTP for the given phone and purpose.
   *
   * Invalidates any previous unused OTPs for the same phone and purpose,
   * generates a fresh 6-digit code, hashes it with bcrypt, and inserts a
   * new row into the kwtsms_otp table.
   *
   * @param string $phone
   *   Normalized phone number.
   * @param string $purpose
   *   OTP purpose (e.g. 'login', 'password_reset').
   * @param int $uid
   *   Optional user ID to associate with the OTP (0 for anonymous).
   *
   * @return string
   *   The plain 6-digit code. Store or send this; never persisted in the DB.
   */
  public function generateOtp(string $phone, string $purpose, int $uid = 0): string {
    $now = $this->time->getRequestTime();

    // Invalidate all previous unused OTPs for this phone and purpose.
    $this->database->update('kwtsms_otp')
      ->fields(['used' => 1])
      ->condition('phone', $phone)
      ->condition('purpose', $purpose)
      ->condition('used', 0)
      ->execute();

    $code     = (string) random_int(100000, 999999);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);

    $request   = $this->requestStack->getCurrentRequest();
    $ipAddress = $request ? $request->getClientIp() : '';

    $this->database->insert('kwtsms_otp')
      ->fields([
        'phone'      => $phone,
        'code_hash'  => $codeHash,
        'uid'        => $uid,
        'purpose'    => $purpose,
        'attempts'   => 0,
        'ip_address' => $ipAddress ?? '',
        'created'    => $now,
        'expires'    => $now + self::OTP_TTL,
        'used'       => 0,
      ])
      ->execute();

    $this->smsLogger->info(
      'OTP generated for @phone purpose @purpose',
      ['@phone' => $phone, '@purpose' => $purpose],
    );

    return $code;
  }

  /**
   * Verifies an OTP code for the given phone and purpose.
   *
   * Increments the attempt counter on every call. Returns a failure result
   * when no valid (unused, unexpired) OTP is found, when the attempt limit
   * is reached, or when the code does not match the stored hash.
   *
   * @param string $phone
   *   Normalized phone number.
   * @param string $code
   *   The plain OTP code entered by the user.
   * @param string $purpose
   *   OTP purpose to match against.
   *
   * @return array{valid: bool, reason: string, uid: int}
   *   Result array with keys 'valid', 'reason', and 'uid'.
   */
  public function verifyOtp(string $phone, string $code, string $purpose): array {
    $now = $this->time->getRequestTime();

    $row = $this->database->select('kwtsms_otp', 'o')
      ->fields('o')
      ->condition('o.phone', $phone)
      ->condition('o.purpose', $purpose)
      ->condition('o.used', 0)
      ->condition('o.expires', $now, '>')
      ->orderBy('o.created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return ['valid' => FALSE, 'reason' => 'Invalid or expired code', 'uid' => 0];
    }

    $lockoutAttempts = (int) ($this->configFactory->get('kwtsms.settings')->get('otp_lockout_attempts') ?? 5);

    // Increment attempts.
    $newAttempts = (int) $row->attempts + 1;
    $this->database->update('kwtsms_otp')
      ->fields(['attempts' => $newAttempts])
      ->condition('id', $row->id)
      ->execute();

    if ($newAttempts >= $lockoutAttempts) {
      return ['valid' => FALSE, 'reason' => 'Too many attempts. Please try again later.', 'uid' => 0];
    }

    if (!password_verify($code, $row->code_hash)) {
      return ['valid' => FALSE, 'reason' => 'Invalid or expired code', 'uid' => 0];
    }

    // Mark OTP as used.
    $this->database->update('kwtsms_otp')
      ->fields(['used' => 1])
      ->condition('id', $row->id)
      ->execute();

    return ['valid' => TRUE, 'reason' => '', 'uid' => (int) $row->uid];
  }

  /**
   * Checks rate limits for OTP requests from a phone number and IP address.
   *
   * Both the per-phone and per-IP limits are checked against OTPs created in
   * the last hour.
   *
   * @param string $phone
   *   Normalized phone number.
   * @param string $ip
   *   The requester's IP address.
   *
   * @return array{allowed: bool, reason: string}
   *   Result with 'allowed' boolean and 'reason' string.
   */
  public function checkRateLimit(string $phone, string $ip): array {
    $config        = $this->configFactory->get('kwtsms.settings');
    $perPhoneLimit = (int) ($config->get('otp_per_phone_hour') ?? 5);
    $perIpLimit    = (int) ($config->get('otp_per_ip_hour') ?? 10);
    $since         = $this->time->getRequestTime() - 3600;

    $phoneCount = (int) $this->database->select('kwtsms_otp', 'o')
      ->condition('o.phone', $phone)
      ->condition('o.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($phoneCount >= $perPhoneLimit) {
      return ['allowed' => FALSE, 'reason' => 'Too many OTP requests for this phone number. Please try again later.'];
    }

    if ($ip !== '') {
      $ipCount = (int) $this->database->select('kwtsms_otp', 'o')
        ->condition('o.ip_address', $ip)
        ->condition('o.created', $since, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($ipCount >= $perIpLimit) {
        return ['allowed' => FALSE, 'reason' => 'Too many OTP requests from your location. Please try again later.'];
      }
    }

    return ['allowed' => TRUE, 'reason' => ''];
  }

  /**
   * Checks whether the given phone is currently locked out.
   *
   * A phone is locked out when there is an OTP with attempts at or above the
   * lockout threshold created within the lockout window.
   *
   * @param string $phone
   *   Normalized phone number.
   *
   * @return bool
   *   TRUE when the phone is locked out.
   */
  public function checkLockout(string $phone): bool {
    $config          = $this->configFactory->get('kwtsms.settings');
    $lockoutAttempts = (int) ($config->get('otp_lockout_attempts') ?? 5);
    $lockoutMinutes  = (int) ($config->get('otp_lockout_minutes') ?? 15);
    $since           = $this->time->getRequestTime() - ($lockoutMinutes * 60);

    $count = (int) $this->database->select('kwtsms_otp', 'o')
      ->condition('o.phone', $phone)
      ->condition('o.attempts', $lockoutAttempts, '>=')
      ->condition('o.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    return $count > 0;
  }

  /**
   * Checks whether the phone is within the resend cooldown window.
   *
   * @param string $phone
   *   Normalized phone number.
   *
   * @return bool
   *   TRUE when a resend is not yet allowed (cooldown is active).
   */
  public function checkResendCooldown(string $phone): bool {
    $cooldown = (int) ($this->configFactory->get('kwtsms.settings')->get('otp_resend_cooldown') ?? 60);
    $since    = $this->time->getRequestTime() - $cooldown;

    $count = (int) $this->database->select('kwtsms_otp', 'o')
      ->condition('o.phone', $phone)
      ->condition('o.created', $since, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    return $count > 0;
  }

  /**
   * Looks up a Drupal user ID by their stored phone number field.
   *
   * Queries users with field_phone matching the given normalized phone.
   * Access checks are bypassed so the lookup works regardless of permissions.
   *
   * @param string $phone
   *   Normalized phone number.
   *
   * @return int|null
   *   The user ID, or NULL when no matching user is found.
   */
  public function findUserByPhone(string $phone): ?int {
    $storage = $this->entityTypeManager->getStorage('user');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_phone', $phone)
      ->range(0, 1)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    return (int) reset($ids);
  }

}
