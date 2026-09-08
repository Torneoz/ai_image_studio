<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_storyboard\Service\StoryboardBulkManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures and queues video sequences from storyboard keyframes.
 */
final class GenerateVideoSequencesForm extends FormBase {

  /**
   * The storyboard being processed.
   */
  private object $storyboard;

  public function __construct(
    private readonly StoryboardBulkManager $bulkManager,
    private readonly ImageGenerator $imageGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_storyboard.bulk_manager'),
      $container->get('ai_image_studio.generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_storyboard_generate_video_sequences_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?object $ai_storyboard = NULL): array {
    $this->storyboard = $ai_storyboard;
    $mode = (string) ($ai_storyboard->get('video_sequence_mode')->value ?: 'animate');
    $image_models = $this->imageGenerator->getModelOptions('image_to_video');
    $reference_models = $this->imageGenerator->getModelOptions('reference_to_video');
    $models = $image_models + $reference_models;

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Create one video clip from each generated keyframe, or bridge each pair of consecutive keyframes. Requests run through the shared Bulk Jobs facility and may incur provider charges.') . '</p>',
    ];
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Sequence mode'),
      '#options' => [
        'animate' => $this->t('Animate each keyframe'),
        'bridge' => $this->t('Bridge consecutive keyframes'),
      ],
      '#default_value' => $mode,
      '#description' => $this->t('Bridge mode supplies the current and following frames as references and therefore creates one fewer clip.'),
    ];
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider and video model'),
      '#options' => $models,
      '#default_value' => $ai_storyboard->get('video_model')->value ?: (string) array_key_first($models),
      '#required' => TRUE,
      '#empty_value' => '',
      '#description' => $this->t('Bridge mode requires a model that supports reference-to-video generation.'),
    ];
    $form['duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Clip duration'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 1,
      '#max' => 15,
      '#default_value' => (int) ($ai_storyboard->get('video_duration')->value ?: 5),
      '#required' => TRUE,
    ];
    $form['resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Resolution'),
      '#options' => [
        '480p' => $this->t('480p — faster and lower cost'),
        '720p' => $this->t('720p — standard'),
        '1080p' => $this->t('1080p — provider dependent'),
      ],
      '#default_value' => $ai_storyboard->get('video_resolution')->value ?: '720p',
    ];
    $form['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sequence direction'),
      '#default_value' => $ai_storyboard->get('video_prompt')->value,
      '#rows' => 5,
      '#description' => $this->t('Project-wide motion, pacing, performance, and transition direction. Each shot’s action and camera movement are appended automatically.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['generate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate video sequences'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $ai_storyboard->toUrl(),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('mode') === 'bridge'
      && !$this->imageGenerator->supportsReferenceVideo((string) $form_state->getValue('model'))) {
      $form_state->setErrorByName('model', $this->t('Choose a reference-to-video capable model for bridge mode.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $settings = [
      'mode' => (string) $form_state->getValue('mode'),
      'model' => (string) $form_state->getValue('model'),
      'duration' => (int) $form_state->getValue('duration'),
      'resolution' => (string) $form_state->getValue('resolution'),
      'prompt' => trim((string) $form_state->getValue('prompt')),
    ];
    $this->storyboard
      ->set('video_sequence_mode', $settings['mode'])
      ->set('video_model', $settings['model'])
      ->set('video_duration', $settings['duration'])
      ->set('video_resolution', $settings['resolution'])
      ->set('video_prompt', $settings['prompt'])
      ->save();
    try {
      $job_id = $this->bulkManager->enqueueVideoSequences(
        $this->storyboard,
        (int) $this->currentUser()->id(),
        $settings,
      );
      $this->messenger()->addStatus($this->t('Storyboard video sequences have been queued.'));
      $form_state->setRedirect('ai_image_studio_vbo.job', ['job_id' => $job_id]);
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->setRedirect('entity.ai_storyboard.canonical', [
        'ai_storyboard' => $this->storyboard->id(),
      ]);
    }
  }

}
