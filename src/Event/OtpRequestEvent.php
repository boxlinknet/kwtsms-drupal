<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Event dispatched before an OTP is generated.
 *
 * Allows other modules (e.g., CAPTCHA) to validate the request
 * and optionally block OTP generation.
 */
class OtpRequestEvent extends Event {

  /**
   * The event name.
   */
  public const EVENT_NAME = 'kwtsms.otp_request';

  /**
   * Whether the OTP request has been blocked.
   *
   * @var bool
   */
  private bool $blocked = FALSE;

  /**
   * The reason the OTP request was blocked.
   *
   * @var string
   */
  private string $blockReason = '';

  /**
   * Constructs an OtpRequestEvent.
   *
   * @param string $phone
   *   The phone number requesting the OTP.
   * @param string $purpose
   *   The OTP purpose (login, password_reset, two_factor).
   * @param string $ipAddress
   *   The IP address of the requester.
   */
  public function __construct(
    private readonly string $phone,
    private readonly string $purpose,
    private readonly string $ipAddress,
  ) {}

  /**
   * Gets the phone number.
   *
   * @return string
   *   The phone number.
   */
  public function getPhone(): string {
    return $this->phone;
  }

  /**
   * Gets the OTP purpose.
   *
   * @return string
   *   The purpose string.
   */
  public function getPurpose(): string {
    return $this->purpose;
  }

  /**
   * Gets the IP address.
   *
   * @return string
   *   The IP address.
   */
  public function getIpAddress(): string {
    return $this->ipAddress;
  }

  /**
   * Blocks the OTP request.
   *
   * @param string $reason
   *   The reason for blocking.
   */
  public function block(string $reason): void {
    $this->blocked = TRUE;
    $this->blockReason = $reason;
  }

  /**
   * Checks if the request is blocked.
   *
   * @return bool
   *   TRUE if blocked.
   */
  public function isBlocked(): bool {
    return $this->blocked;
  }

  /**
   * Gets the block reason.
   *
   * @return string
   *   The reason string.
   */
  public function getBlockReason(): string {
    return $this->blockReason;
  }

}
