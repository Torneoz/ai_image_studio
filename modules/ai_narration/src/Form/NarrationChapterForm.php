<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationDurationInspector;
use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\ai_narration\Service\NarrationMediaPublisher;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uses a project selector for narration chapters.
 */
final class NarrationChapterForm extends ContentEntityForm {

  protected NarrationGenerator $generator;

  protected FileUrlGeneratorInterface $fileUrlGenerator;

  protected NarrationMediaPublisher $mediaPublisher;

  protected ?NarrationDurationInspector $durationInspector = NULL;

  public function __construct(
    $entity_repository,
    $entity_type_bundle_info,
    $time,
    NarrationGenerator $generator,
    FileUrlGeneratorInterface $file_url_generator,
    NarrationMediaPublisher $media_publisher,
    NarrationDurationInspector $duration_inspector,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->generator = $generator;
    $this->fileUrlGenerator = $file_url_generator;
    $this->mediaPublisher = $media_publisher;
    $this->durationInspector = $duration_inspector;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('ai_narration.generator'),
      $container->get('file_url_generator'),
      $container->get('ai_narration.media_publisher'),
      $container->get('ai_narration.duration_inspector'),
    );
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $projects = $this->entityTypeManager->getStorage('ai_narration_project')->loadMultiple();
    $options = [];
    foreach ($projects as $project) {
      $options[$project->id()] = (string) $project->label();
    }
    $element =& $form['project_id']['widget'][0]['target_id'];
    $element['#type'] = 'select';
    $element['#options'] = $options;
    $requested_project = (int) \Drupal::request()->query->get('project', 0);
    $element['#default_value'] = $this->entity->get('project_id')->target_id ?: (isset($options[$requested_project]) ? $requested_project : NULL);
    $element['#empty_option'] = $this->t('- Select a project -');
    $element['#multiple'] = FALSE;
    $element['#size'] = 1;
    $element['#description'] = $this->t('Choose the narration project whose settings this chapter will inherit.');
    if (!$this->entity->isNew()) {
      $this->buildJobSettings($form, $form_state);
      $this->buildPreviousGenerationResult($form, $form_state);
      $form['generate_narration'] = [
        '#type' => 'submit',
        '#value' => $this->t('Generate narration'),
        '#weight' => 89,
        '#submit' => ['::submitForm', '::save', '::generateNarration'],
        '#ajax' => [
          'callback' => '::ajaxGenerate',
          'wrapper' => 'ai-narration-chapter-feedback',
          'progress' => ['type' => 'throbber', 'message' => $this->t('Generating narration…')],
        ],
      ];
      $form['#attached']['library'][] = 'ai_narration/character_counter';
      $form['generation_feedback'] = [
        '#type' => 'container',
        '#weight' => 90,
        '#attributes' => ['id' => 'ai-narration-chapter-feedback', 'class' => ['ai-narration-result']],
      ];
      $this->buildGenerationFeedback($form['generation_feedback'], $form_state);
    }
    return $form;
  }

  /**
   * Builds non-persistent overrides for the current generation only.
   */
  private function buildJobSettings(array &$form, FormStateInterface $form_state): void {
    $project = $this->entity->get('project_id')->entity;
    if ($project === NULL) {
      return;
    }
    $defaults = [
      'model' => (string) $project->get('model')->value,
      'voice' => (string) $project->get('voice')->value,
      'format' => (string) $project->get('format')->value,
      'speed' => (string) $project->get('speed')->value ?: '1',
      'language' => (string) $project->get('language')->value,
      'text_normalization' => (bool) $project->get('text_normalization')->value,
    ];
    $settings = $form_state->getValue('job_settings') ?? [];
    $models = $this->generator->getModelOptions();
    $model = (string) ($settings['model'] ?? $defaults['model']);
    $model = isset($models[$model]) ? $model : '';
    $voices = $model === '' ? [] : $this->generator->getVoiceOptions($model);
    $formats = $model === '' ? [] : $this->generator->getFormatOptions($model);
    $provider = explode('__', $model, 2)[0] ?? '';

    $form['job_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Project Narration settings'),
      '#description' => $this->t('These values start with the parent Project defaults. Changes are used only for this generation and do not alter the Project or Chapter.'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#weight' => 80,
      '#attributes' => ['id' => 'ai-narration-chapter-job-settings'],
    ];
    $form['job_settings']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Text-to-speech model'),
      '#options' => $models,
      '#empty_option' => $this->t('- Select a model -'),
      '#default_value' => $model,
      '#description' => $this->t('Provider and model for this one generation.'),
      '#ajax' => ['callback' => '::refreshJobSettings', 'wrapper' => 'ai-narration-chapter-job-settings', 'progress' => ['type' => 'throbber'], 'event' => 'change'],
    ];
    $form['job_settings']['voice'] = [
      '#type' => 'select',
      '#title' => $this->t('Voice'),
      '#options' => $voices,
      '#empty_option' => $this->t('- Provider default -'),
      '#default_value' => isset($voices[$settings['voice'] ?? $defaults['voice']]) ? ($settings['voice'] ?? $defaults['voice']) : '',
      '#description' => $this->t('Voice used for this one generation.'),
    ];
    $form['job_settings']['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Output format'),
      '#options' => $formats,
      '#empty_option' => $this->t('- Provider default -'),
      '#default_value' => isset($formats[$settings['format'] ?? $defaults['format']]) ? ($settings['format'] ?? $defaults['format']) : '',
      '#description' => $this->t('Audio encoding for this one generation when supported by the provider.'),
    ];
    $form['job_settings']['speed'] = [
      '#type' => 'number',
      '#title' => $this->t('Speech speed'),
      '#default_value' => $settings['speed'] ?? $defaults['speed'],
      '#min' => $provider === 'openai' ? 0.25 : 0.7,
      '#max' => $provider === 'openai' ? 4 : ($provider === 'elevenlabs' ? 1.2 : 1.5),
      '#step' => 0.05,
      '#access' => in_array($provider, ['openai', 'grok', 'elevenlabs'], TRUE),
      '#description' => $this->t('Playback-speed multiplier for this one generation.'),
    ];
    $form['job_settings']['language'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Language'),
      '#default_value' => $settings['language'] ?? $defaults['language'],
      '#access' => in_array($provider, ['gemini', 'grok', 'elevenlabs'], TRUE),
      '#description' => $this->t('Optional language or locale hint for this generation.'),
    ];
    $form['job_settings']['text_normalization'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Normalize text before speaking'),
      '#default_value' => $settings['text_normalization'] ?? $defaults['text_normalization'],
      '#access' => $provider === 'grok',
      '#description' => $this->t('Expands numbers, abbreviations, and symbols before synthesis.'),
    ];
  }

  public function refreshJobSettings(array &$form, FormStateInterface $form_state): array {
    return $form['job_settings'];
  }

  /**
   * Shows the durable result saved by the last successful generation.
   */
  private function buildPreviousGenerationResult(array &$form, FormStateInterface $form_state): void {
    $file = $this->entity->get('last_generated_audio')->entity;
    $metadata = json_decode((string) $this->entity->get('generation_metadata')->value, TRUE) ?: [];
    if ($file === NULL && $metadata === []) {
      return;
    }
    $form['previous_generation_result'] = [
      '#type' => 'details',
      '#title' => $this->t('Previous Generation Result'),
      '#open' => FALSE,
      '#weight' => 85,
      '#attributes' => ['id' => 'ai-narration-chapter-previous-result', 'class' => ['ai-narration-result']],
    ];
    if ($file) {
      $url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      $form['previous_generation_result']['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['ai-narration-result__actions']]];
      $form['previous_generation_result']['actions']['audio'] = [
        '#type' => 'html_tag',
        '#tag' => 'audio',
        '#attributes' => ['controls' => TRUE, 'src' => $url],
      ];
      $form['previous_generation_result']['actions']['download'] = [
        '#type' => 'link',
        '#title' => $this->t('Download previous audio'),
        '#url' => Url::fromUri($url),
        '#attributes' => ['download' => TRUE, 'class' => ['button', 'button--primary']],
      ];
      if ($media_url = $form_state->get('previous_media_url')) {
        $form['previous_generation_result']['actions']['view_media'] = [
          '#type' => 'link',
          '#title' => $this->t('View previous audio in Media Library'),
          '#url' => Url::fromUri($media_url),
          '#attributes' => ['class' => ['button']],
        ];
      }
      else {
        $form['previous_generation_result']['actions']['save_media'] = [
          '#type' => 'submit',
          '#value' => $this->t('Save previous to Media Library'),
          '#name' => 'save_previous_media',
          '#submit' => ['::savePreviousToMedia'],
          '#limit_validation_errors' => [],
          '#ajax' => ['callback' => '::ajaxGenerate', 'wrapper' => 'ai-narration-chapter-feedback'],
          '#attributes' => ['class' => ['button']],
        ];
      }
    }
    if ($metadata !== []) {
      $items = [];
      foreach ($metadata as $key => $value) {
        if (is_array($value)) {
          $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        if ($value !== NULL && $value !== '') {
          $items[] = $this->t('@key: @value', ['@key' => ucwords(str_replace('_', ' ', (string) $key)), '@value' => (string) $value]);
        }
      }
      $form['previous_generation_result']['metadata'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Saved generation metadata'),
        '#items' => $items,
      ];
    }
  }

  /**
   * Generates audio using the selected project's shared settings.
   */
  public function generateNarration(array &$form, FormStateInterface $form_state): void {
    try {
      $chapter = $this->entity;
      $project = $chapter->get('project_id')->entity;
      if ($project === NULL) {
        throw new \RuntimeException('Choose a narration project before generating audio.');
      }
      $text = (string) $chapter->get('text')->value;
      $settings = $form_state->getValue('job_settings') ?? [];
      $model = (string) ($settings['model'] ?? $project->get('model')->value);
      if ($model === '') {
        throw new \RuntimeException('The selected narration project has no text-to-speech model.');
      }
      $limit = (int) $project->get('max_input_characters')->value;
      if ($limit > 0 && mb_strlen($text) > $limit) {
        throw new \RuntimeException((string) $this->t('This chapter is @characters characters, exceeding the project limit of @limit. Split it into smaller chapters before generating.', ['@characters' => mb_strlen($text), '@limit' => number_format($limit)]));
      }
      $started = microtime(TRUE);
      $voice = (string) ($settings['voice'] ?? $project->get('voice')->value);
      $format = (string) ($settings['format'] ?? $project->get('format')->value);
      $parameters = [
        'language' => (string) ($settings['language'] ?? $project->get('language')->value),
        'speed' => $settings['speed'] ?? ($project->get('speed')->value ?: 1),
        'text_normalization' => (bool) ($settings['text_normalization'] ?? $project->get('text_normalization')->value),
      ];
      $file = $this->generator->generate($text, $model, $voice, $format, $parameters, (string) $chapter->label());
      $duration = $this->getDurationInspector()->getDuration($file);
      $seconds = microtime(TRUE) - $started;
      $metadata = [
        'model' => $model,
        'voice' => $voice,
        'format' => $format,
        'parameters' => $parameters,
        'input_characters' => mb_strlen($text),
        'file_id' => (int) $file->id(),
        'bytes' => (int) $file->getSize(),
        'mime' => $file->getMimeType(),
        'runtime_seconds' => $duration,
        'generation_seconds' => $seconds,
        'generated_at' => gmdate(DATE_ATOM),
      ];
      $chapter->get('audio_files')->appendItem(['target_id' => $file->id()]);
      $chapter->set('last_generated_audio', ['target_id' => $file->id()]);
      $chapter->set('generation_metadata', json_encode($metadata, JSON_UNESCAPED_SLASHES));
      $chapter->set('status', 'complete');
      $chapter->set('error_message', '');
      $chapter->save();
      $form_state->set('generation_result', [
        'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
        'fid' => $file->id(),
        'model' => $model,
        'voice' => $voice,
        'format' => $format,
        'characters' => mb_strlen($text),
        'bytes' => $file->getSize(),
        'mime' => $file->getMimeType(),
        'duration' => $duration,
        'seconds' => $seconds,
      ]);
    }
    catch (\Throwable $exception) {
      $this->entity->set('status', 'failed');
      $this->entity->set('error_message', $exception->getMessage());
      $this->entity->save();
      $form_state->set('generation_error', $exception->getMessage());
    }
    $form_state->setRebuild();
  }

  public function ajaxGenerate(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#ai-narration-chapter-feedback', $form['generation_feedback']));
    if (isset($form['previous_generation_result'])) {
      $response->addCommand(new ReplaceCommand('#ai-narration-chapter-previous-result', $form['previous_generation_result']));
    }
    return $response;
  }

  private function buildGenerationFeedback(array &$feedback, FormStateInterface $form_state): void {
    if ($error = $form_state->get('generation_error')) {
      $feedback['#attributes']['class'] = ['messages', 'messages--error', 'ai-narration-result'];
      $feedback['error'] = ['#markup' => $this->t('Narration could not be generated: @error', ['@error' => $error])];
      return;
    }
    if (!$result = $form_state->get('generation_result')) {
      return;
    }
    $feedback['#attributes']['class'] = ['messages', 'messages--status', 'ai-narration-result'];
    $feedback['title'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Narration generated')];
    $feedback['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['ai-narration-result__actions']]];
    $feedback['actions']['player'] = ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $result['url']]];
    $feedback['actions']['download'] = [
      '#type' => 'link',
      '#title' => $this->t('Download @format file (@size)', ['@format' => strtoupper($result['format'] ?: pathinfo($result['url'], PATHINFO_EXTENSION) ?: 'audio'), '@size' => ByteSizeMarkup::create($result['bytes'])]),
      '#url' => Url::fromUri($result['url']),
      '#attributes' => ['download' => TRUE, 'class' => ['button', 'button--primary']],
    ];
    if (empty($result['media_url'])) {
      $feedback['actions']['save_media'] = [
        '#type' => 'submit',
          '#value' => $this->t('Save current to Media Library'),
        '#name' => 'save_media',
        '#submit' => ['::saveToMedia'],
        '#limit_validation_errors' => [],
        '#ajax' => ['callback' => '::ajaxGenerate', 'wrapper' => 'ai-narration-chapter-feedback'],
        '#attributes' => ['class' => ['button']],
      ];
    }
    else {
      $feedback['actions']['view_media'] = [
        '#type' => 'link',
        '#title' => $this->t('View in Media Library'),
        '#url' => Url::fromUri($result['media_url']),
        '#attributes' => ['class' => ['button']],
      ];
    }
    $feedback['metadata'] = ['#theme' => 'item_list', '#title' => $this->t('Generation details'), '#items' => [
      $this->t('Model: @model', ['@model' => $result['model']]),
      $result['voice'] === '' ? $this->t('Voice: provider default') : $this->t('Voice: @voice', ['@voice' => $result['voice']]),
      $this->t('Input: @characters characters', ['@characters' => $result['characters']]),
      $this->t('File: @size bytes (@mime)', ['@size' => number_format($result['bytes']), '@mime' => $result['mime']]),
      $result['duration'] === NULL ? $this->t('Clip runtime: unavailable') : $this->t('Clip runtime: @duration', ['@duration' => $this->formatDuration($result['duration'])]),
      $this->t('Generation time: @seconds seconds', ['@seconds' => number_format($result['seconds'], 2)]),
      $this->t('Provider-reported price: unavailable'),
    ]];
  }

  private function getDurationInspector(): NarrationDurationInspector {
    return $this->durationInspector ??= \Drupal::service('ai_narration.duration_inspector');
  }

  /**
   * Publishes the most recently generated chapter audio to Media.
   */
  public function saveToMedia(array &$form, FormStateInterface $form_state): void {
    $result = $form_state->get('generation_result');
    $file = !empty($result['fid']) ? $this->entityTypeManager->getStorage('file')->load($result['fid']) : NULL;
    if (!$file) {
      $form_state->set('generation_error', $this->t('The generated narration file is no longer available.'));
      $form_state->setRebuild();
      return;
    }
    try {
      $media = $this->mediaPublisher->publish($file, (string) $this->t('AI narration: @title', ['@title' => $this->entity->label()]));
      $result['media_url'] = $media->toUrl('canonical', ['absolute' => TRUE])->toString();
      $form_state->set('generation_result', $result);
    }
    catch (\Throwable $exception) {
      $form_state->set('generation_error', $exception->getMessage());
    }
    $form_state->setRebuild();
  }

  /**
   * Publishes the Chapter's stored previous-generation file to Media.
   */
  public function savePreviousToMedia(array &$form, FormStateInterface $form_state): void {
    $file = $this->entity->get('last_generated_audio')->entity;
    if (!$file) {
      $form_state->set('generation_error', $this->t('The previous narration file is no longer available.'));
      $form_state->setRebuild();
      return;
    }
    try {
      $media = $this->mediaPublisher->publish($file, (string) $this->t('AI narration: @title', ['@title' => $this->entity->label()]));
      $form_state->set('previous_media_url', $media->toUrl('canonical', ['absolute' => TRUE])->toString());
    }
    catch (\Throwable $exception) {
      $form_state->set('generation_error', $exception->getMessage());
    }
    $form_state->setRebuild();
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Narration chapter %title has been saved.', ['%title' => $this->entity->label()]));
    $form_state->setRedirect('entity.ai_narration_chapter.collection');
    return $result;
  }

  private function formatDuration(float $seconds): string {
    $seconds = (int) round($seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
  }

}
