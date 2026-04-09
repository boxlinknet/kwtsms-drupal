<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays all kwtsms_template entities grouped by category with actions.
 */
class TemplateListForm extends FormBase {

  /**
   * Constructs a TemplateListForm instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_template_list_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\kwtsms\Entity\SmsTemplate[] $templates */
    $templates = $this->entityTypeManager
      ->getStorage('kwtsms_template')
      ->loadMultiple();

    // Group by category.
    $grouped = [];
    foreach ($templates as $template) {
      $grouped[$template->getCategory()][] = $template;
    }

    $form['add'] = [
      '#type'  => 'link',
      '#title' => $this->t('Add Template'),
      '#url'   => Url::fromRoute('kwtsms.template_add'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $categoryLabels = [
      'authentication' => $this->t('Authentication'),
      'notifications'  => $this->t('Notifications'),
      'admin_alerts'   => $this->t('Admin Alerts'),
      'commerce'       => $this->t('Commerce'),
    ];

    $recipientLabels = [
      'customer' => $this->t('Customer'),
      'admin'    => $this->t('Admin'),
      'both'     => $this->t('Both'),
    ];

    $header = [
      $this->t('Label'),
      $this->t('Category'),
      $this->t('Recipient Type'),
      $this->t('System'),
      $this->t('Actions'),
    ];

    $rows = [];
    $resetButtons = [];

    foreach ($grouped as $categoryTemplates) {
      foreach ($categoryTemplates as $template) {
        $id = $template->id();

        $actions = [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => Url::fromRoute('kwtsms.template_edit', ['kwtsms_template' => $id]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ];

        $actionMarkup = $this->renderer->render($actions);

        if ($template->isSystem()) {
          $resetKey = 'reset_' . $id;
          $resetButtons[$resetKey] = [
            '#type'   => 'submit',
            '#value'  => $this->t('Reset to Default'),
            '#name'   => $resetKey,
            '#submit' => ['::submitForm'],
            '#attributes' => ['data-template-id' => $id, 'class' => ['button', 'button--small', 'button--danger']],
          ];
          $resetMarkup = $this->renderer->render($resetButtons[$resetKey]);
          $actionMarkup .= ' ' . $resetMarkup;
        }

        $catLabel = $categoryLabels[$template->getCategory()] ?? $template->getCategory();
        $recLabel = $recipientLabels[$template->getRecipientType()] ?? $template->getRecipientType();

        $rows[] = [
          $template->label(),
          $catLabel,
          $recLabel,
          $template->isSystem() ? $this->t('Yes') : $this->t('No'),
          ['data' => ['#markup' => $actionMarkup]],
        ];
      }
    }

    $form['table'] = [
      '#type'   => 'table',
      '#header' => $header,
      '#rows'   => $rows,
      '#empty'  => $this->t('No templates found.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $triggeringElement = $form_state->getTriggeringElement();
    $attributes = $triggeringElement['#attributes'] ?? [];
    $templateId = $attributes['data-template-id'] ?? NULL;

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

    $template->resetToDefault()->save();
    $this->messenger()->addStatus($this->t('Template %label has been reset to its default.', [
      '%label' => $template->label(),
    ]));
  }

}
