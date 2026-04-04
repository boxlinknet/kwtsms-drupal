<?php

declare(strict_types=1);

namespace Drupal\kwtsms_smsframework\Plugin\SmsGateway;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\sms\Message\SmsMessageInterface;
use Drupal\sms\Message\SmsMessageResultInterface;
use Drupal\sms\Message\SmsMessageResult;
use Drupal\sms\Message\SmsDeliveryReport;
use Drupal\sms\Plugin\SmsGatewayPluginBase;
use Drupal\kwtsms\Service\KwtsmsGateway as KwtsmsGatewayService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * SMS Framework gateway plugin for kwtSMS.
 *
 * @SmsGateway(
 *   id = "kwtsms",
 *   label = @Translation("kwtSMS"),
 *   outgoing_message_max_recipients = 200,
 *   incoming = false,
 * )
 */
class KwtSmsGateway extends SmsGatewayPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The kwtSMS gateway service.
   */
  private readonly KwtsmsGatewayService $kwtsmsGateway;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->kwtsmsGateway = $container->get('kwtsms.gateway');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'sender_id' => 'KWT-SMS',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['sender_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender ID'),
      '#default_value' => $this->configuration['sender_id'],
      '#description' => $this->t('Configure your sender ID in the main kwtSMS Gateway tab.'),
    ];

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('API credentials are managed in the <a href=":url">kwtSMS Gateway settings</a>. The kwtSMS module must be connected before this gateway can send messages.', [
        ':url' => '/admin/config/kwtsms/gateway',
      ]) . '</p>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['sender_id'] = $form_state->getValue('sender_id');
  }

  /**
   * {@inheritdoc}
   */
  public function send(SmsMessageInterface $sms): SmsMessageResultInterface {
    $result = new SmsMessageResult();

    if (!$this->kwtsmsGateway->isConnected()) {
      $result->setError(SmsMessageResult::ERROR, 'kwtSMS gateway is not connected.');
      return $result;
    }

    $recipients = $sms->getRecipients();
    $message = $sms->getMessage();

    $sendResult = $this->kwtsmsGateway->send(
      $recipients,
      $message,
      'smsframework',
      ['skip_balance_check' => FALSE],
    );

    if ($sendResult['success']) {
      foreach ($recipients as $recipient) {
        $report = new SmsDeliveryReport();
        $report->setRecipient($recipient);
        $report->setStatus(SmsDeliveryReport::STATUS_DELIVERED);
        $result->addReport($report);
      }
    }
    else {
      $errorMsg = implode(', ', $sendResult['errors'] ?? ['Unknown error']);
      $result->setError(SmsMessageResult::ERROR, $errorMsg);
      foreach ($recipients as $recipient) {
        $report = new SmsDeliveryReport();
        $report->setRecipient($recipient);
        $report->setStatus(SmsDeliveryReport::STATUS_REJECTED);
        $report->setStatusMessage($errorMsg);
        $result->addReport($report);
      }
    }

    return $result;
  }

}
