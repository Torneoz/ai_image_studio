<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationDurationInspector;
use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\ai_narration\Service\NarrationMediaPublisher;
use Drupal\ai_narration\Service\NarrationSourceLoader;
use Drupal\ai_narration\Service\NarrationTextChunker;
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

  protected NarrationSourceLoader $sourceLoader;

  protected NarrationTextChunker $textChunker;

  public function __construct(
    $entity_repository,
    $entity_type_bundle_info,
    $time,
    NarrationGenerator $generator,
    FileUrlGeneratorInterface $file_url_generator,
    NarrationMediaPublisher $media_publisher,
    NarrationDurationInspector $duration_inspector,
    NarrationSourceLoader $source_loader,
    NarrationTextChunker $text_chunker,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->generator = $generator;
    $this->fileUrlGenerator = $file_url_generator;
    $this->mediaPublisher = $media_publisher;
    $this->durationInspector = $duration_inspector;
    $this->sourceLoader = $source_loader;
    $this->textChunker = $text_chunker;
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
      $container->get('ai_narration.source_loader'),
      $container->get('ai_narration.text_chunker'),
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
    $this->buildSourceControls($form, $form_state);
    if ($loaded_text = $form_state->get('loaded_source_text')) {
      $form['text']['widget'][0]['value']['#default_value'] = $loaded_text;
    }
    $text_element =& $form['text']['widget'][0]['value'];
    $text_element['#prefix'] = '<div id="ai-narration-chapter-text-wrapper">';
    $text_element['#suffix'] = '</div>';
    $text_element['#attributes']['data-ai-narration-text'] = TRUE;
    $project_id = (int) ($form_state->getValue(['project_id', 0, 'target_id']) ?? $element['#default_value'] ?? 0);
    $project = $project_id ? ($projects[$project_id] ?? NULL) : NULL;
    $selected_model = (string) ($form_state->getValue(['job_settings', 'model']) ?? '');
    $model = $selected_model !== '' ? $selected_model : (string) ($project?->get('model')->value ?? '');
    $limit = $project
      ? $this->generator->getInputCharacterLimit($model)
      : NULL;
    $limit_label = $project === NULL
      ? $this->t('Select a project to show its model limit.')
      : ($limit === NULL
        ? $this->t('No application limit is available for this provider.')
        : $this->t('Model limit: @limit characters', ['@limit' => number_format($limit)]));
    $form['text_count'] = [
      '#type' => 'item',
      '#weight' => -6,
      '#attributes' => ['id' => 'ai-narration-text-count'],
      '#markup' => '<span data-ai-narration-character-count>' . mb_strlen((string) ($form_state->getValue(['text', 0, 'value']) ?? $text_element['#default_value'])) . '</span> ' . $this->t('characters') . ' · <span data-ai-narration-limit-label>' . $limit_label . '</span>',
    ];
    $form['#attached']['library'][] = 'ai_narration/character_counter';
    foreach ($this->generator->getModelOptions() as $model_option => $label) {
      $form['#attached']['drupalSettings']['aiNarration']['modelCharacterLimits'][$model_option] = $this->generator->getInputCharacterLimit($model_option);
    }
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
      $form['#attached']['library'][] = 'ai_narration/audio_share';
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
   * Builds the source picker without coupling a chapter to live remote content.
   */
  private function buildSourceControls(array &$form, FormStateInterface $form_state): void {
    $saved = [
      'type' => (string) ($this->entity->get('source_type')->value ?: 'local'),
      'url' => (string) $this->entity->get('source_url')->value,
      'entity_type' => (string) $this->entity->get('source_entity_type')->value,
      'entity_id' => (int) $this->entity->get('source_entity_id')->value,
      'label' => (string) $this->entity->get('source_label')->value,
      'fetched' => (int) $this->entity->get('source_fetched')->value,
    ];
    $values = $form_state->getValue('source') ?? $saved;
    $type = in_array($values['type'] ?? '', ['local', 'remote_url', 'entity'], TRUE) ? $values['type'] : 'local';
    $entityType = 'node';
    $bundles = array_map(static fn ($type) => $type->label(), $this->entityTypeManager->getStorage('node_type')->loadMultiple());
    $bundle = $values['bundle'] ?? '';
    $sourceEntity = !empty($values['entity_id']) ? $this->entityTypeManager->getStorage('node')->load((int) $values['entity_id']) : NULL;

    $form['source'] = [
      '#type' => 'details',
      '#title' => $this->t('Narration text source'),
      '#description' => $this->t('Loading a source copies it into Narration text as an editable snapshot. It is never refreshed automatically before generation.'),
      '#open' => $type !== 'local',
      '#tree' => TRUE,
      '#weight' => -7.5,
      '#attributes' => ['id' => 'ai-narration-chapter-source'],
    ];
    $form['source']['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Source'),
      '#options' => ['local' => $this->t('Local text'), 'remote_url' => $this->t('Remote URL'), 'entity' => $this->t('Local node')],
      '#default_value' => $type,
      '#description' => $this->t('Local text is written directly below. A remote URL or local entity must be explicitly loaded.'),
      '#ajax' => ['callback' => '::refreshSourceControls', 'wrapper' => 'ai-narration-chapter-source', 'event' => 'change', 'progress' => ['type' => 'throbber']],
    ];
    $form['source']['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Remote URL'),
      '#default_value' => $values['url'] ?? '',
      '#access' => $type === 'remote_url',
      '#description' => $this->t('A public HTTP(S) page. The readable article or main content will be copied into Narration text.'),
    ];
    $form['source']['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#options' => $bundles,
      '#default_value' => $bundle,
      '#empty_option' => $this->t('- Any content type -'),
      '#access' => $type === 'entity',
      '#description' => $this->t('Limit node suggestions to a content type.'),
      '#ajax' => ['callback' => '::refreshSourceControls', 'wrapper' => 'ai-narration-chapter-source', 'event' => 'change', 'progress' => ['type' => 'throbber']],
    ];
    $form['source']['entity_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Entity'),
      '#target_type' => $entityType,
      '#selection_settings' => $bundle === '' ? [] : ['target_bundles' => [$bundle]],
      '#default_value' => $sourceEntity,
      '#access' => $type === 'entity',
      '#description' => $this->t('Start typing a node title to choose narration content.'),
    ];
    if ($type !== 'local') {
      $form['source']['load'] = [
        '#type' => 'submit',
        '#value' => $this->t('Load source into narration text'),
        '#submit' => ['::loadSource'],
        '#limit_validation_errors' => [['source']],
        '#ajax' => ['callback' => '::loadSourceAjax', 'progress' => ['type' => 'throbber', 'message' => $this->t('Loading source…')]],
      ];
    }
    if ($saved['fetched']) {
      $form['source']['loaded'] = ['#markup' => $this->t('Current snapshot loaded from <strong>@source</strong> on @date. It can be edited below.', ['@source' => $saved['label'] ?: ($saved['url'] ?: $saved['type']), '@date' => \Drupal::service('date.formatter')->format($saved['fetched'], 'short')])];
    }
  }

  /** AJAX callback for the source-picker controls. */
  public function refreshSourceControls(array &$form, FormStateInterface $form_state): array {
    return $form['source'];
  }

  /** Replaces source controls and the loaded narration text. */
  public function loadSourceAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#ai-narration-chapter-source', $form['source']));
    $response->addCommand(new ReplaceCommand('#ai-narration-chapter-text-wrapper', $form['text']['widget'][0]['value']));
    $response->addCommand(new ReplaceCommand('#ai-narration-text-count', $form['text_count']));
    return $response;
  }

  /** Copies an explicitly selected source into the editable narration snapshot. */
  public function loadSource(array &$form, FormStateInterface $form_state): void {
    $source = $form_state->getValue('source') ?? [];
    try {
      if (($source['type'] ?? '') === 'remote_url') {
        $url = trim((string) ($source['url'] ?? ''));
        if ($url === '') {
          throw new \RuntimeException('Enter a remote URL to load.');
        }
        $loaded = $this->sourceLoader->loadUrl($url);
        $source['url'] = $url;
        $source['entity_type'] = '';
        $source['entity_id'] = 0;
      }
      elseif (($source['type'] ?? '') === 'entity') {
        $entityType = 'node';
        $entityId = (int) ($source['entity_id'] ?? 0);
        if ($entityType === '' || $entityId <= 0) {
          throw new \RuntimeException('Choose a local entity to load.');
        }
        $loaded = $this->sourceLoader->loadEntity($entityType, $entityId);
        $source['url'] = '';
      }
      else {
        throw new \RuntimeException('Choose a remote URL or local entity source.');
      }
      $source['label'] = $loaded['title'];
      $source['fetched'] = $this->time->getRequestTime();
      $form_state->setValue('source', $source);
      $form_state->setValue(['text', 0, 'value'], $loaded['text']);
      $form_state->set('loaded_source_text', $loaded['text']);
      // Entity widgets retain submitted browser input during an AJAX rebuild.
      // Update that input too, so the loaded snapshot is visible immediately.
      $input = $form_state->getUserInput();
      $input['text'][0]['value'] = $loaded['text'];
      $form_state->setUserInput($input);
      $this->messenger()->addStatus($this->t('Loaded @characters characters from @source. Review and save the editable narration text.', ['@characters' => number_format(mb_strlen($loaded['text'])), '@source' => $loaded['title']]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Source could not be loaded: @error', ['@error' => $exception->getMessage()]));
    }
    $form_state->setRebuild();
  }

  /** @return array<string, string> */
  private function getContentEntityTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->getGroup() === 'content' && $definition->hasHandlerClass('storage')) {
        $options[$id] = (string) $definition->getLabel();
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /** {@inheritdoc} */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $entity = parent::buildEntity($form, $form_state);
    $source = $form_state->getValue('source');
    if (is_array($source)) {
      $entity->set('source_type', $source['type'] ?? 'local');
      $entity->set('source_url', $source['type'] === 'remote_url' ? ($source['url'] ?? '') : '');
      $entity->set('source_entity_type', $source['type'] === 'entity' ? ($source['entity_type'] ?? '') : '');
      $entity->set('source_entity_id', $source['type'] === 'entity' ? (int) ($source['entity_id'] ?? 0) : 0);
      $entity->set('source_label', $source['label'] ?? '');
      $entity->set('source_fetched', (int) ($source['fetched'] ?? 0));
    }
    return $entity;
  }

  /**
   * Builds non-persistent overrides for the current generation only.
   */
  private function buildJobSettings(array &$form, FormStateInterface $form_state): void {
    $project = $this->entity->get('project_id')->entity;
    if ($project === NULL) {
      return;
    }
    $metadata = json_decode((string) $this->entity->get('generation_metadata')->value, TRUE) ?: [];
    $overrides = $metadata['parameters'] ?? [];
    $defaults = [
      'model' => (string) ($metadata['model'] ?? $project->get('model')->value),
      'voice' => (string) ($metadata['voice'] ?? $project->get('voice')->value),
      'format' => (string) ($metadata['format'] ?? $project->get('format')->value),
      'speed' => (string) ($overrides['speed'] ?? ($project->get('speed')->value ?: '1')),
      'language' => (string) ($overrides['language'] ?? $project->get('language')->value),
      'text_normalization' => (bool) ($overrides['text_normalization'] ?? $project->get('text_normalization')->value),
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
      '#attributes' => ['data-ai-narration-model-select' => TRUE],
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

  public function refreshJobSettings(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#ai-narration-chapter-job-settings', $form['job_settings']));
    $response->addCommand(new ReplaceCommand('#ai-narration-text-count', $form['text_count']));
    return $response;
  }

  /**
   * Shows the durable result saved by the last successful generation.
   */
  private function buildPreviousGenerationResult(array &$form, FormStateInterface $form_state): void {
    $files = [];
    foreach ($this->entity->get('audio_files')->referencedEntities() as $audio_file) {
      $files[] = $audio_file;
    }
    $file = $this->entity->get('last_generated_audio')->entity;
    $metadata = json_decode((string) $this->entity->get('generation_metadata')->value, TRUE) ?: [];
    if (!empty($metadata['file_ids'])) {
      $latest_ids = array_map('intval', $metadata['file_ids']);
      $files = array_values(array_filter($files, static fn ($audio_file) => in_array((int) $audio_file->id(), $latest_ids, TRUE)));
    }
    if ($files === [] && $file) {
      $files[] = $file;
    }
    $file ??= end($files) ?: NULL;
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
    if ($files !== []) {
      $url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      $form['previous_generation_result']['actions'] = ['#type' => 'container', '#attributes' => ['class' => ['ai-narration-result__actions']]];
      if (!empty($metadata['entire_file_id']) && ($entire = $this->entityTypeManager->getStorage('file')->load((int) $metadata['entire_file_id']))) {
        $entire_url = $this->fileUrlGenerator->generateAbsoluteString($entire->getFileUri());
        $file = $entire;
        $url = $entire_url;
        $form['previous_generation_result']['actions']['entire'] = ['#type' => 'container', '#attributes' => ['class' => ['ai-narration-result__segment']], 'label' => ['#markup' => '<strong>' . $this->t('Entire') . '</strong>'], 'player' => ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $entire_url]], 'download' => ['#type' => 'link', '#title' => $this->t('Download entire audio'), '#url' => Url::fromUri($entire_url), '#attributes' => ['download' => TRUE, 'class' => ['button']]]];
      }
      foreach ($files as $index => $audio_file) {
        $audio_url = $this->fileUrlGenerator->generateAbsoluteString($audio_file->getFileUri());
        $form['previous_generation_result']['actions']['segment_' . $index] = ['#type' => 'container', '#attributes' => ['class' => ['ai-narration-result__segment']], 'label' => ['#markup' => '<strong>' . $this->t('Part @part', ['@part' => $index + 1]) . '</strong>'], 'player' => ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $audio_url]], 'download' => ['#type' => 'link', '#title' => $this->t('Download part @part', ['@part' => $index + 1]), '#url' => Url::fromUri($audio_url), '#attributes' => ['download' => TRUE, 'class' => ['button']]]];
      }
      $form['previous_generation_result']['actions']['share'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Share previous audio'),
        '#attributes' => ['type' => 'button', 'class' => ['button', 'ai-narration-share'], 'data-ai-narration-share-url' => $url, 'data-ai-narration-share-title' => $this->t('Narration: @title', ['@title' => $this->entity->label()])],
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
      $items[] = $this->t('Segments: @count', ['@count' => count($files)]);
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
      $project_limit = (int) $project->get('max_input_characters')->value;
      $model_limit = $this->generator->getInputCharacterLimit($model);
      $limit = $model_limit ?? 4096;
      if ($project_limit > 0) {
        $limit = min($project_limit, $limit);
      }
      if ($limit < 100) {
        throw new \RuntimeException('The effective input-character limit must be at least 100.');
      }
      $started = microtime(TRUE);
      $generatedAt = $this->time->getRequestTime();
      $voice = (string) ($settings['voice'] ?? $project->get('voice')->value);
      $format = (string) ($settings['format'] ?? $project->get('format')->value);
      $parameters = [
        'language' => (string) ($settings['language'] ?? $project->get('language')->value),
        'speed' => $settings['speed'] ?? ($project->get('speed')->value ?: 1),
        'text_normalization' => (bool) ($settings['text_normalization'] ?? $project->get('text_normalization')->value),
      ];
      $files = [];
      $duration = 0.0;
      $chapter->set('audio_files', []);
      foreach ($this->textChunker->split($text, $limit) as $index => $chunk) {
        $file = $this->generator->generate($chunk, $model, $voice, $format, $parameters, (string) $chapter->label() . '-' . ($index + 1));
        $files[] = $file;
        $duration += $this->getDurationInspector()->getDuration($file) ?? 0.0;
        $chapter->get('audio_files')->appendItem(['target_id' => $file->id()]);
      }
      $file = end($files);
      $entire = \Drupal::service('ai_narration.audio_merger')->merge($files, (string) $chapter->label());
      $seconds = microtime(TRUE) - $started;
      $metadata = [
        'model' => $model,
        'voice' => $voice,
        'format' => $format,
        'parameters' => $parameters,
        'input_characters' => mb_strlen($text),
        'file_ids' => array_map(static fn ($file) => (int) $file->id(), $files),
        'entire_file_id' => $entire?->id(),
        'audio_segments' => count($files),
        'bytes' => (int) $file->getSize(),
        'mime' => $file->getMimeType(),
        'runtime_seconds' => $duration,
        'generation_seconds' => $seconds,
        'generated_at' => gmdate(DATE_ATOM, $generatedAt),
      ];
      $chapter->set('last_generated_audio', ['target_id' => $file->id()]);
      $chapter->set('generation_metadata', json_encode($metadata, JSON_UNESCAPED_SLASHES));
      $chapter->set('status', 'complete');
      $chapter->set('error_message', '');
      $chapter->save();
      $form_state->set('generation_result', [
        'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
        'entire' => $entire ? ['url' => $this->fileUrlGenerator->generateAbsoluteString($entire->getFileUri())] : NULL,
        'segments' => array_map(fn ($audio_file) => ['url' => $this->fileUrlGenerator->generateAbsoluteString($audio_file->getFileUri()), 'name' => $audio_file->getFilename()], $files),
        'fid' => $file->id(),
        'model' => $model,
        'voice' => $voice,
        'format' => $format,
        'characters' => mb_strlen($text),
        'bytes' => array_sum(array_map(static fn ($audio_file) => $audio_file->getSize(), $files)),
        'mime' => $file->getMimeType(),
        'duration' => $duration,
        'seconds' => $seconds,
        'generated_at' => $generatedAt,
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
    if (!empty($result['entire']['url'])) {
      $feedback['actions']['entire'] = ['#type' => 'container', '#attributes' => ['class' => ['ai-narration-result__segment']], 'label' => ['#markup' => '<strong>' . $this->t('Entire') . '</strong>'], 'player' => ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $result['entire']['url']]], 'download' => ['#type' => 'link', '#title' => $this->t('Download entire audio'), '#url' => Url::fromUri($result['entire']['url']), '#attributes' => ['download' => TRUE, 'class' => ['button']]]];
    }
    foreach ($result['segments'] ?? [['url' => $result['url'], 'name' => 'Audio']] as $index => $segment) {
      $feedback['actions']['segment_' . $index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-narration-result__segment']],
        'label' => ['#markup' => '<strong>' . $this->t('Part @part', ['@part' => $index + 1]) . '</strong>'],
        'player' => ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $segment['url']]],
        'download' => ['#type' => 'link', '#title' => $this->t('Download part @part', ['@part' => $index + 1]), '#url' => Url::fromUri($segment['url']), '#attributes' => ['download' => TRUE, 'class' => ['button']]],
      ];
    }
    $feedback['actions']['share'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Share Audio'),
      '#attributes' => ['type' => 'button', 'class' => ['button', 'ai-narration-share'], 'data-ai-narration-share-url' => $result['url'], 'data-ai-narration-share-title' => $this->t('Narration: @title', ['@title' => $this->entity->label()])],
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
      $this->t('Segments: @count', ['@count' => count($result['segments'] ?? []) ?: 1]),
      $this->t('Input: @characters characters', ['@characters' => $result['characters']]),
      $this->t('File: @size bytes (@mime)', ['@size' => number_format($result['bytes']), '@mime' => $result['mime']]),
      $result['duration'] === NULL ? $this->t('Clip runtime: unavailable') : $this->t('Clip runtime: @duration', ['@duration' => $this->formatDuration($result['duration'])]),
      $this->t('Generation time: @seconds seconds', ['@seconds' => number_format($result['seconds'], 2)]),
      $this->t('Generated: @date', ['@date' => $this->formatGenerationDate((int) $result['generated_at'])]),
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
    $metadata = json_decode((string) $this->entity->get('generation_metadata')->value, TRUE) ?: [];
    if (!empty($metadata['entire_file_id'])) {
      $file = $this->entityTypeManager->getStorage('file')->load((int) $metadata['entire_file_id']) ?: $file;
    }
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

  /** Formats a generation timestamp in the site's configured timezone. */
  private function formatGenerationDate(int $timestamp): string {
    return \Drupal::service('date.formatter')->format($timestamp, 'custom', 'j M Y, g:i:s a T');
  }

}
