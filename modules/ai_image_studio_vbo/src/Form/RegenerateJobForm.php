<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio_vbo\Form;

use Drupal\ai_image_studio_vbo\Plugin\Action\GenerateNodeImages;
use Drupal\ai_image_studio_vbo\Service\BulkGenerationManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\views_bulk_operations\Service\ViewsBulkOperationsActionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Collects replacement settings before regenerating a complete bulk job.
 */
final class RegenerateJobForm extends FormBase {

  /**
   * The bulk job ID.
   */
  private int $jobId;

  /**
   * Constructs the bulk-job regeneration form.
   */
  public function __construct(
    private readonly BulkGenerationManager $bulkManager,
    private readonly ViewsBulkOperationsActionManager $actionManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_image_studio_vbo.batch_manager'),
      $container->get('plugin.manager.views_bulk_operations_action'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_image_studio_vbo_regenerate_job';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $job_id = 0): array {
    $this->jobId = $job_id;
    $job = $this->bulkManager->loadJob($job_id);
    if ($job === NULL) {
      return ['#markup' => $this->t('The bulk job does not exist.')];
    }
    $action = $this->action($job->configuration);
    $context = ['list' => $this->selectionContext($job_id), 'sandbox' => []];
    $action->setContext($context);
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Choose replacement settings. Every node originally selected for this job will be queued again.') . '</p>',
    ];
    $form = $action->buildConfigurationForm($form, $form_state);
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#button_type' => 'primary',
        '#value' => $this->t('Regenerate all'),
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => $this->jobUrl(),
        '#attributes' => ['class' => ['button']],
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $job = $this->bulkManager->loadJob($this->jobId);
    $this->action($job?->configuration ?? [])->validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $job = $this->bulkManager->loadJob($this->jobId);
    $action = $this->action($job?->configuration ?? []);
    $action->submitConfigurationForm($form, $form_state);
    $count = $this->bulkManager->regenerateJob($this->jobId, $action->getConfiguration());
    $this->messenger()->addStatus($this->formatPlural(
      $count,
      'One image has been queued for regeneration.',
      '@count images have been queued for regeneration.',
    ));
    $form_state->setRedirect('ai_image_studio_vbo.job', ['job_id' => $this->jobId]);
  }

  /**
   * Checks permission and ownership of the requested job.
   */
  public function access(AccountInterface $account, int $job_id): AccessResult {
    $job = $this->bulkManager->loadJob($job_id);
    $has_active_items = array_filter(
      $this->bulkManager->itemsForJob($job_id),
      static fn (object $item): bool => in_array($item->status, ['queued', 'processing'], TRUE),
    );
    $allowed = $job !== NULL
      && $has_active_items === []
      && $account->hasPermission('run ai image studio vbo generation')
      && ($account->hasPermission('view any ai image studio vbo job')
        || ($account->hasPermission('view ai image studio vbo jobs')
          && (int) $job->uid === (int) $account->id()));
    return AccessResult::allowedIf($allowed)
      ->addCacheContexts(['user', 'user.permissions'])
      ->setCacheMaxAge(0);
  }

  /**
   * Creates the configurable bulk-generation action.
   */
  private function action(array $configuration): GenerateNodeImages {
    $action = $this->actionManager->createInstance(
      'ai_image_studio_generate_node_images',
      $configuration,
    );
    assert($action instanceof GenerateNodeImages);
    return $action;
  }

  /**
   * Builds the VBO-style selected-node context expected by the action form.
   */
  private function selectionContext(int $job_id): array {
    $context = [];
    // Item loading remains encapsulated by the manager; IDs are sufficient to
    // infer a common bundle in the action form.
    foreach ($this->bulkManager->itemsForJob($job_id) as $item) {
      $context[] = [
        (string) $item->node_id,
        (string) $item->langcode,
        'node',
        (string) $item->node_id,
      ];
    }
    return $context;
  }

  /**
   * Returns the current bulk job URL.
   */
  private function jobUrl(): Url {
    return Url::fromRoute('ai_image_studio_vbo.job', [
      'job_id' => $this->jobId,
    ]);
  }

}
