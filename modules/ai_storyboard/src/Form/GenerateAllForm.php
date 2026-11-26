<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\ai_image_studio\Service\BadgePolicy;
use Drupal\ai_image_studio\Service\ImageGenerator;
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

  public function __construct(
    private readonly StoryboardBulkManager $bulkManager,
    private readonly ImageGenerator $imageGenerator,
  ) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_storyboard.bulk_manager'),
      $container->get('ai_image_studio.generator'),
    );
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
    return (string) $this->t('Choose whether to skip shots that already have a generated frame. Each queued shot makes a provider request and may incur charges.');
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
    $form['skip_existing_frames'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skip shots that already have a generated frame'),
      '#default_value' => 1,
      '#description' => $this->t('Clear this option to regenerate every shot. Previous images remain in the linked Image Studio session history.'),
    ];
    $models = $this->imageGenerator->getModelOptions('text_to_image');
    $form['image_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Frame generation model'),
      '#options' => $models,
      '#default_value' => isset($models[$ai_storyboard->get('image_model')->value])
        ? $ai_storyboard->get('image_model')->value
        : (string) array_key_first($models),
      '#required' => TRUE,
      '#description' => $this->t('Regenerated frames use this model, even when it differs from the model used for earlier frames.'),
    ];
    $form['badges'] = BadgePolicy::requestControls($this->config('ai_image_studio.settings')->getRawData());
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $model = (string) $form_state->getValue('image_model');
    $available_models = $this->imageGenerator->getModelOptions('text_to_image');
    if (!isset($available_models[$model])) {
      $this->messenger()->addError($this->t('Select an available frame generation model.'));
      return;
    }
    $this->storyboard->set('image_model', $model)->save();
    $job_id = $this->bulkManager->enqueueProject(
      $this->storyboard,
      (int) $this->currentUser()->id(),
      (array) $form_state->getValue('badges'),
      (bool) $form_state->getValue('skip_existing_frames'),
    );
    $this->messenger()->addStatus($this->t('Eligible storyboard frames have been queued. Shots with existing frames were skipped according to your selection.'));
    $form_state->setRedirect('ai_image_studio_vbo.job', ['job_id' => $job_id]);
  }

}
