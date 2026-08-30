<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio_vbo\Form;

use Drupal\ai_image_studio_vbo\Service\BulkGenerationManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms regeneration of one bulk image job item.
 */
final class RegenerateItemForm extends ConfirmFormBase {

  /**
   * The parent bulk job ID.
   */
  private int $jobId;

  /**
   * The bulk job item ID.
   */
  private int $itemId;

  /**
   * Constructs the regeneration confirmation form.
   */
  public function __construct(
    private readonly BulkGenerationManager $bulkManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ai_image_studio_vbo.batch_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_image_studio_vbo_regenerate_item';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, int $job_id = 0, int $item_id = 0): array {
    $this->jobId = $job_id;
    $this->itemId = $item_id;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    $item = $this->bulkManager->loadItem($this->itemId);
    return (string) $this->t('Regenerate the image for @label?', [
      '@label' => $item?->label ?? $this->t('this item'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('The existing Media item remains in place until the replacement is generated and published successfully.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Regenerate');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('ai_image_studio_vbo.job', ['job_id' => $this->jobId]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->bulkManager->regenerateItem($this->jobId, $this->itemId);
    $this->messenger()->addStatus($this->t('The image has been queued for regeneration.'));
    $form_state->setRedirect('ai_image_studio_vbo.job', ['job_id' => $this->jobId]);
  }

  /**
   * Checks permission and access to the requested job item.
   */
  public function access(AccountInterface $account, int $job_id, int $item_id): AccessResult {
    $job = $this->bulkManager->loadJob($job_id);
    $item = $this->bulkManager->loadItem($item_id);
    $allowed = $job !== NULL
      && $item !== NULL
      && (int) $item->job_id === $job_id
      && !in_array($item->status, ['queued', 'processing'], TRUE)
      && $account->hasPermission('run ai image studio vbo generation')
      && ($account->hasPermission('view any ai image studio vbo job')
        || ($account->hasPermission('view ai image studio vbo jobs')
          && (int) $job->uid === (int) $account->id()));
    return AccessResult::allowedIf($allowed)
      ->addCacheContexts(['user', 'user.permissions'])
      ->setCacheMaxAge(0);
  }

}
