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

  /**
   * The event name.
   */
  public const EVENT_NAME = 'kwtsms.sms_send';

  /**
   * Whether the SMS send has been blocked.
   *
   * @var bool
   */
  private bool $blocked = FALSE;

  /**
   * The reason the SMS send was blocked.
   *
   * @var string
   */
  private string $blockReason = '';

  /**
   * Constructs a SmsSendEvent.
   *
   * @param array $recipients
   *   The recipient phone numbers.
   * @param string $message
   *   The message text.
   * @param string $eventType
   *   The event type triggering the send.
   */
  public function __construct(
    private array $recipients,
    private string $message,
    private readonly string $eventType,
  ) {}

  /**
   * Gets the recipients.
   *
   * @return array
   *   The recipient phone numbers.
   */
  public function getRecipients(): array {
    return $this->recipients;
  }

  /**
   * Gets the message text.
   *
   * @return string
   *   The message.
   */
  public function getMessage(): string {
    return $this->message;
  }

  /**
   * Gets the event type.
   *
   * @return string
   *   The event type string.
   */
  public function getEventType(): string {
    return $this->eventType;
  }

  /**
   * Blocks the SMS send.
   *
   * @param string $reason
   *   The reason for blocking.
   */
  public function block(string $reason): void {
    $this->blocked = TRUE;
    $this->blockReason = $reason;
  }

  /**
   * Checks if the send is blocked.
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
