<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Event dispatched before an SMS is sent.
 *
 * Allows other modules to validate, modify, or block the send.
 */
class SmsSendEvent extends Event {

  public const EVENT_NAME = 'kwtsms.sms_send';

  private bool $blocked = FALSE;
  private string $blockReason = '';

  public function __construct(
    private array $recipients,
    private string $message,
    private readonly string $eventType,
  ) {}

  public function getRecipients(): array {
    return $this->recipients;
  }

  public function getMessage(): string {
    return $this->message;
  }

  public function getEventType(): string {
    return $this->eventType;
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
