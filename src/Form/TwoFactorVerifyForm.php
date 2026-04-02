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
 * Verify step of the two-factor authentication flow.
 *
 * The user arrives here after a successful password login when their account
 * role requires 2FA. They enter the 6-digit code sent to their registered
 * phone number. On success, login is finalized.
 */
class TwoFactorVerifyForm extends FormBase {

  /**
   * Constructs a TwoFactorVerifyForm instance.
   *
   * @param \Drupal\kwtsms\Authentication\OtpAuthProvider $otpProvider
   *   The OTP auth provider service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   * @param \Drupal\kwtsms\Service\TemplateRenderer $templateRenderer
   *   The template renderer service.
   * @param \Drupal\kwtsms\Service\PhoneNormalizer $phoneNormalizer
   *   The phone normalizer service.
   */
  public function __construct(
    private readonly OtpAuthProvider $otpProvider,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly KwtsmsGateway $gateway,
    private readonly TemplateRenderer $templateRenderer,
    private readonly PhoneNormalizer $phoneNormalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.otp_provider'),
      $container->get('tempstore.private'),
      $container->get('kwtsms.gateway'),
      $container->get('kwtsms.template_renderer'),
      $container->get('kwtsms.phone_normalizer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_two_factor_verify_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $store = $this->tempStoreFactory->get('kwtsms');
    $uid   = $store->get('2fa_uid');

    if ($uid === NULL) {
      $form_state->setRedirectUrl(Url::fromRoute('user.login'));
      return $form;
    }

    $form['code'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Verification Code'),
      '#required'    => TRUE,
      '#maxlength'   => 6,
      '#size'        => 6,
      '#description' => $this->t('Enter the 6-digit code sent to your phone.'),
      '#attributes'  => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric'],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Verify'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get('kwtsms');
    $uid   = (int) ($store->get('2fa_uid') ?? 0);

    if ($uid <= 0) {
      $this->messenger()->addError($this->t('Session expired. Please log in again.'));
      $form_state->setRedirectUrl(Url::fromRoute('user.login'));
      return;
    }

    /** @var \Drupal\user\UserInterface|null $user */
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);

    if ($user === NULL || $user->isBlocked()) {
      $this->messenger()->addError($this->t('Invalid or expired code.'));
      $store->delete('2fa_uid');
      $form_state->setRedirectUrl(Url::fromRoute('user.login'));
      return;
    }

    $phone = '';
    if ($user->hasField('field_phone')) {
      $phone = (string) ($user->get('field_phone')->value ?? '');
      if ($phone !== '') {
        $phone = $this->phoneNormalizer->normalize($phone);
      }
    }

    if ($phone === '') {
      $this->messenger()->addError($this->t('Invalid or expired code.'));
      return;
    }

    $code   = trim((string) $form_state->getValue('code'));
    $result = $this->otpProvider->verifyOtp($phone, $code, 'two_factor');

    if (!$result['valid']) {
      $this->messenger()->addError($this->t('Invalid or expired code.'));
      return;
    }

    $store->delete('2fa_uid');

    user_login_finalize($user);

    $form_state->setRedirectUrl(Url::fromRoute('<front>'));
  }

}
