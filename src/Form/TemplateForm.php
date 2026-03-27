<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating or editing a single kwtsms_template entity.
 */
class TemplateForm extends FormBase {

  /**
   * Constructs a TemplateForm instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_template_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $kwtsms_template = NULL): array {
    $template = NULL;
    $isNew = TRUE;

    if ($kwtsms_template !== NULL) {
      /** @var \Drupal\kwtsms\Entity\SmsTemplate|null $template */
      $template = $this->entityTypeManager
        ->getStorage('kwtsms_template')
        ->load($kwtsms_template);
      $isNew = FALSE;
    }

    $isSystem = $template !== NULL && $template->isSystem();

    $categoryOptions = [
      'authentication' => $this->t('Authentication'),
      'notifications'  => $this->t('Notifications'),
      'admin_alerts'   => $this->t('Admin Alerts'),
      'commerce'       => $this->t('Commerce'),
    ];

    $recipientOptions = [
      'customer' => $this->t('Customer'),
      'admin'    => $this->t('Admin'),
      'both'     => $this->t('Both'),
    ];

    // Store template ID in form state for submit handler.
    $form_state->set('kwtsms_template_id', $kwtsms_template);

    if ($isSystem) {
      $form['label'] = [
        '#type'   => 'markup',
        '#markup' => '<p><strong>' . $this->t('Label') . ':</strong> ' . htmlspecialchars((string) $template->label(), ENT_QUOTES, 'UTF-8') . '</p>',
      ];

      $form['category'] = [
        '#type'   => 'markup',
        '#markup' => '<p><strong>' . $this->t('Category') . ':</strong> ' . htmlspecialchars((string) ($categoryOptions[$template->getCategory()] ?? $template->getCategory()), ENT_QUOTES, 'UTF-8') . '</p>',
      ];

      $form['recipient_type'] = [
        '#type'   => 'markup',
        '#markup' => '<p><strong>' . $this->t('Recipient Type') . ':</strong> ' . htmlspecialchars((string) ($recipientOptions[$template->getRecipientType()] ?? $template->getRecipientType()), ENT_QUOTES, 'UTF-8') . '</p>',
      ];
    }
    else {
      $form['label'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Label'),
        '#default_value' => $template !== NULL ? $template->label() : '',
        '#required'      => TRUE,
        '#maxlength'     => 255,
      ];

      if ($isNew) {
        $form['id'] = [
          '#type'          => 'machine_name',
          '#title'         => $this->t('Machine name'),
          '#machine_name'  => [
            'exists' => [$this, 'templateExists'],
            'source' => ['label'],
          ],
          '#required'  => TRUE,
          '#maxlength' => 64,
        ];
      }

      $form['category'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Category'),
        '#options'       => $categoryOptions,
        '#default_value' => $template !== NULL ? $template->getCategory() : 'notifications',
        '#required'      => TRUE,
      ];

      $form['recipient_type'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Recipient Type'),
        '#options'       => $recipientOptions,
        '#default_value' => $template !== NULL ? $template->getRecipientType() : 'customer',
        '#required'      => TRUE,
      ];
    }

    $tokenHelp = $this->t('Available tokens: [site:name], [user:display-name], [user:mail], [kwtsms:otp-code], [kwtsms:sender-id], [kwtsms:balance]');

    $form['body_en'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Message body (English)'),
      '#default_value' => $template !== NULL ? $template->getBodyEn() : '',
      '#rows'          => 5,
      '#description'   => $this->t('Standard SMS: up to 160 characters (1 page). Unicode SMS (Arabic/emoji): up to 70 characters (1 page).') . ' ' . $tokenHelp,
      '#required'      => TRUE,
    ];

    $form['body_ar'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Message body (Arabic)'),
      '#default_value' => $template !== NULL ? $template->getBodyAr() : '',
      '#rows'          => 5,
      '#attributes'    => ['dir' => 'rtl', 'lang' => 'ar'],
      '#description'   => $tokenHelp,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Save'),
    ];

    if (!$isNew && !$isSystem) {
      $form['actions']['delete'] = [
        '#type'   => 'submit',
        '#value'  => $this->t('Delete'),
        '#submit' => ['::deleteTemplate'],
        '#attributes' => ['class' => ['button', 'button--danger']],
        '#limit_validation_errors' => [],
      ];
    }

    return $form;
  }

  /**
   * Checks whether a template machine name already exists.
   *
   * @param string $id
   *   The machine name to check.
   *
   * @return bool
   *   TRUE if a template with this ID already exists.
   */
  public function templateExists(string $id): bool {
    return $this->entityTypeManager
      ->getStorage('kwtsms_template')
      ->load($id) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $templateId = $form_state->get('kwtsms_template_id');

    if ($templateId === NULL) {
      // Create new template.
      /** @var \Drupal\kwtsms\Entity\SmsTemplate $template */
      $template = $this->entityTypeManager
        ->getStorage('kwtsms_template')
        ->create([
          'id'             => $form_state->getValue('id'),
          'label'          => $form_state->getValue('label'),
          'category'       => $form_state->getValue('category'),
          'recipient_type' => $form_state->getValue('recipient_type'),
          'body_en'        => $form_state->getValue('body_en'),
          'body_ar'        => (string) ($form_state->getValue('body_ar') ?? ''),
          'system'         => FALSE,
        ]);
    }
    else {
      /** @var \Drupal\kwtsms\Entity\SmsTemplate|null $template */
      $template = $this->entityTypeManager
        ->getStorage('kwtsms_template')
        ->load($templateId);

      if ($template === NULL) {
        $this->messenger()->addError($this->t('Template not found.'));
        return;
      }

      $template->set('body_en', $form_state->getValue('body_en'));
      $template->set('body_ar', (string) ($form_state->getValue('body_ar') ?? ''));

      if (!$template->isSystem()) {
        $template->set('label', $form_state->getValue('label'));
        $template->set('category', $form_state->getValue('category'));
        $template->set('recipient_type', $form_state->getValue('recipient_type'));
      }
    }

    $template->save();

    $this->messenger()->addStatus($this->t('Template %label has been saved.', [
      '%label' => $template->label(),
    ]));

    $form_state->setRedirectUrl(Url::fromRoute('kwtsms.templates'));
  }

  /**
   * Submit handler for the Delete button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function deleteTemplate(array &$form, FormStateInterface $form_state): void {
    $templateId = $form_state->get('kwtsms_template_id');

    if ($templateId === NULL) {
      return;
    }

    /** @var \Drupal\kwtsms\Entity\SmsTemplate|null $template */
    $template = $this->entityTypeManager
      ->getStorage('kwtsms_template')
      ->load($templateId);

    if ($template === NULL) {
      $this->messenger()->addError($this->t('Template not found.'));
      return;
    }

    $label = $template->label();
    $template->delete();

    $this->messenger()->addStatus($this->t('Template %label has been deleted.', [
      '%label' => $label,
    ]));

    $form_state->setRedirectUrl(Url::fromRoute('kwtsms.templates'));
  }

}
