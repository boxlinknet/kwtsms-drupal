<?php

declare(strict_types=1);

namespace Drupal\kwtsms_commerce\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Time\TimeInterface;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\SmsLogger;
use Drupal\kwtsms\Service\TemplateRenderer;

/**
 * Handles cron-based Commerce SMS tasks: low stock alerts and abandoned carts.
 *
 * Called from kwtsms_commerce_cron() in kwtsms_commerce.module.
 */
class CommerceCronSubscriber {

  /**
   * Constructs a CommerceCronSubscriber instance.
   *
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   * @param \Drupal\kwtsms\Service\TemplateRenderer $templateRenderer
   *   The kwtSMS template renderer service.
   * @param \Drupal\kwtsms\Service\SmsLogger $smsLogger
   *   The kwtSMS logger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Time\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected KwtsmsGateway $gateway,
    protected TemplateRenderer $templateRenderer,
    protected SmsLogger $smsLogger,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
  ) {}

  /**
   * Checks product variation stock levels and sends low stock alerts.
   *
   * Queries commerce_product_variation entities with field_stock <= threshold
   * and sends an SMS to the configured admin phones.
   */
  public function checkLowStock(): void {
    $config = $this->configFactory->get('kwtsms.settings');
    if (!$config->get('commerce_low_stock_enabled')) {
      return;
    }

    $threshold = (int) $config->get('commerce_low_stock_threshold');
    $adminPhones = (string) ($config->get('admin_phones') ?? '');
    if ($adminPhones === '') {
      return;
    }

    if (!$this->entityTypeManager->hasDefinition('commerce_product_variation')) {
      return;
    }

    $phones = array_values(array_filter(array_map('trim', explode(',', $adminPhones))));
    if (empty($phones)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $query = $storage->getQuery()->accessCheck(FALSE);

    try {
      $ids = $query
        ->condition('field_stock', 0, '>')
        ->condition('field_stock', $threshold, '<=')
        ->execute();
    }
    catch (\Exception $e) {
      $this->smsLogger->debug('Low stock check skipped: @msg', ['@msg' => $e->getMessage()]);
      return;
    }

    if (empty($ids)) {
      return;
    }

    $variations = $storage->loadMultiple($ids);
    foreach ($variations as $variation) {
      $stockLevel = (int) $variation->get('field_stock')->value;
      $product = method_exists($variation, 'getProduct') ? $variation->getProduct() : NULL;

      $message = $this->templateRenderer->render('low_stock', [
        'commerce_product' => $product,
      ], [
        '[kwtsms:stock-level]' => (string) $stockLevel,
      ]);

      if ($message !== NULL && $message !== '') {
        $this->gateway->send($phones, $message, 'low_stock', [
          'template_id' => 'low_stock',
        ]);
      }
    }
  }

  /**
   * Finds draft orders with items older than the configured threshold and sends reminders.
   *
   * Only processes orders within a 24-hour window past the threshold to avoid
   * re-sending on subsequent cron runs.
   */
  public function checkAbandonedCarts(): void {
    $config = $this->configFactory->get('kwtsms.settings');
    if (!$config->get('commerce_abandoned_cart_enabled')) {
      return;
    }

    $hours = (int) $config->get('commerce_abandoned_cart_hours');
    $now = $this->time->getRequestTime();
    $cutoff = $now - ($hours * 3600);

    if (!$this->entityTypeManager->hasDefinition('commerce_order')) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('commerce_order');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', 'draft')
      ->condition('changed', $cutoff - 86400, '>')
      ->condition('changed', $cutoff, '<')
      ->range(0, 50)
      ->execute();

    if (empty($ids)) {
      return;
    }

    $orders = $storage->loadMultiple($ids);
    foreach ($orders as $order) {
      if (!$order->hasItems()) {
        continue;
      }

      $phone = $this->getOrderPhone($order);
      if ($phone === '') {
        continue;
      }

      $message = $this->templateRenderer->render('abandoned_cart', [
        'commerce_order' => $order,
      ]);

      if ($message !== NULL && $message !== '') {
        $this->gateway->send([$phone], $message, 'abandoned_cart', [
          'template_id' => 'abandoned_cart',
          'uid' => (int) $order->getCustomerId(),
        ]);
      }
    }
  }

  /**
   * Resolves the customer phone number from an order.
   *
   * Tries the billing profile field_phone first, then falls back to the
   * customer user's field_phone.
   *
   * @param object $order
   *   The commerce order entity.
   *
   * @return string
   *   The phone number, or an empty string if none found.
   */
  private function getOrderPhone(object $order): string {
    try {
      if ($order instanceof OrderInterface) {
        $billingProfile = $order->getBillingProfile();
        if ($billingProfile !== NULL && $billingProfile->hasField('field_phone')) {
          $phoneValue = $billingProfile->get('field_phone')->value;
          if (!empty($phoneValue)) {
            return (string) $phoneValue;
          }
        }

        $customer = $order->getCustomer();
        if ($customer !== NULL && !$customer->isAnonymous() && $customer->hasField('field_phone')) {
          $phoneValue = $customer->get('field_phone')->value;
          if (!empty($phoneValue)) {
            return (string) $phoneValue;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->smsLogger->error('Failed to resolve phone for order @id: @message', [
        '@id' => method_exists($order, 'id') ? $order->id() : 'unknown',
        '@message' => $e->getMessage(),
      ]);
    }

    return '';
  }

}
