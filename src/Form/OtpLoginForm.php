<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\kwtsms\Authentication\OtpAuthProvider;
use Drupal\kwtsms\Event\OtpRequestEvent;
use Drupal\kwtsms\Service\KwtsmsGateway;
use Drupal\kwtsms\Service\PhoneNormalizer;
use Drupal\kwtsms\Service\SmsLogger;
use Drupal\kwtsms\Service\TemplateRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Login form that sends a one-time code to the user's phone number.
 *
 * Anti-enumeration: the form never reveals whether a phone number has a Drupal
 * account. The same success message is shown regardless of whether a user was
 * found. The OTP is only actually sent when a matching user exists.
 */
class OtpLoginForm extends FormBase {

  /**
   * Constructs an OtpLoginForm instance.
   *
   * @param \Drupal\kwtsms\Authentication\OtpAuthProvider $otpProvider
   *   The OTP auth provider service.
   * @param \Drupal\kwtsms\Service\KwtsmsGateway $gateway
   *   The kwtSMS gateway service.
   * @param \Drupal\kwtsms\Service\PhoneNormalizer $phoneNormalizer
   *   The phone normalizer service.
   * @param \Drupal\kwtsms\Service\TemplateRenderer $templateRenderer
   *   The template renderer service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   * @param \Drupal\kwtsms\Service\SmsLogger $smsLogger
   *   The kwtSMS SMS logger service.
   */
  public function __construct(
    private readonly OtpAuthProvider $otpProvider,
    private readonly KwtsmsGateway $gateway,
    private readonly PhoneNormalizer $phoneNormalizer,
    private readonly TemplateRenderer $templateRenderer,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly SmsLogger $smsLogger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.otp_provider'),
      $container->get('kwtsms.gateway'),
      $container->get('kwtsms.phone_normalizer'),
      $container->get('kwtsms.template_renderer'),
      $container->get('tempstore.private'),
      $container->get('event_dispatcher'),
      $container->get('kwtsms.logger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_otp_login_form';
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
      '#description' => $this->t('Enter your mobile phone number to receive a verification code.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Send Code'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
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

    if ($this->otpProvider->checkResendCooldown($phone)) {
      $form_state->setErrorByName('phone', $this->t('A code was recently sent. Please wait before requesting another.'));
      return;
    }

    // Store normalized phone on form state for use in submitForm.
    $form_state->set('normalized_phone', $phone);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $phone = (string) $form_state->get('normalized_phone');
    if ($phone === '') {
      $phone = $this->phoneNormalizer->normalize(trim((string) $form_state->getValue('phone')));
    }

    // Dispatch OTP request event (CAPTCHA integration point).
    $event = new OtpRequestEvent($phone, 'login', $this->getRequest()->getClientIp() ?? '');
    $this->eventDispatcher->dispatch($event, OtpRequestEvent::EVENT_NAME);
    if ($event->isBlocked()) {
      // Silently log, don't reveal to user (anti-enumeration).
      $this->smsLogger->info('OTP request blocked: @reason', ['@reason' => $event->getBlockReason()]);
      // Still show the same neutral message.
    }

    // Look up user, but never reveal whether one was found (anti-enumeration).
    $uid  = $this->otpProvider->findUserByPhone($phone);
    $code = $this->otpProvider->generateOtp($phone, 'login', $uid ?? 0);

    if ($uid !== NULL) {
      // Only send SMS when a matching account exists.
      $message = $this->templateRenderer->render(
        'otp_login',
        [],
        ['[kwtsms:otp-code]' => $code],
        NULL,
      );

      if ($message !== NULL) {
        $this->gateway->send(
          $phone,
          $message,
          'otp_login',
          ['skip_balance_check' => FALSE],
        );
      }
    }

    // Store the normalized phone for the verify step.
    $store = $this->tempStoreFactory->get('kwtsms_otp');
    $store->set('otp_phone', $phone);

    // Anti-enumeration: same message regardless of whether user exists.
    $this->messenger()->addStatus(
      $this->t('If an account exists with this phone number, a code has been sent.')
    );

    $form_state->setRedirectUrl(Url::fromRoute('kwtsms.otp_verify'));
  }

}
