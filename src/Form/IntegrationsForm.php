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
    $instance = new static(
      $container->get('config.factory'),
    );
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
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
        ->set('commerce_order_canceled', (bool) $form_state->getValue('commerce_order_canceled'));
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
