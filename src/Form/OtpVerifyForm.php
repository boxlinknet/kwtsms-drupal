<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\user\Entity\User;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\kwtsms\Authentication\OtpAuthProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Verify step of the OTP login flow.
 *
 * Accepts the 6-digit code sent in the previous step, verifies it against the
 * stored hash, and finalizes login when the code is valid and a user is found.
 */
class OtpVerifyForm extends FormBase {

  /**
   * Constructs an OtpVerifyForm instance.
   *
   * @param \Drupal\kwtsms\Authentication\OtpAuthProvider $otpProvider
   *   The OTP auth provider service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   */
  public function __construct(
    private readonly OtpAuthProvider $otpProvider,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.otp_provider'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_otp_verify_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
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

    $form['actions']['resend'] = [
      '#type'  => 'link',
      '#title' => $this->t('Resend code'),
      '#url'   => Url::fromRoute('kwtsms.otp_login'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get('kwtsms_otp');
    $phone = (string) ($store->get('otp_phone') ?? '');

    if ($phone === '') {
      $this->messenger()->addError($this->t('Session expired. Please start over.'));
      $form_state->setRedirectUrl(Url::fromRoute('kwtsms.otp_login'));
      return;
    }

    $code   = trim((string) $form_state->getValue('code'));
    $result = $this->otpProvider->verifyOtp($phone, $code, 'login');

    if (!$result['valid']) {
      $this->messenger()->addError($this->t('@reason', ['@reason' => $result['reason']]));
      return;
    }

    $uid = $result['uid'];

    if ($uid <= 0) {
      // OTP was valid but no account is associated: treat as failure.
      $this->messenger()->addError($this->t('Invalid or expired code.'));
      return;
    }

    // Check the purpose stored by the initiating form.
    $purpose = $store->get('otp_purpose') ?? 'login';
    if ($purpose === 'password_reset') {
      $resetUid = $store->get('reset_uid');
      $store->delete('otp_purpose');
      $store->delete('reset_uid');
      $store->delete('otp_phone');
      if ($resetUid) {
        $user = User::load($resetUid);
        if ($user) {
          // Generate one-time login link and redirect.
          $url = user_pass_reset_url($user);
          $form_state->setRedirectUrl(Url::fromUri($url));
          return;
        }
      }
      $this->messenger()->addError($this->t('Password reset failed. Please try again.'));
      $form_state->setRedirect('user.pass');
      return;
    }

    /** @var \Drupal\user\UserInterface|null $user */
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);

    if ($user === NULL || $user->isBlocked()) {
      $this->messenger()->addError($this->t('Your account is not active. Please contact the site administrator.'));
      return;
    }

    // Clear the stored phone from the session.
    $store->delete('otp_phone');

    user_login_finalize($user);

    $form_state->setRedirectUrl(Url::fromRoute('<front>'));
  }

}
