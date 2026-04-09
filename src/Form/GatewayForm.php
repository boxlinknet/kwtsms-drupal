<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Gateway connection, sync, and test form.
 *
 * Handles login/logout against the kwtSMS API, displays cached gateway info
 * when connected, allows a manual sync, and provides a test SMS card.
 */
class GatewayForm extends FormBase {

  /**
   * Constructs a GatewayForm instance.
   *
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(
    private readonly KwtsmsGateway $gateway,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.gateway'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_gateway_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $connected = $this->gateway->isConnected();

    // Credentials fieldset.
    $form['credentials'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Gateway Credentials'),
    ];

    if (!$connected) {
      $form['credentials']['username'] = [
        '#type'        => 'textfield',
        '#title'       => $this->t('Username'),
        '#required'    => TRUE,
        '#description' => $this->t('Your kwtSMS API username.'),
      ];

      $form['credentials']['password'] = [
        '#type'        => 'password',
        '#title'       => $this->t('Password'),
        '#required'    => TRUE,
        '#description' => $this->t('Your kwtSMS API password.'),
      ];

      $form['credentials']['login'] = [
        '#type'  => 'submit',
        '#value' => $this->t('Login'),
        '#name'  => 'login',
      ];
    }
    else {
      $config   = $this->configFactory()->get('kwtsms.settings');
      $username = (string) ($config->get('api_username') ?? '');

      $form['credentials']['status'] = [
        '#markup' => '<p>' . $this->t('Connected as: <strong>@user</strong>', ['@user' => $username]) . '</p>',
      ];

      $form['credentials']['logout'] = [
        '#type'  => 'submit',
        '#value' => $this->t('Logout'),
        '#name'  => 'logout',
      ];
    }

    // Gateway Info fieldset: only when connected.
    if ($connected) {
      $balance   = $this->gateway->getCachedValue('balance');
      $senderIds = $this->gateway->getCachedValue('senderids');
      $coverage  = $this->gateway->getCachedValue('coverage');
      $syncTs    = $this->gateway->getCacheTimestamp('balance');

      $lastSync = $syncTs
        ? $this->dateFormatter->format($syncTs, 'medium')
        : $this->t('Never');

      // Format sender IDs.
      if (is_array($senderIds) && !empty($senderIds)) {
        $senderList = implode(', ', $senderIds);
      }
      else {
        $senderList = $this->t('None cached');
      }

      // Format coverage: stored as flat array of prefix strings.
      if (is_array($coverage) && !empty($coverage)) {
        $coverageText = implode(', ', $coverage);
      }
      else {
        $coverageText = $this->t('Not cached');
      }

      $form['gateway_info'] = [
        '#type'  => 'fieldset',
        '#title' => $this->t('Gateway Info'),
      ];

      $form['gateway_info']['info_table'] = [
        '#theme'  => 'item_list',
        '#items'  => [
          $this->t('Balance: <strong>@balance</strong>', ['@balance' => $balance ?? $this->t('Unknown')]),
          $this->t('Sender IDs: <strong>@ids</strong>', ['@ids' => $senderList]),
          $this->t('Coverage: <strong>@coverage</strong>', ['@coverage' => $coverageText]),
          $this->t('Last sync: <strong>@time</strong>', ['@time' => $lastSync]),
        ],
        '#allowed_tags' => ['strong'],
      ];

      $form['gateway_info']['sync'] = [
        '#type'  => 'submit',
        '#value' => $this->t('Sync Now'),
        '#name'  => 'sync',
      ];

      // Test SMS card: only when connected.
      $form['test_card'] = [
        '#type'  => 'fieldset',
        '#title' => $this->t('Send Test SMS'),
      ];

      $form['test_card']['test_phone'] = [
        '#type'        => 'textfield',
        '#title'       => $this->t('Phone Number'),
        '#description' => $this->t('Enter the recipient phone number in international format (e.g. 96598765432).'),
        '#maxlength'   => 20,
      ];

      $form['test_card']['test_message'] = [
        '#type'          => 'textarea',
        '#title'         => $this->t('Message'),
        '#default_value' => 'kwtSMS test message from Drupal.',
        '#rows'          => 3,
      ];

      $form['test_card']['send_test'] = [
        '#type'  => 'submit',
        '#value' => $this->t('Send Test SMS'),
        '#name'  => 'send_test',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement()['#name'] ?? '';

    switch ($trigger) {
      case 'login':
        $username = trim((string) $form_state->getValue('username'));
        $password = (string) $form_state->getValue('password');
        $result   = $this->gateway->login($username, $password);

        if ($result['success']) {
          $balance = $result['balance'] ?? NULL;
          if ($balance !== NULL) {
            $this->messenger()->addStatus(
              $this->t('Connected successfully. Balance: @balance credits.', ['@balance' => $balance])
            );
          }
          else {
            $this->messenger()->addStatus($this->t('Connected successfully.'));
          }
        }
        else {
          $this->messenger()->addError(
            $this->t('Login failed: @msg', ['@msg' => $result['message']])
          );
        }
        break;

      case 'logout':
        $this->gateway->logout();
        $this->messenger()->addStatus($this->t('Disconnected from the kwtSMS gateway.'));
        break;

      case 'sync':
        $result = $this->gateway->sync();

        if ($result['success']) {
          $balance = $result['balance'] ?? NULL;
          if ($balance !== NULL) {
            $this->messenger()->addStatus(
              $this->t('Sync complete. Balance: @balance credits.', ['@balance' => $balance])
            );
          }
          else {
            $this->messenger()->addStatus($this->t('Sync complete.'));
          }
        }
        else {
          $this->messenger()->addError($this->t('Sync failed. Gateway may not be connected.'));
        }
        break;

      case 'send_test':
        $phone   = trim((string) $form_state->getValue('test_phone'));
        $message = trim((string) $form_state->getValue('test_message'));

        if ($phone === '') {
          $this->messenger()->addError($this->t('Phone number is required to send a test SMS.'));
          break;
        }

        $result = $this->gateway->sendTestSms($phone, $message);

        if ($result['success']) {
          $this->messenger()->addStatus(
            $this->t('Test SMS sent: @msg', ['@msg' => $result['message']])
          );
        }
        else {
          $this->messenger()->addError(
            $this->t('Test SMS failed: @msg', ['@msg' => $result['message']])
          );
        }
        break;
    }
  }

}
