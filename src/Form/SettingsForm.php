<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for the kwtSMS module.
 *
 * Configures general options, authentication, rate limits, notifications,
 * and language preferences stored in the kwtsms.settings config object.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The kwtSMS gateway service.
   */
  private readonly KwtsmsGateway $gateway;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
    );
    $instance->gateway = $container->get('kwtsms.gateway');
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
    return 'kwtsms_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('kwtsms.settings');

    // -------------------------------------------------------------------------
    // General section
    // -------------------------------------------------------------------------
    $form['general'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('General'),
    ];

    $form['general']['enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable SMS sending'),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    $form['general']['test_mode'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Test mode (test=1, no delivery)'),
      '#default_value' => (bool) $config->get('test_mode'),
    ];

    // Build country code options from cached coverage or use static fallbacks.
    $coverageData = $this->gateway->getCachedValue('coverage');
    $countryCodeOptions = $this->buildCountryCodeOptions($coverageData);

    $currentCountryCode = (string) ($config->get('default_country_code') ?? '965');

    if ($countryCodeOptions !== NULL) {
      $form['general']['default_country_code'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Default country code'),
        '#options'       => $countryCodeOptions,
        '#default_value' => $currentCountryCode,
      ];
    }
    else {
      $form['general']['default_country_code'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Default country code'),
        '#default_value' => $currentCountryCode,
        '#description'   => $this->t('Connect to the gateway to load country codes from coverage data.'),
        '#size'          => 10,
      ];
    }

    // Build sender ID options from cache or fall back to textfield.
    $senderIds = $this->gateway->getCachedValue('senderids');
    $currentSenderId = (string) ($config->get('sender_id') ?? '');

    if (!empty($senderIds) && is_array($senderIds)) {
      $senderOptions = ['' => $this->t('Select sender ID')];
      foreach ($senderIds as $id) {
        $senderOptions[(string) $id] = (string) $id;
      }
      $form['general']['sender_id'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Sender ID'),
        '#options'       => $senderOptions,
        '#default_value' => $currentSenderId,
        '#empty_option'  => $this->t('Select sender ID'),
      ];
    }
    else {
      $form['general']['sender_id'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Sender ID'),
        '#default_value' => $currentSenderId,
        '#description'   => $this->t('Connect to the gateway to load available sender IDs.'),
        '#size'          => 30,
        '#maxlength'     => 11,
      ];
    }

    // -------------------------------------------------------------------------
    // Authentication section
    // -------------------------------------------------------------------------
    $form['authentication'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Authentication and OTP'),
    ];

    $form['authentication']['otp_mode'] = [
      '#type'          => 'select',
      '#title'         => $this->t('OTP mode'),
      '#options'       => [
        'disabled' => $this->t('Disabled'),
        'primary'  => $this->t('Primary login (SMS only)'),
        '2fa'      => $this->t('Two-factor authentication (2FA)'),
      ],
      '#default_value' => (string) ($config->get('otp_mode') ?? 'disabled'),
    ];

    // Build role checkboxes, excluding the anonymous role.
    $roles = \Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple();
    $roleOptions = [];
    foreach ($roles as $roleId => $role) {
      if ($roleId === 'anonymous') {
        continue;
      }
      $roleOptions[$roleId] = $role->label();
    }

    $otpRoles = $config->get('otp_roles') ?? [];

    $form['authentication']['otp_roles'] = [
      '#type'          => 'checkboxes',
      '#title'         => $this->t('Roles with OTP enabled'),
      '#options'       => $roleOptions,
      '#default_value' => is_array($otpRoles) ? $otpRoles : [],
      '#description'   => $this->t('Apply OTP to users with these roles.'),
    ];

    $form['authentication']['password_reset_mode'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Password reset mode'),
      '#options'       => [
        'email_only' => $this->t('Email only'),
        'sms_only'   => $this->t('SMS only'),
        'email_sms'  => $this->t('Email and SMS'),
      ],
      '#default_value' => (string) ($config->get('password_reset_mode') ?? 'email_only'),
    ];

    // -------------------------------------------------------------------------
    // Logging section
    // -------------------------------------------------------------------------
    $form['logging'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Logging'),
    ];

    $form['logging']['debug_logging'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable debug logging'),
      '#default_value' => (bool) $config->get('debug_logging'),
    ];

    // -------------------------------------------------------------------------
    // Rate limits section
    // -------------------------------------------------------------------------
    $form['rate_limits'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Rate Limits'),
    ];

    $form['rate_limits']['otp_per_phone_hour'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Max OTP requests per phone per hour'),
      '#default_value' => (int) ($config->get('otp_per_phone_hour') ?? 5),
      '#min'           => 1,
      '#max'           => 20,
    ];

    $form['rate_limits']['otp_per_ip_hour'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Max OTP requests per IP per hour'),
      '#default_value' => (int) ($config->get('otp_per_ip_hour') ?? 10),
      '#min'           => 1,
      '#max'           => 50,
    ];

    $form['rate_limits']['otp_lockout_attempts'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Failed attempts before lockout'),
      '#default_value' => (int) ($config->get('otp_lockout_attempts') ?? 5),
      '#min'           => 1,
      '#max'           => 10,
    ];

    $form['rate_limits']['otp_lockout_minutes'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Lockout duration (minutes)'),
      '#default_value' => (int) ($config->get('otp_lockout_minutes') ?? 15),
      '#min'           => 1,
      '#max'           => 60,
    ];

    $form['rate_limits']['otp_resend_cooldown'] = [
      '#type'          => 'number',
      '#title'         => $this->t('OTP resend cooldown (seconds)'),
      '#default_value' => (int) ($config->get('otp_resend_cooldown') ?? 60),
      '#min'           => 30,
      '#max'           => 300,
      '#description'   => $this->t('Seconds between OTP resends.'),
    ];

    // -------------------------------------------------------------------------
    // Notifications section
    // -------------------------------------------------------------------------
    $form['notifications'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Notifications'),
    ];

    $form['notifications']['notify_user_registered_customer'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Notify customer on registration'),
      '#default_value' => (bool) $config->get('notify_user_registered_customer'),
    ];

    $form['notifications']['notify_user_registered_admin'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Notify admin on registration'),
      '#default_value' => (bool) $config->get('notify_user_registered_admin'),
    ];

    $form['notifications']['admin_phones'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Admin phone numbers'),
      '#default_value' => (string) ($config->get('admin_phones') ?? ''),
      '#description'   => $this->t('Admin phone numbers (one per line or comma-separated).'),
      '#rows'          => 4,
    ];

    // -------------------------------------------------------------------------
    // Language section
    // -------------------------------------------------------------------------
    $form['language'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Language'),
    ];

    $form['language']['sms_language'] = [
      '#type'          => 'select',
      '#title'         => $this->t('SMS language'),
      '#options'       => [
        'auto' => $this->t('Auto (user preference)'),
        'en'   => $this->t('Force English'),
        'ar'   => $this->t('Force Arabic'),
      ],
      '#default_value' => (string) ($config->get('sms_language') ?? 'auto'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $otpRoles = array_filter((array) $form_state->getValue('otp_roles'));

    $this->config('kwtsms.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('test_mode', (bool) $form_state->getValue('test_mode'))
      ->set('debug_logging', (bool) $form_state->getValue('debug_logging'))
      ->set('default_country_code', (string) $form_state->getValue('default_country_code'))
      ->set('sender_id', (string) $form_state->getValue('sender_id'))
      ->set('sms_language', (string) $form_state->getValue('sms_language'))
      ->set('otp_mode', (string) $form_state->getValue('otp_mode'))
      ->set('otp_roles', array_values($otpRoles))
      ->set('password_reset_mode', (string) $form_state->getValue('password_reset_mode'))
      ->set('otp_per_phone_hour', (int) $form_state->getValue('otp_per_phone_hour'))
      ->set('otp_per_ip_hour', (int) $form_state->getValue('otp_per_ip_hour'))
      ->set('otp_lockout_attempts', (int) $form_state->getValue('otp_lockout_attempts'))
      ->set('otp_lockout_minutes', (int) $form_state->getValue('otp_lockout_minutes'))
      ->set('otp_resend_cooldown', (int) $form_state->getValue('otp_resend_cooldown'))
      ->set('notify_user_registered_customer', (bool) $form_state->getValue('notify_user_registered_customer'))
      ->set('notify_user_registered_admin', (bool) $form_state->getValue('notify_user_registered_admin'))
      ->set('admin_phones', (string) $form_state->getValue('admin_phones'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  // ---------------------------------------------------------------------------
  // Private helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds country code select options from cached coverage data.
   *
   * Falls back to a static list of common Gulf codes if coverage data is
   * absent or does not contain a usable prefix array. Returns NULL when no
   * options can be determined (caller should render a textfield instead).
   *
   * @param mixed $coverageData
   *   The decoded coverage value from the gateway cache, or NULL.
   *
   * @return array<string, string>|null
   *   Keyed options array, or NULL if the textfield fallback should be used.
   */
  private function buildCountryCodeOptions(mixed $coverageData): ?array {
    // Static fallback codes always available.
    $staticOptions = [
      '965' => $this->t('965 - Kuwait'),
      '966' => $this->t('966 - KSA'),
      '971' => $this->t('971 - UAE'),
      '973' => $this->t('973 - Bahrain'),
      '974' => $this->t('974 - Qatar'),
    ];

    if (!is_array($coverageData)) {
      return $staticOptions;
    }

    // The coverage API response may contain a 'prefixes' or 'prefix' key
    // with an array of country code strings.
    $prefixes = $coverageData['prefixes'] ?? $coverageData['prefix'] ?? NULL;

    if (!is_array($prefixes) || empty($prefixes)) {
      return $staticOptions;
    }

    $options = [];
    foreach ($prefixes as $prefix) {
      $code = (string) $prefix;
      // Use a static label if available, otherwise show the code alone.
      $options[$code] = $staticOptions[$code] ?? $code;
    }

    // Ensure static fallbacks appear even if coverage omits them.
    foreach ($staticOptions as $code => $label) {
      if (!isset($options[$code])) {
        $options[$code] = $label;
      }
    }

    ksort($options);

    return $options;
  }

}
