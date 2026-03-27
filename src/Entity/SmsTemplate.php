<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines an SMS template config entity.
 *
 * @ConfigEntityType(
 *   id = "kwtsms_template",
 *   label = @Translation("SMS Template"),
 *   handlers = {
 *     "list_builder" = "Drupal\Core\Config\Entity\ConfigEntityListBuilder",
 *   },
 *   config_prefix = "template",
 *   admin_permission = "administer kwtsms",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "category",
 *     "body_en",
 *     "body_ar",
 *     "default_body_en",
 *     "default_body_ar",
 *     "recipient_type",
 *     "system",
 *   }
 * )
 */
class SmsTemplate extends ConfigEntityBase {

  /**
   * The template machine name.
   *
   * @var string
   */
  protected string $id;

  /**
   * The human-readable template label.
   *
   * @var string
   */
  protected string $label;

  /**
   * The template category (e.g. authentication, notifications, admin_alerts).
   *
   * @var string
   */
  protected string $category = '';

  /**
   * The English message body.
   *
   * @var string
   */
  protected string $body_en = '';

  /**
   * The Arabic message body.
   *
   * @var string
   */
  protected string $body_ar = '';

  /**
   * The default English message body (used for reset-to-default).
   *
   * @var string
   */
  protected string $default_body_en = '';

  /**
   * The default Arabic message body (used for reset-to-default).
   *
   * @var string
   */
  protected string $default_body_ar = '';

  /**
   * The intended recipient type: customer, admin, or both.
   *
   * @var string
   */
  protected string $recipient_type = 'customer';

  /**
   * Whether this is a system-managed template.
   *
   * @var bool
   */
  protected bool $system = FALSE;

  /**
   * Returns the template category.
   *
   * @return string
   *   The category machine name.
   */
  public function getCategory(): string {
    return $this->category;
  }

  /**
   * Returns the English message body.
   *
   * @return string
   *   The English body with token placeholders.
   */
  public function getBodyEn(): string {
    return $this->body_en;
  }

  /**
   * Returns the Arabic message body.
   *
   * @return string
   *   The Arabic body with token placeholders.
   */
  public function getBodyAr(): string {
    return $this->body_ar;
  }

  /**
   * Returns the default English message body.
   *
   * @return string
   *   The default English body used when resetting the template.
   */
  public function getDefaultBodyEn(): string {
    return $this->default_body_en;
  }

  /**
   * Returns the default Arabic message body.
   *
   * @return string
   *   The default Arabic body used when resetting the template.
   */
  public function getDefaultBodyAr(): string {
    return $this->default_body_ar;
  }

  /**
   * Returns the recipient type.
   *
   * @return string
   *   One of: customer, admin, both.
   */
  public function getRecipientType(): string {
    return $this->recipient_type;
  }

  /**
   * Returns whether this is a system template.
   *
   * @return bool
   *   TRUE if this template is managed by the system.
   */
  public function isSystem(): bool {
    return $this->system;
  }

  /**
   * Returns the message body for the given language.
   *
   * @param string $langcode
   *   A language code. Returns Arabic body for 'ar', English body otherwise.
   *
   * @return string
   *   The message body for the requested language.
   */
  public function getBody(string $langcode = 'en'): string {
    return $langcode === 'ar' ? $this->body_ar : $this->body_en;
  }

  /**
   * Resets the editable body fields to the system defaults.
   *
   * @return $this
   *   Returns the current instance for method chaining.
   */
  public function resetToDefault(): static {
    $this->body_en = $this->default_body_en;
    $this->body_ar = $this->default_body_ar;
    return $this;
  }

}
