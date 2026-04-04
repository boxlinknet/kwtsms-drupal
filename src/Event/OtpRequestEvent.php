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

  public const EVENT_NAME = 'kwtsms.otp_request';

  private bool $blocked = FALSE;
  private string $blockReason = '';

  public function __construct(
    private readonly string $phone,
    private readonly string $purpose,
    private readonly string $ipAddress,
  ) {}

  public function getPhone(): string {
    return $this->phone;
  }

  public function getPurpose(): string {
    return $this->purpose;
  }

  public function getIpAddress(): string {
    return $this->ipAddress;
  }

  public function block(string $reason): void {
    $this->blocked = TRUE;
    $this->blockReason = $reason;
  }

  public function isBlocked(): bool {
    return $this->blocked;
  }

  public function getBlockReason(): string {
    return $this->blockReason;
  }

}
