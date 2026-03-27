<?php

declare(strict_types=1);

namespace Drupal\kwtsms\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\SmsLogger;
use Drupal\kwtsms\Service\TemplateRenderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to user entity events for SMS notifications.
 */
class UserEventSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a UserEventSubscriber instance.
   */
  public function __construct(
    private readonly KwtsmsGateway $gateway,
    private readonly TemplateRenderer $renderer,
    private readonly SmsLogger $smsLogger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns subscribed events.
   */
  public static function getSubscribedEvents(): array {
    // Entity events are handled via hook_user_insert in kwtsms.module.
    // This class provides the business logic called from that hook.
    return [];
  }

  /**
   * Handles new user registration.
   *
   * Called from kwtsms_user_insert() in kwtsms.module.
   */
  public function onUserInsert(EntityInterface $entity): void {
    $config = $this->configFactory->get('kwtsms.settings');

    // Notify customer.
    if ($config->get('notify_user_registered_customer')) {
      $phone = '';
      if ($entity->hasField('field_phone')) {
        $phone = $entity->get('field_phone')->value ?? '';
      }
      if (!empty($phone)) {
        $message = $this->renderer->render('user_registered', ['user' => $entity]);
        if ($message) {
          $this->gateway->send($phone, $message, 'user_register', [
            'template_id' => 'user_registered',
            'uid' => (int) $entity->id(),
          ]);
        }
      }
    }

    // Notify admin.
    if ($config->get('notify_user_registered_admin')) {
      $adminPhones = $config->get('admin_phones');
      if (!empty($adminPhones)) {
        $phones = array_map('trim', explode(',', $adminPhones));
        $phones = array_filter($phones);
        $message = $this->renderer->render('admin_new_user', ['user' => $entity]);
        if ($message && !empty($phones)) {
          $this->gateway->send($phones, $message, 'admin_new_user', [
            'template_id' => 'admin_new_user',
          ]);
        }
      }
    }
  }

}
