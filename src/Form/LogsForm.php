<?php

declare(strict_types=1);

namespace Drupal\kwtsms\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\kwtsms\Service\SmsLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays and filters SMS logs with pagination and export/clear actions.
 */
class LogsForm extends FormBase {

  /**
   * Number of log rows shown per page.
   */
  private const LOGS_PER_PAGE = 50;

  /**
   * Constructs a LogsForm instance.
   *
   * @param \Drupal\kwtsms\Service\SmsLogger $smsLogger
   *   The kwtSMS SMS logger service.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager service.
   */
  public function __construct(
    private readonly SmsLogger $smsLogger,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kwtsms.logger'),
      $container->get('pager.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kwtsms_logs_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Persist filters across rebuilds.
    $filters = $form_state->get('filters') ?? [];

    $form['filters'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Filter Logs'),
    ];

    $form['filters']['status'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Status'),
      '#options'       => [
        ''       => $this->t('All statuses'),
        'sent'    => $this->t('Sent'),
        'failed'  => $this->t('Failed'),
        'skipped' => $this->t('Skipped'),
      ],
      '#default_value' => $filters['status'] ?? '',
    ];

    $form['filters']['event_type'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Event Type'),
      '#options'       => [
        ''               => $this->t('All event types'),
        'manual'         => $this->t('Manual'),
        'otp'            => $this->t('OTP'),
        'user_register'  => $this->t('User Registration'),
        'order_placed'   => $this->t('Order Placed'),
      ],
      '#default_value' => $filters['event_type'] ?? '',
    ];

    $form['filters']['date_from'] = [
      '#type'          => 'date',
      '#title'         => $this->t('Date from'),
      '#default_value' => $filters['date_from'] ?? '',
    ];

    $form['filters']['date_to'] = [
      '#type'          => 'date',
      '#title'         => $this->t('Date to'),
      '#default_value' => $filters['date_to'] ?? '',
    ];

    $form['filters']['filter'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Filter'),
      '#name'  => 'filter',
    ];

    // Build active filters for the query.
    $activeFilters = [];

    if (!empty($filters['status'])) {
      $activeFilters['status'] = $filters['status'];
    }

    if (!empty($filters['event_type'])) {
      $activeFilters['event_type'] = $filters['event_type'];
    }

    if (!empty($filters['date_from'])) {
      $activeFilters['date_from'] = strtotime($filters['date_from'] . ' 00:00:00');
    }

    if (!empty($filters['date_to'])) {
      $activeFilters['date_to'] = strtotime($filters['date_to'] . ' 23:59:59');
    }

    $page = (int) \Drupal::request()->query->get('page', 0);
    $offset = $page * self::LOGS_PER_PAGE;

    // Fetch one extra row to detect if there are more pages.
    $logs = $this->smsLogger->getLogs($activeFilters, self::LOGS_PER_PAGE + 1, $offset);
    $hasMore = count($logs) > self::LOGS_PER_PAGE;
    if ($hasMore) {
      array_pop($logs);
    }

    // Initialize the pager.
    $this->pagerManager->createPager(
      $hasMore ? ($offset + self::LOGS_PER_PAGE + 1) : ($offset + count($logs)),
      self::LOGS_PER_PAGE,
    );

    $header = [
      $this->t('Date'),
      $this->t('Recipient'),
      $this->t('Template'),
      $this->t('Status'),
      $this->t('Message'),
      $this->t('Error Code'),
      $this->t('Error Description'),
    ];

    $rows = [];
    foreach ($logs as $log) {
      $date = date('Y-m-d H:i', (int) ($log->created ?? 0));
      $message = (string) ($log->message ?? '');
      $truncated = mb_strlen($message) > 50 ? mb_substr($message, 0, 50) . '...' : $message;

      $rows[] = [
        $date,
        (string) ($log->recipient ?? ''),
        (string) ($log->template_id ?? ''),
        (string) ($log->status ?? ''),
        $truncated,
        (string) ($log->error_code ?? ''),
        (string) ($log->error_description ?? ''),
      ];
    }

    $form['table'] = [
      '#type'   => 'table',
      '#header' => $header,
      '#rows'   => $rows,
      '#empty'  => $this->t('No log entries found.'),
    ];

    $form['pager'] = [
      '#type' => 'pager',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['export'] = [
      '#type'  => 'link',
      '#title' => $this->t('Export CSV'),
      '#url'   => Url::fromRoute('kwtsms.logs_export'),
      '#attributes' => ['class' => ['button']],
    ];

    $form['actions']['clear'] = [
      '#type'   => 'submit',
      '#value'  => $this->t('Clear All Logs'),
      '#name'   => 'clear',
      '#attributes' => ['class' => ['button', 'button--danger']],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $triggeringElement = $form_state->getTriggeringElement();
    $name = $triggeringElement['#name'] ?? '';

    if ($name === 'clear') {
      $this->smsLogger->clearLogs();
      $this->messenger()->addStatus($this->t('All SMS logs have been cleared.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    // Filter action: store filter values and rebuild.
    $filters = [
      'status'     => (string) ($form_state->getValue('status') ?? ''),
      'event_type' => (string) ($form_state->getValue('event_type') ?? ''),
      'date_from'  => (string) ($form_state->getValue('date_from') ?? ''),
      'date_to'    => (string) ($form_state->getValue('date_to') ?? ''),
    ];

    $form_state->set('filters', $filters);
    $form_state->setRebuild(TRUE);
  }

}
