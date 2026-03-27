<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\kwtsms\Authentication\OtpAuthProvider;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\PhoneNormalizer;
use Drupal\kwtsms\Service\TemplateRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Password reset form that delivers a one-time code via SMS.
 *
 * Anti-enumeration: the success message is identical whether or not a
 * matching account exists. The OTP is only generated and sent when a user
 * is found, but the visitor cannot infer that from the response.
 */
class PasswordResetSmsForm extends FormBase {

  /**
   * Constructs a PasswordResetSmsForm instance.
   *
   * @param \Drupal\kwtsms\Authentication\OtpAuthProvider $otpProvider
   *   The OTP auth provider service.
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   * @param \Drupal\kwtsms\Service\TemplateRenderer $templateRenderer
   *   The template renderer service.
   * @param \Drupal\kwtsms\Service\PhoneNormalizer $phoneNormalizer
   *   The phone normalizer service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   */
  public function __construct(
    private readonly OtpAuthProvider $otpProvider,
    private readonly KwtsmsGateway $gateway,
    private readonly TemplateRenderer $templateRenderer,
    private readonly PhoneNormalizer $phoneNormalizer,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.otp_provider'),
      $container->get('kwtsms.gateway'),
      $container->get('kwtsms.template_renderer'),
      $container->get('kwtsms.phone_normalizer'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_password_reset_sms_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['phone'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Phone Number'),
      '#required'    => TRUE,
      '#maxlength'   => 20,
      '#description' => $this->t('Enter your registered phone number to receive a password reset code.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Send Reset Code'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $mode = $this->configFactory()->get('kwtsms.settings')->get('password_reset_mode');
    if ($mode === 'email_only') {
      $form_state->setErrorByName('phone', $this->t('SMS password reset is not enabled.'));
      return;
    }

    $raw   = trim((string) $form_state->getValue('phone'));
    $phone = $this->phoneNormalizer->normalize($raw);

    $verification = $this->phoneNormalizer->verify($phone);
    if (!$verification['valid']) {
      $form_state->setErrorByName('phone', $this->t('Invalid phone number format.'));
      return;
    }

    $request = $this->getRequest();
    $ip      = (string) ($request->getClientIp() ?? '');

    $rateLimit = $this->otpProvider->checkRateLimit($phone, $ip);
    if (!$rateLimit['allowed']) {
      $form_state->setErrorByName('phone', $this->t('@reason', ['@reason' => $rateLimit['reason']]));
      return;
    }

    if ($this->otpProvider->checkLockout($phone)) {
      $form_state->setErrorByName('phone', $this->t('This phone number is temporarily locked. Please try again later.'));
      return;
    }

    // Store normalized phone for submitForm.
    $form_state->set('normalized_phone', $phone);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $phone = (string) ($form_state->get('normalized_phone') ?? '');
    if ($phone === '') {
      $phone = $this->phoneNormalizer->normalize(trim((string) $form_state->getValue('phone')));
    }

    // Look up the user without revealing whether an account exists.
    $uid  = $this->otpProvider->findUserByPhone($phone);
    $code = $this->otpProvider->generateOtp($phone, 'password_reset', $uid ?? 0);

    if ($uid !== NULL) {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);

      if ($user !== NULL) {
        $message = $this->templateRenderer->render(
          'password_reset',
          ['user' => $user],
          ['[kwtsms:otp-code]' => $code],
          NULL,
        );

        if ($message !== NULL) {
          $this->gateway->send(
            $phone,
            $message,
            'password_reset',
            ['template_id' => 'password_reset', 'uid' => $uid],
          );
        }
      }
    }

    // Store data for the verify step.
    $store = $this->tempStoreFactory->get('kwtsms');
    $store->set('reset_phone', $phone);
    $store->set('reset_uid', $uid);
    $store->set('otp_purpose', 'password_reset');

    // Anti-enumeration: same message regardless of whether an account exists.
    $this->messenger()->addStatus(
      $this->t('If an account exists with this phone number, a reset code has been sent.')
    );

    $form_state->setRedirectUrl(Url::fromRoute('kwtsms.otp_verify'));
  }

}
