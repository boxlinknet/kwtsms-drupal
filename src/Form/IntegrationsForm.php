<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Integrations settings form for the kwtSMS module.
 *
 * Controls third-party integrations such as Commerce order notifications.
 * Settings are stored in kwtsms.settings under commerce_* keys.
 */
class IntegrationsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['kwtsms.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_integrations_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('kwtsms.settings');
    $commerceEnabled = \Drupal::moduleHandler()->moduleExists('kwtsms_commerce');

    // Commerce integration section.
    $form['commerce'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Commerce Integration'),
    ];

    if (!$commerceEnabled) {
      $form['commerce']['notice'] = [
        '#type'   => 'markup',
        '#markup' => '<p class="messages messages--warning">' . $this->t('Enable the kwtSMS Commerce submodule for order notifications.') . '</p>',
      ];
    }
    else {
      $form['commerce']['description'] = [
        '#type'   => 'markup',
        '#markup' => '<p>' . $this->t('Configure which order status transitions trigger an SMS notification.') . '</p>',
      ];

      $form['commerce']['commerce_order_placed'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Order placed'),
        '#description'   => $this->t('Send an SMS when a new order is placed.'),
        '#default_value' => (bool) $config->get('commerce_order_placed'),
      ];

      $form['commerce']['commerce_order_completed'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Order completed'),
        '#description'   => $this->t('Send an SMS when an order is marked as completed.'),
        '#default_value' => (bool) $config->get('commerce_order_completed'),
      ];

      $form['commerce']['commerce_order_canceled'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Order canceled'),
        '#description'   => $this->t('Send an SMS when an order is canceled.'),
        '#default_value' => (bool) $config->get('commerce_order_canceled'),
      ];

      $form['commerce']['commerce_shipping_enabled'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Shipping status updates'),
        '#description'   => $this->t('Send an SMS to the customer when a shipment is shipped or canceled (requires Commerce Shipping).'),
        '#default_value' => (bool) $config->get('commerce_shipping_enabled'),
      ];

      $form['commerce']['low_stock'] = [
        '#type'  => 'fieldset',
        '#title' => $this->t('Low Stock Alerts'),
      ];

      $form['commerce']['low_stock']['commerce_low_stock_enabled'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Enable low stock alerts'),
        '#description'   => $this->t('Send an SMS to admin phones when a product variation stock falls at or below the threshold (requires field_stock on variations, checked via cron).'),
        '#default_value' => (bool) $config->get('commerce_low_stock_enabled'),
      ];

      $form['commerce']['low_stock']['commerce_low_stock_threshold'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Low stock threshold'),
        '#description'   => $this->t('Send an alert when stock is at or below this quantity.'),
        '#default_value' => (int) ($config->get('commerce_low_stock_threshold') ?? 5),
        '#min'           => 1,
        '#max'           => 1000,
        '#states'        => [
          'visible' => [
            ':input[name="commerce_low_stock_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['commerce']['abandoned_cart'] = [
        '#type'  => 'fieldset',
        '#title' => $this->t('Abandoned Cart Reminders'),
      ];

      $form['commerce']['abandoned_cart']['commerce_abandoned_cart_enabled'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Enable abandoned cart reminders'),
        '#description'   => $this->t('Send an SMS reminder to customers who have items in their cart but have not completed checkout (checked via cron).'),
        '#default_value' => (bool) $config->get('commerce_abandoned_cart_enabled'),
      ];

      $form['commerce']['abandoned_cart']['commerce_abandoned_cart_hours'] = [
        '#type'          => 'number',
        '#title'         => $this->t('Hours until cart is considered abandoned'),
        '#description'   => $this->t('Send a reminder after this many hours of inactivity.'),
        '#default_value' => (int) ($config->get('commerce_abandoned_cart_hours') ?? 24),
        '#min'           => 1,
        '#max'           => 168,
        '#states'        => [
          'visible' => [
            ':input[name="commerce_abandoned_cart_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    // Future integrations placeholder.
    $form['future'] = [
      '#type'     => 'fieldset',
      '#title'    => $this->t('Future Integrations'),
      '#disabled' => TRUE,
    ];

    $form['future']['placeholder'] = [
      '#type'   => 'markup',
      '#markup' => '<p>' . $this->t('More integrations coming soon (Webform, etc.)') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $commerceEnabled = \Drupal::moduleHandler()->moduleExists('kwtsms_commerce');

    $config = $this->config('kwtsms.settings');

    if ($commerceEnabled) {
      $config
        ->set('commerce_order_placed', (bool) $form_state->getValue('commerce_order_placed'))
        ->set('commerce_order_completed', (bool) $form_state->getValue('commerce_order_completed'))
        ->set('commerce_order_canceled', (bool) $form_state->getValue('commerce_order_canceled'))
        ->set('commerce_shipping_enabled', (bool) $form_state->getValue('commerce_shipping_enabled'))
        ->set('commerce_low_stock_enabled', (bool) $form_state->getValue('commerce_low_stock_enabled'))
        ->set('commerce_low_stock_threshold', (int) $form_state->getValue('commerce_low_stock_threshold'))
        ->set('commerce_abandoned_cart_enabled', (bool) $form_state->getValue('commerce_abandoned_cart_enabled'))
        ->set('commerce_abandoned_cart_hours', (int) $form_state->getValue('commerce_abandoned_cart_hours'));
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
