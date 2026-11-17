<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationDurationInspector;
use Drupal\ai_narration\Service\NarrationGenerator;
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

  protected ?NarrationDurationInspector $durationInspector = NULL;

  public function __construct(
    $entity_repository,
    $entity_type_bundle_info,
    $time,
    NarrationGenerator $generator,
    FileUrlGeneratorInterface $file_url_generator,
    NarrationDurationInspector $duration_inspector,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->generator = $generator;
    $this->fileUrlGenerator = $file_url_generator;
    $this->durationInspector = $duration_inspector;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('ai_narration.generator'),
      $container->get('file_url_generator'),
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
   * Adds project-driven generation to existing chapter edit forms.
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    if (!$this->entity->isNew()) {
      $actions['generate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Generate narration'),
        '#submit' => ['::submitForm', '::save', '::generateNarration'],
        '#ajax' => [
          'callback' => '::ajaxGenerate',
          'wrapper' => 'ai-narration-chapter-feedback',
          'progress' => ['type' => 'throbber', 'message' => $this->t('Generating narration…')],
        ],
      ];
    }
    return $actions;
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
      $model = (string) $project->get('model')->value;
      if ($model === '') {
        throw new \RuntimeException('The selected narration project has no text-to-speech model.');
      }
      $limit = (int) $project->get('max_input_characters')->value;
      if ($limit > 0 && mb_strlen($text) > $limit) {
        throw new \RuntimeException((string) $this->t('This chapter is @characters characters, exceeding the project limit of @limit. Split it into smaller chapters before generating.', ['@characters' => mb_strlen($text), '@limit' => number_format($limit)]));
      }
      $started = microtime(TRUE);
      $file = $this->generator->generate($text, $model, (string) $project->get('voice')->value, (string) $project->get('format')->value, [
        'language' => (string) $project->get('language')->value,
        'speed' => $project->get('speed')->value ?: 1,
        'text_normalization' => (bool) $project->get('text_normalization')->value,
      ]);
      $chapter->get('audio_files')->appendItem(['target_id' => $file->id()]);
      $chapter->set('status', 'complete');
      $chapter->set('error_message', '');
      $chapter->save();
      $form_state->set('generation_result', [
        'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
        'model' => $model,
        'voice' => (string) $project->get('voice')->value,
        'format' => (string) $project->get('format')->value,
        'characters' => mb_strlen($text),
        'bytes' => $file->getSize(),
        'mime' => $file->getMimeType(),
        'duration' => $this->getDurationInspector()->getDuration($file),
        'seconds' => microtime(TRUE) - $started,
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

  private function formatDuration(float $seconds): string {
    $seconds = (int) round($seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
  }

}
