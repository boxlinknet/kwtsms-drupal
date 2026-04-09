<?php

declare(strict_types=1);

namespace Drupal\kwtsms_commerce\EventSubscriber;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\SmsLogger;
use Drupal\kwtsms\Service\TemplateRenderer;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens to Commerce order transitions and sends SMS.
 */
class OrderEventSubscriber implements EventSubscriberInterface {

  /**
   * Constructs an OrderEventSubscriber instance.
   *
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   * @param \Drupal\kwtsms\Service\TemplateRenderer $templateRenderer
   *   The kwtSMS template renderer service.
   * @param \Drupal\kwtsms\Service\SmsLogger $logger
   *   The kwtSMS logger service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    protected KwtsmsGateway $gateway,
    protected TemplateRenderer $templateRenderer,
    protected SmsLogger $logger,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'commerce_order.place.post_transition' => ['onOrderPlaced'],
      'commerce_order.fulfill.post_transition' => ['onOrderTransition'],
      'commerce_order.cancel.post_transition' => ['onOrderTransition'],
      'commerce_shipment.ship.post_transition' => ['onShipmentUpdate'],
      'commerce_shipment.cancel.post_transition' => ['onShipmentUpdate'],
    ];
  }

  /**
   * Handles the order placed transition.
   *
   * Sends an order_placed SMS to the customer and an order_paid_admin SMS
   * to configured admin phones.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onOrderPlaced(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    $phone = $this->getOrderPhone($order);

    if ($phone !== '') {
      $msg = $this->templateRenderer->render(
        'order_placed',
        ['commerce_order' => $order]
      );
      $this->gateway->send([$phone], $msg, 'order_placed');
    }

    $config = $this->configFactory->get('kwtsms.settings');
    $adminPhones = $config->get('admin_phones') ?? [];

    if (!empty($adminPhones)) {
      $adminMsg = $this->templateRenderer->render(
        'order_paid_admin',
        ['commerce_order' => $order]
      );
      $this->gateway->send(
        $adminPhones,
        $adminMsg,
        'order_paid_admin',
      );
    }
  }

  /**
   * Handles order fulfill and cancel transitions.
   *
   * Sends an order_status SMS to the customer.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onOrderTransition(WorkflowTransitionEvent $event): void {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();

    $phone = $this->getOrderPhone($order);

    if ($phone !== '') {
      $statusMsg = $this->templateRenderer->render(
        'order_status',
        ['commerce_order' => $order]
      );
      $this->gateway->send(
        [$phone],
        $statusMsg,
        'order_status',
      );
    }

    $transitionId = $event->getTransition()->getId();
    $this->logger->info('Order @id transitioned via @transition.', [
      '@id' => $order->id(),
      '@transition' => $transitionId,
    ]);
  }

  /**
   * Handles Commerce Shipping shipment transitions.
   *
   * Sends a shipping_update SMS to the customer when a shipment is shipped
   * or canceled.
   *
   * @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
   *   The workflow transition event.
   */
  public function onShipmentUpdate(WorkflowTransitionEvent $event): void {
    $config = $this->configFactory->get('kwtsms.settings');
    if (!$config->get('commerce_shipping_enabled')) {
      return;
    }

    $shipment = $event->getEntity();
    if (!method_exists($shipment, 'getOrder')) {
      return;
    }

    $order = $shipment->getOrder();
    if ($order === NULL) {
      return;
    }

    $phone = $this->getOrderPhone($order);
    if ($phone === '') {
      return;
    }

    $transition = $event->getTransition();
    $status = $transition->getToState()->getLabel();
    $message = $this->templateRenderer->render('shipping_update', [
      'commerce_order' => $order,
    ], [
      '[kwtsms:shipping-status]' => (string) $status,
    ]);

    if ($message !== NULL && $message !== '') {
      $eventType = 'shipping_' . $transition->getId();
      $this->gateway->send(
        [$phone],
        $message,
        $eventType,
        [
          'template_id' => 'shipping_update',
          'uid' => (int) $order->getCustomerId(),
        ]
      );
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
    // Try billing profile first.
    try {
      if ($order instanceof OrderInterface) {
        $billingProfile = $order->getBillingProfile();
        $hasPhone = $billingProfile !== NULL
          && $billingProfile->hasField('field_phone');
        if ($hasPhone) {
          $phoneValue = $billingProfile->get('field_phone')->value;
          if (!empty($phoneValue)) {
            return (string) $phoneValue;
          }
        }

        // Fall back to customer user field_phone.
        $customer = $order->getCustomer();
        $customerHasPhone = $customer !== NULL
          && !$customer->isAnonymous()
          && $customer->hasField('field_phone');
        if ($customerHasPhone) {
          $phoneValue = $customer->get('field_phone')->value;
          if (!empty($phoneValue)) {
            return (string) $phoneValue;
          }
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to resolve phone for order @id: @message', [
        '@id' => method_exists($order, 'id') ? $order->id() : 'unknown',
        '@message' => $e->getMessage(),
      ]);
    }

    return '';
  }

}
