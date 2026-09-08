<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\ai_storyboard\Service\StoryboardBulkManager;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms queueing every shot in a storyboard project. */
final class GenerateAllForm extends ConfirmFormBase {

  /**
   * The storyboard being generated.
   */
  private object $storyboard;

  public function __construct(private readonly StoryboardBulkManager $bulkManager) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ai_storyboard.bulk_manager'));
  }

  /**
   * {@inheritdoc} */
  public function getFormId(): string {
    return 'ai_storyboard_generate_all_form';
  }

  /**
   * {@inheritdoc} */
  public function getQuestion(): string {
    return (string) $this->t('Generate every frame in “@title”?', [
      '@title' => $this->storyboard->label(),
    ]);
  }

  /**
   * {@inheritdoc} */
  public function getDescription(): string {
    return (string) $this->t('One provider request will be queued for every shot. Existing frames will be regenerated and retained in the linked Image Studio session history. Provider charges may apply.');
  }

  /**
   * {@inheritdoc} */
  public function getConfirmText(): string {
    return (string) $this->t('Generate all frames');
  }

  /**
   * {@inheritdoc} */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.ai_storyboard.canonical', [
      'ai_storyboard' => $this->storyboard->id(),
    ]);
  }

  /**
   * {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state, ?object $ai_storyboard = NULL): array {
    $this->storyboard = $ai_storyboard;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $job_id = $this->bulkManager->enqueueProject(
      $this->storyboard,
      (int) $this->currentUser()->id(),
    );
    $this->messenger()->addStatus($this->t('All storyboard frames have been queued.'));
    $form_state->setRedirect('ai_storyboard.bulk_job', ['job_id' => $job_id]);
  }

}
