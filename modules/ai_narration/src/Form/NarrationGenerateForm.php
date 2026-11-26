<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\ai_narration\Service\NarrationMediaPublisher;
use Drupal\ai_narration\Service\NarrationDurationInspector;
use Drupal\ai_narration\Service\NarrationSourceLoader;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets authorized users generate and download narrated audio.
 */
final class NarrationGenerateForm extends FormBase {

  /**
   * Per-user storage for reusable narration values.
   */
  protected UserDataInterface $userData;

  /**
   * Generates audio through the selected Drupal AI provider.
   */
  protected NarrationGenerator $generator;

  /**
   * Reads the configured default voice.
   */
  protected ConfigFactoryInterface $narrationConfigFactory;

  /**
   * Generates file URLs for completed narration.
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Creates reusable Media Library items from generated files.
   */
  protected NarrationMediaPublisher $mediaPublisher;

  /**
   * Loads the generated file before it is published to Media.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Reads the duration from completed audio files.
   */
  protected ?NarrationDurationInspector $durationInspector = NULL;

  /** Loads explicitly selected remote and local-entity text sources. */
  protected NarrationSourceLoader $sourceLoader;

  public function __construct(
    NarrationGenerator $generator,
    ConfigFactoryInterface $narration_config_factory,
    FileUrlGeneratorInterface $file_url_generator,
    UserDataInterface $user_data,
    NarrationMediaPublisher $media_publisher,
    EntityTypeManagerInterface $entity_type_manager,
    NarrationDurationInspector $duration_inspector,
    NarrationSourceLoader $source_loader,
  ) {
    $this->generator = $generator;
    $this->narrationConfigFactory = $narration_config_factory;
    $this->fileUrlGenerator = $file_url_generator;
    $this->userData = $user_data;
    $this->mediaPublisher = $media_publisher;
    $this->entityTypeManager = $entity_type_manager;
    $this->durationInspector = $duration_inspector;
    $this->sourceLoader = $source_loader;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_narration.generator'),
      $container->get('config.factory'),
      $container->get('file_url_generator'),
      $container->get('user.data'),
      $container->get('ai_narration.media_publisher'),
      $container->get('entity_type.manager'),
      $container->get('ai_narration.duration_inspector'),
      $container->get('ai_narration.source_loader'),
    );
  }

  public function getFormId(): string {
    return 'ai_narration_generate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = $this->generator->getModelOptions();
    $default = (string) $this->narrationConfigFactory->get('ai_narration.settings')->get('default_model');
    $savedText = (string) ($this->userData->get('ai_narration', $this->currentUser()->id(), 'last_text') ?? '');
    $savedModel = (string) ($this->userData->get('ai_narration', $this->currentUser()->id(), 'last_model') ?? '');
    $savedVoice = (string) ($this->userData->get('ai_narration', $this->currentUser()->id(), 'last_voice') ?? '');
    $savedFormat = (string) ($this->userData->get('ai_narration', $this->currentUser()->id(), 'last_format') ?? '');
    $savedProject = (int) ($this->userData->get('ai_narration', $this->currentUser()->id(), 'last_project') ?? 0);
    $sitePlayerMode = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_mode') ?: 'native');
    $savedPlayerMode = (string) ($this->userData->get('ai_narration', $this->currentUser()->id(), 'player_mode') ?? '');
    $storyboardTransfer = FALSE;
    if (!$form_state->has('storyboard_narration_transfer_checked')) {
      $query = \Drupal::request()->query;
      if ($query->getInt('storyboard_narration') === 1) {
        $store = \Drupal::service('user.private_tempstore')->get('ai_storyboard');
        $transferredText = $store->get('narration_text_' . $this->currentUser()->id());
        if (is_string($transferredText) && trim($transferredText) !== '') {
          $form_state->set('storyboard_narration_text', $transferredText);
          $store->delete('narration_text_' . $this->currentUser()->id());
          $storyboardTransfer = TRUE;
        }
      }
      $form_state->set('storyboard_narration_transfer_checked', TRUE);
    }
    $textDefault = (string) ($form_state->get('loaded_source_text') ?? $form_state->getValue('text') ?? $form_state->get('storyboard_narration_text') ?? $savedText);
    $submittedModel = (string) ($form_state->getValue('model') ?? '');
    $model = isset($options[$submittedModel]) ? $submittedModel : (isset($options[$savedModel]) ? $savedModel : (isset($options[$default]) ? $default : ''));
    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Create a single narrated audio file from a script. Select a text-to-speech model and voice, adjust the available request controls, then preview, download, or save the completed narration to the Media Library.'),
    ];
    $form['player_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Audio player display'),
      '#options' => [
        '' => $this->t('Use site default (@mode)', ['@mode' => $sitePlayerMode === 'enhanced' ? $this->t('enhanced') : $this->t('native')]),
        'native' => $this->t('Native browser player'),
        'enhanced' => $this->t('Enhanced narration player'),
      ],
      '#default_value' => $form_state->getValue('player_mode') ?? $savedPlayerMode,
      '#description' => $this->t('Your selection is remembered for future narration work.'),
      '#attributes' => ['data-ai-narration-player-mode' => TRUE],
    ];
    $this->buildSourceControls($form, $form_state);
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Narration text'),
      '#description' => $this->t('Use only text you are authorized to turn into speech. Your selected provider applies its own length and content limits.'),
      '#required' => TRUE,
      '#rows' => 10,
      '#default_value' => $textDefault,
      '#attributes' => ['data-ai-narration-text' => TRUE],
      '#prefix' => '<div id="ai-narration-simple-text-wrapper">',
      '#suffix' => '</div>',
    ];
    if ($storyboardTransfer) {
      $form['storyboard_source_notice'] = [
        '#type' => 'item',
        '#weight' => 1,
        '#markup' => '<p>' . $this->t('Narration text was transferred from AI Storyboard. Review and edit it before generating audio.') . '</p>',
      ];
    }
    $limit = $this->generator->getInputCharacterLimit($model);
    $limitLabel = $limit === NULL
      ? $this->t('No application limit is available for this provider.')
      : $this->t('Limit: @limit characters', ['@limit' => number_format($limit)]);
    $form['text_count'] = [
      '#type' => 'item',
      '#attributes' => ['id' => 'ai-narration-text-count'],
      '#markup' => '<span data-ai-narration-character-count>' . mb_strlen($textDefault) . '</span> ' . $this->t('characters') . ' · ' . $limitLabel,
    ];
    $form['#attached']['library'][] = 'ai_narration/character_counter';
    $form['#attached']['library'][] = 'ai_narration/audio_share';
    $form['#attached']['library'][] = 'ai_narration/enhanced_player';
    $form['#attached']['drupalSettings']['aiNarration']['playerModeDefault'] = $sitePlayerMode;
    $form['#attached']['drupalSettings']['aiNarration']['playerAccentColour'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_accent_colour') ?: '#24c7b9');
    $form['#attached']['drupalSettings']['aiNarration']['playerColourMode'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_colour_mode') ?: 'default');
    $form['#attached']['drupalSettings']['aiNarration']['playerWaveformColour'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_waveform_colour') ?: '#74fff0');
    $form['#attached']['drupalSettings']['aiNarration']['playerUnplayedWaveformColour'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_unplayed_waveform_colour') ?: '#4c898a');
    $form['#attached']['drupalSettings']['aiNarration']['playerSpeedControlColour'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_speed_control_colour') ?: '#e9fffc');
    $form['#attached']['drupalSettings']['aiNarration']['playerControlBackgroundColour'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_control_background_colour') ?: '#0c2527');
    $form['#attached']['drupalSettings']['aiNarration']['playerBackgroundColour'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_background_colour') ?: '#102b2d');
    $form['#attached']['drupalSettings']['aiNarration']['playerTextColour'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_text_colour') ?: '#e9fffc');
    $form['#attached']['drupalSettings']['aiNarration']['playerHeight'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_height') ?: '4rem');
    $form['#attached']['drupalSettings']['aiNarration']['playerDisplayStyle'] = (string) ($this->narrationConfigFactory->get('ai_narration.settings')->get('player_display_style') ?: 'waveform');
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Text-to-speech model'),
      '#options' => $options,
      '#default_value' => $model,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a voice -'),
      '#description' => $options === [] ? $this->t('No usable text-to-speech provider is configured.') : $this->t('Choose the provider and text-to-speech model. Compatible voices, formats, and advanced controls update below.'),
      '#ajax' => ['callback' => '::refreshModelOptions'],
    ];
    $voices = $this->generator->getVoiceOptions($model);
    $form['voice_wrapper'] = ['#type' => 'container', '#attributes' => ['id' => 'ai-narration-voice']];
    if ($voices !== []) {
      $form['voice_wrapper']['voice'] = [
        '#type' => 'select',
        '#title' => $this->t('Voice'),
        '#options' => $voices,
        '#default_value' => isset($voices[$form_state->getValue('voice') ?? $savedVoice]) ? ($form_state->getValue('voice') ?? $savedVoice) : array_key_first($voices),
        '#description' => $this->t('Choose the voice for this request without changing the provider-wide default.'),
      ];
    }
    $formats = $this->generator->getFormatOptions($model);
    if ($formats !== []) {
      $form['voice_wrapper']['format'] = [
        '#type' => 'select',
        '#title' => $this->t('Output format'),
        '#options' => $formats,
        '#default_value' => isset($formats[$form_state->getValue('format') ?? $savedFormat]) ? ($form_state->getValue('format') ?? $savedFormat) : array_key_first($formats),
        '#description' => $this->t('Choose the downloaded audio encoding. Availability depends on the selected provider.'),
      ];
    }
    $provider = explode('__', $model, 2)[0] ?? '';
    if ($provider === 'openai') {
      $form['voice_wrapper']['speed'] = ['#type' => 'number', '#title' => $this->t('Speech speed'), '#default_value' => 1, '#min' => 0.25, '#max' => 4, '#step' => 0.05, '#description' => $this->t('Playback-speed multiplier. 1 is normal speed; lower is slower and higher is faster.')];
    }
    elseif ($provider === 'gemini') {
      $form['voice_wrapper']['language'] = [
        '#type' => 'select',
        '#title' => $this->t('Speech language'),
        '#default_value' => (string) ($form_state->getValue('language') ?? ''),
        '#options' => [
          '' => $this->t('Auto-detect from narration text'),
          'en-AU' => $this->t('English (Australia)'),
          'en-GB' => $this->t('English (United Kingdom)'),
          'en-US' => $this->t('English (United States)'),
          'de-DE' => $this->t('German (Germany)'),
          'es-ES' => $this->t('Spanish (Spain)'),
          'es-US' => $this->t('Spanish (United States)'),
          'fr-FR' => $this->t('French (France)'),
          'fr-CA' => $this->t('French (Canada)'),
          'hi-IN' => $this->t('Hindi (India)'),
          'it-IT' => $this->t('Italian (Italy)'),
          'ja-JP' => $this->t('Japanese (Japan)'),
          'ko-KR' => $this->t('Korean (Korea)'),
          'pt-BR' => $this->t('Portuguese (Brazil)'),
          'ru-RU' => $this->t('Russian (Russia)'),
        ],
        '#description' => $this->t('Choose a locale to guide synthesis, including Australian, British, or American English. Leave on Auto-detect for Gemini to infer the language from the narration text.'),
      ];
    }
    elseif ($provider === 'grok') {
      $form['voice_wrapper']['language'] = [
        '#type' => 'select',
        '#title' => $this->t('Language'),
        '#default_value' => 'auto',
        '#options' => ['auto' => $this->t('Auto-detect'), 'en' => $this->t('English'), 'ar-EG' => $this->t('Arabic (Egypt)'), 'ar-SA' => $this->t('Arabic (Saudi Arabia)'), 'ar-AE' => $this->t('Arabic (UAE)'), 'bn' => $this->t('Bengali'), 'zh' => $this->t('Chinese'), 'fr' => $this->t('French'), 'de' => $this->t('German'), 'hi' => $this->t('Hindi'), 'id' => $this->t('Indonesian'), 'it' => $this->t('Italian'), 'ja' => $this->t('Japanese'), 'ko' => $this->t('Korean'), 'pt-BR' => $this->t('Portuguese (Brazil)'), 'pt-PT' => $this->t('Portuguese (Portugal)'), 'ru' => $this->t('Russian'), 'es-MX' => $this->t('Spanish (Mexico)'), 'es-ES' => $this->t('Spanish (Spain)'), 'tr' => $this->t('Turkish'), 'vi' => $this->t('Vietnamese')],
        '#description' => $this->t('Auto-detect uses the narration text; choose a language to force a supported speech locale.'),
      ];
      $form['voice_wrapper']['speed'] = ['#type' => 'number', '#title' => $this->t('Speech speed'), '#default_value' => 1, '#min' => 0.7, '#max' => 1.5, '#step' => 0.05, '#description' => $this->t('Playback-speed multiplier. 1 is normal speed; Grok supports 0.7 to 1.5.')];
      $form['voice_wrapper']['text_normalization'] = ['#type' => 'checkbox', '#title' => $this->t('Normalize text before speaking'), '#description' => $this->t('Expands numbers, abbreviations, and symbols into a spoken form before synthesis.')];
    }
    elseif ($provider === 'elevenlabs') {
      $form['voice_wrapper']['speed'] = ['#type' => 'number', '#title' => $this->t('Speech speed'), '#default_value' => $form_state->getValue('speed') ?? 1, '#min' => 0.7, '#max' => 1.2, '#step' => 0.01, '#description' => $this->t('Controls delivery pace. 1 is normal speed.')];
      $form['voice_wrapper']['stability'] = ['#type' => 'number', '#title' => $this->t('Stability'), '#default_value' => $form_state->getValue('stability') ?? 0.5, '#min' => 0, '#max' => 1, '#step' => 0.01, '#description' => $this->t('Higher values are steadier; lower values are more expressive and variable.')];
      $form['voice_wrapper']['similarity_boost'] = ['#type' => 'number', '#title' => $this->t('Similarity boost'), '#default_value' => $form_state->getValue('similarity_boost') ?? 0.75, '#min' => 0, '#max' => 1, '#step' => 0.01, '#description' => $this->t('How closely the result follows the selected voice.')];
      $form['voice_wrapper']['style'] = ['#type' => 'number', '#title' => $this->t('Style exaggeration'), '#default_value' => $form_state->getValue('style') ?? 0, '#min' => 0, '#max' => 1, '#step' => 0.01, '#description' => $this->t('Amplifies the voice’s natural style. Higher values can add latency.')];
      $form['voice_wrapper']['use_speaker_boost'] = ['#type' => 'checkbox', '#title' => $this->t('Use speaker boost'), '#default_value' => $form_state->getValue('use_speaker_boost') ?? TRUE, '#description' => $this->t('Improves similarity to the original speaker, with a small latency cost.')];
      $form['voice_wrapper']['language_code'] = ['#type' => 'textfield', '#title' => $this->t('Language code'), '#default_value' => $form_state->getValue('language_code') ?? '', '#maxlength' => 8, '#description' => $this->t('Optional ISO 639-1 language code, such as en, fr, or ja. Not supported by Eleven Multilingual v2.')];
      $form['voice_wrapper']['apply_text_normalization'] = ['#type' => 'select', '#title' => $this->t('Text normalisation'), '#options' => ['auto' => $this->t('Auto'), 'on' => $this->t('Always on'), 'off' => $this->t('Off')], '#default_value' => $form_state->getValue('apply_text_normalization') ?? 'auto', '#description' => $this->t('Controls expansion of numbers, dates, abbreviations, and symbols. Turbo v2.5 and Flash v2.5 do not support Always on.')];
      $form['voice_wrapper']['apply_language_text_normalization'] = ['#type' => 'checkbox', '#title' => $this->t('Language-aware normalisation'), '#default_value' => $form_state->getValue('apply_language_text_normalization') ?? FALSE, '#description' => $this->t('Enables language-specific normalisation. Currently intended for Japanese and can increase latency.')];
      $form['voice_wrapper']['seed'] = ['#type' => 'number', '#title' => $this->t('Seed'), '#default_value' => $form_state->getValue('seed') ?? '', '#min' => 0, '#max' => 4294967295, '#step' => 1, '#description' => $this->t('Optional best-effort deterministic seed. Identical output is not guaranteed.')];
      $form['voice_wrapper']['advanced'] = ['#type' => 'details', '#title' => $this->t('Advanced ElevenLabs controls'), '#open' => FALSE];
      $form['voice_wrapper']['advanced']['previous_text'] = ['#type' => 'textarea', '#title' => $this->t('Previous text'), '#default_value' => $form_state->getValue('previous_text') ?? '', '#rows' => 2, '#description' => $this->t('Preceding narration to improve continuity across separately generated chapters.')];
      $form['voice_wrapper']['advanced']['next_text'] = ['#type' => 'textarea', '#title' => $this->t('Next text'), '#default_value' => $form_state->getValue('next_text') ?? '', '#rows' => 2, '#description' => $this->t('Following narration to improve continuity across separately generated chapters.')];
      $form['voice_wrapper']['advanced']['pronunciation_dictionary_locators'] = ['#type' => 'textarea', '#title' => $this->t('Pronunciation dictionaries (JSON)'), '#default_value' => $form_state->getValue('pronunciation_dictionary_locators') ?? '', '#rows' => 2, '#description' => $this->t('Optional JSON array of up to three objects with id and version_id.')];
      $form['voice_wrapper']['advanced']['previous_request_ids'] = ['#type' => 'textarea', '#title' => $this->t('Previous request IDs (JSON)'), '#default_value' => $form_state->getValue('previous_request_ids') ?? '', '#rows' => 2, '#description' => $this->t('Optional JSON array of up to three preceding ElevenLabs request IDs.')];
      $form['voice_wrapper']['advanced']['next_request_ids'] = ['#type' => 'textarea', '#title' => $this->t('Next request IDs (JSON)'), '#default_value' => $form_state->getValue('next_request_ids') ?? '', '#rows' => 2, '#description' => $this->t('Optional JSON array of up to three following ElevenLabs request IDs.')];
      $form['voice_wrapper']['advanced']['use_pvc_as_ivc'] = ['#type' => 'checkbox', '#title' => $this->t('Use IVC rather than PVC'), '#default_value' => $form_state->getValue('use_pvc_as_ivc') ?? FALSE, '#description' => $this->t('Legacy compatibility control for professional voice clones; ElevenLabs marks this API option deprecated.')];
    }
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate narration'),
      '#disabled' => $options === [],
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'ai-narration-feedback',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Generating narration…'),
        ],
      ],
    ];
    $form['feedback'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-narration-feedback', 'class' => ['ai-narration-result']],
    ];
    if ($result = $form_state->get('result')) {
      $form['feedback']['#attributes']['role'] = 'status';
      $form['feedback']['#attributes']['class'] = ['messages', 'messages--status', 'ai-narration-result'];
      $form['feedback']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Narration generated'),
      ];
      $form['feedback']['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-narration-result__actions']],
      ];
      $form['feedback']['actions']['player'] = [
        '#type' => 'html_tag',
        '#tag' => 'audio',
        '#attributes' => ['controls' => TRUE, 'src' => $result['url'], 'data-ai-narration-segment-markers' => json_encode($result['entire']['markers'] ?? [])],
      ];
      $form['feedback']['actions']['download'] = [
        '#type' => 'link',
        '#title' => $this->t('Download @format file (@size)', [
          '@format' => strtoupper($result['format'] ?: pathinfo($result['url'], PATHINFO_EXTENSION) ?: 'audio'),
          '@size' => ByteSizeMarkup::create($result['bytes']),
        ]),
        '#url' => Url::fromUri($result['url']),
        '#attributes' => ['download' => TRUE, 'class' => ['button', 'button--primary']],
      ];
      foreach ($result['segments'] ?? [] as $index => $segment) {
        $form['feedback']['actions']['part_' . $index] = ['#type' => 'container', '#attributes' => ['class' => ['ai-narration-result__segment']], 'label' => ['#markup' => '<strong>' . $this->t('Part @part', ['@part' => $index + 1]) . '</strong>'], 'player' => ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $segment['url']]], 'download' => ['#type' => 'link', '#title' => $this->t('Download part @part', ['@part' => $index + 1]), '#url' => Url::fromUri($segment['url']), '#attributes' => ['download' => TRUE, 'class' => ['button']]]];
      }
      $form['feedback']['actions']['share'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Share Audio'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'ai-narration-share'],
          'data-ai-narration-share-url' => $result['url'],
          'data-ai-narration-share-title' => $this->t('Generated narration audio'),
        ],
      ];
      if (empty($result['media_url'])) {
        $form['feedback']['actions']['save_media'] = [
          '#type' => 'submit',
          '#value' => $this->t('Save to Media Library'),
          '#name' => 'save_media',
          '#submit' => ['::saveToMedia'],
          '#limit_validation_errors' => [],
          '#ajax' => ['callback' => '::ajaxSubmit', 'wrapper' => 'ai-narration-feedback'],
          '#attributes' => ['class' => ['button']],
        ];
      }
      else {
        $form['feedback']['actions']['view_media'] = [
          '#type' => 'link',
          '#title' => $this->t('View in Media Library'),
          '#url' => Url::fromUri($result['media_url']),
          '#attributes' => ['class' => ['button']],
        ];
      }
      $projects = $this->entityTypeManager->getStorage('ai_narration_project')->loadMultiple();
      $projectOptions = [];
      foreach ($projects as $project) {
        $projectOptions[$project->id()] = (string) $project->label();
      }
      $selectedProject = (int) ($form_state->getValue('project_id') ?? $result['project_id'] ?? $savedProject);
      if ($projectOptions !== [] && empty($result['chapter_url'])) {
        $form['feedback']['actions']['project_id'] = [
          '#type' => 'select',
          '#title' => $this->t('Narration Project'),
          '#title_display' => 'invisible',
          '#options' => $projectOptions,
          '#default_value' => isset($projectOptions[$selectedProject]) ? $selectedProject : '',
          '#empty_option' => $this->t('Select a Narration Project'),
          '#attributes' => ['class' => ['button']],
        ];
      }
      if (!empty($result['chapter_url'])) {
        $form['feedback']['actions']['view_chapter'] = [
          '#type' => 'link',
          '#title' => $this->t('View Narration Chapter'),
          '#url' => Url::fromUri($result['chapter_url']),
          '#attributes' => ['class' => ['button']],
        ];
      }
      elseif ($projectOptions !== []) {
        $form['feedback']['actions']['save_chapter'] = [
          '#type' => 'submit',
          '#value' => $this->t('Save To Narration Chapter'),
          '#name' => 'save_chapter',
          '#submit' => ['::saveToChapter'],
          '#limit_validation_errors' => [['project_id']],
          '#ajax' => ['callback' => '::ajaxSubmit', 'wrapper' => 'ai-narration-feedback'],
          '#attributes' => ['class' => ['button']],
        ];
      }
      $form['feedback']['metadata'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Generation details'),
        '#items' => [
          $this->t('Model: @model', ['@model' => $result['model']]),
          $result['voice'] === '' ? $this->t('Voice: provider default') : $this->t('Voice: @voice', ['@voice' => $result['voice']]),
          $result['format'] === '' ? $this->t('Format: provider default') : $this->t('Format: @format', ['@format' => strtoupper($result['format'])]),
          $this->t('Input: @characters characters', ['@characters' => $result['characters']]),
          $this->t('Segments: @count', ['@count' => count($result['segments'] ?? []) ?: 1]),
          $this->t('File: @size bytes (@mime)', ['@size' => number_format($result['bytes']), '@mime' => $result['mime']]),
          $this->t('Generation time: @seconds seconds', ['@seconds' => number_format($result['seconds'], 2)]),
          $this->t('Generated: @date', ['@date' => $this->formatGenerationDate((int) $result['generated_at'])]),
          $result['duration'] === NULL ? $this->t('Clip runtime: unavailable') : $this->t('Clip runtime: @duration', ['@duration' => $this->formatDuration($result['duration'])]),
          $result['parameters'] === [] ? $this->t('Request parameters: provider defaults') : $this->t('Request parameters: @parameters', ['@parameters' => json_encode($result['parameters'], JSON_UNESCAPED_SLASHES)]),
          !empty($result['source']) ? $this->t('Text source: @source', ['@source' => $result['source']]) : $this->t('Text source: local text'),
          $this->t('Provider-reported price: unavailable'),
        ],
      ];
    }
    return $form;
  }

  /** Builds the explicit source loader used by Simple Narration. */
  private function buildSourceControls(array &$form, FormStateInterface $form_state): void {
    $saved = $this->userData->get('ai_narration', $this->currentUser()->id(), 'last_source') ?? [];
    $values = $form_state->getValue('source') ?? $saved;
    $type = in_array($values['type'] ?? '', ['local', 'remote_url', 'entity'], TRUE) ? $values['type'] : 'local';
    $entityTypes = $this->getContentEntityTypeOptions();
    $entityType = isset($entityTypes[$values['entity_type'] ?? '']) ? $values['entity_type'] : 'node';
    $sourceEntity = !empty($values['entity_id']) ? $this->entityTypeManager->getStorage($entityType)->load((int) $values['entity_id']) : NULL;
    $form['source'] = [
      '#type' => 'details',
      '#title' => $this->t('Narration text source'),
      '#description' => $this->t('Loading a source copies it into Narration text as an editable snapshot. It is never refreshed automatically before generation.'),
      '#open' => $type !== 'local',
      '#tree' => TRUE,
      '#weight' => -1,
      '#attributes' => ['id' => 'ai-narration-simple-source'],
    ];
    $form['source']['type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Source'),
      '#options' => ['local' => $this->t('Local text'), 'remote_url' => $this->t('Remote URL'), 'entity' => $this->t('Local node or entity')],
      '#default_value' => $type,
      '#description' => $this->t('Local text is written directly below. A remote URL or local entity must be explicitly loaded.'),
      '#ajax' => ['callback' => '::refreshSourceControls', 'wrapper' => 'ai-narration-simple-source', 'event' => 'change', 'progress' => ['type' => 'throbber']],
    ];
    $form['source']['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Remote URL'),
      '#default_value' => $values['url'] ?? '',
      '#access' => $type === 'remote_url',
      '#description' => $this->t('A public HTTP(S) page. Its readable article or main content will be copied into Narration text.'),
    ];
    $form['source']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $entityTypes,
      '#default_value' => $entityType,
      '#access' => $type === 'entity',
      '#description' => $this->t('Choose the type of local content to narrate.'),
      '#ajax' => ['callback' => '::refreshSourceControls', 'wrapper' => 'ai-narration-simple-source', 'event' => 'change', 'progress' => ['type' => 'throbber']],
    ];
    $form['source']['entity_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Entity'),
      '#target_type' => $entityType,
      '#default_value' => $sourceEntity,
      '#access' => $type === 'entity',
      '#description' => $this->t('Select the local content item whose readable fields should be copied into Narration text.'),
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
    if (!empty($values['fetched'])) {
      $form['source']['loaded'] = ['#markup' => $this->t('Current editable snapshot: <strong>@source</strong>.', ['@source' => $values['label'] ?: ($values['url'] ?: $values['type'])])];
    }
  }

  /** AJAX callback for source type and entity type changes. */
  public function refreshSourceControls(array &$form, FormStateInterface $form_state): array {
    return $form['source'];
  }

  /** Loads a source into the editable script field. */
  public function loadSource(array &$form, FormStateInterface $form_state): void {
    $source = $form_state->getValue('source') ?? [];
    try {
      if (($source['type'] ?? '') === 'remote_url') {
        $url = trim((string) ($source['url'] ?? ''));
        if ($url === '') {
          throw new \RuntimeException('Enter a remote URL to load.');
        }
        $loaded = $this->sourceLoader->loadUrl($url);
        $source += ['entity_type' => '', 'entity_id' => 0];
        $source['url'] = $url;
        $source['entity_type'] = '';
        $source['entity_id'] = 0;
      }
      elseif (($source['type'] ?? '') === 'entity') {
        $entityType = (string) ($source['entity_type'] ?? '');
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
      $source['fetched'] = \Drupal::service('datetime.time')->getRequestTime();
      $form_state->setValue('source', $source);
      $form_state->set('source_snapshot', $source);
      $form_state->set('loaded_source_text', $loaded['text']);
      // Submitted browser input takes precedence over #default_value on an
      // AJAX rebuild, so replace it as well as the stored form value.
      $input = $form_state->getUserInput();
      $input['text'] = $loaded['text'];
      $form_state->setUserInput($input);
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_source', $source);
      $this->messenger()->addStatus($this->t('Loaded @characters characters from @source. Review the editable narration text before generating.', ['@characters' => number_format(mb_strlen($loaded['text'])), '@source' => $loaded['title']]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Source could not be loaded: @error', ['@error' => $exception->getMessage()]));
    }
    $form_state->setRebuild();
  }

  /** Replaces the source controls and editable text after a source load. */
  public function loadSourceAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#ai-narration-simple-source', $form['source']));
    $response->addCommand(new ReplaceCommand('#ai-narration-simple-text-wrapper', $form['text']));
    $response->addCommand(new ReplaceCommand('#ai-narration-text-count', $form['text_count']));
    return $response;
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

  /**
   * Rebuilds request-level voices after a model changes.
   */
  public function refreshModelOptions(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#ai-narration-voice', $form['voice_wrapper']));
    $response->addCommand(new ReplaceCommand('#ai-narration-text-count', $form['text_count']));
    return $response;
  }

  /**
   * Returns completed generation feedback to the AJAX request.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): array {
    return $form['feedback'];
  }

  /**
   * Formats an exact clip duration as hours, minutes, and seconds.
   */
  private function formatDuration(float $seconds): string {
    $seconds = (int) round($seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
  }

  /** Formats a generation timestamp in the site's configured timezone. */
  private function formatGenerationDate(int $timestamp): string {
    return \Drupal::service('date.formatter')->format($timestamp, 'custom', 'j M Y, g:i:s a T');
  }

  /**
   * Loads the duration service when resuming a form serialized before it existed.
   */
  private function getDurationInspector(): NarrationDurationInspector {
    return $this->durationInspector ??= \Drupal::service('ai_narration.duration_inspector');
  }

  /**
   * Saves the most recently generated narration as an Audio media item.
   */
  public function saveToMedia(array &$form, FormStateInterface $form_state): void {
    $result = $form_state->get('result');
    $file = !empty($result['fid']) ? $this->entityTypeManager->getStorage('file')->load($result['fid']) : NULL;
    if (!$file) {
      $this->messenger()->addError($this->t('The generated narration file is no longer available.'));
      return;
    }
    try {
      $media = $this->mediaPublisher->publish($file, (string) $this->t('AI narration: @filename', ['@filename' => $file->getFilename()]));
      $result['media_url'] = $media->toUrl('canonical', ['absolute' => TRUE])->toString();
      $form_state->set('result', $result);
      $form_state->setRebuild();
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

  /**
   * Creates a completed Chapter from the current generated narration result.
   */
  public function saveToChapter(array &$form, FormStateInterface $form_state): void {
    $result = $form_state->get('result') ?? [];
    // This action deliberately skips unrelated form validation. Read the
    // selector from user input as a fallback because Drupal does not always
    // copy excluded form values into FormState during an AJAX submission.
    $input = $form_state->getUserInput();
    $projectValue = $form_state->getValue('project_id');
    $submittedProject = $input['project_id'] ?? $input['feedback']['actions']['project_id'] ?? 0;
    $projectId = (int) ($projectValue !== '' && $projectValue !== NULL ? $projectValue : $submittedProject);
    $file = !empty($result['fid']) ? $this->entityTypeManager->getStorage('file')->load((int) $result['fid']) : NULL;
    $project = $projectId ? $this->entityTypeManager->getStorage('ai_narration_project')->load($projectId) : NULL;
    if (!$file || !$project) {
      $this->messenger()->addError($this->t('Select a Narration Project in the result actions, then try saving the result again.'));
      return;
    }
    try {
      $ids = $this->entityTypeManager->getStorage('ai_narration_chapter')->getQuery()
        ->accessCheck(FALSE)
        ->condition('project_id', $projectId)
        ->sort('position', 'DESC')
        ->range(0, 1)
        ->execute();
      $last = $ids ? $this->entityTypeManager->getStorage('ai_narration_chapter')->load(reset($ids)) : NULL;
      $position = $last ? (int) $last->get('position')->value + 1 : 1;
      $chapter = $this->entityTypeManager->getStorage('ai_narration_chapter')->create([
        'project_id' => $projectId,
        'title' => $this->chapterTitle($result),
        'position' => $position,
        'text' => (string) ($result['text'] ?? ''),
        'audio_files' => [['target_id' => $file->id()]],
        'last_generated_audio' => ['target_id' => $file->id()],
        'generation_metadata' => json_encode([
          'model' => $result['model'] ?? '',
          'voice' => $result['voice'] ?? '',
          'format' => $result['format'] ?? '',
          'parameters' => $result['parameters'] ?? [],
          'input_characters' => $result['characters'] ?? 0,
          'file_id' => $file->id(),
          'bytes' => $file->getSize(),
          'mime' => $file->getMimeType(),
          'runtime_seconds' => $result['duration'] ?? NULL,
          'generation_seconds' => $result['seconds'] ?? NULL,
          'generated_at' => gmdate(DATE_ATOM, (int) ($result['generated_at'] ?? time())),
          'source' => $result['source'] ?? 'local text',
        ], JSON_UNESCAPED_SLASHES),
        'status' => 'complete',
      ]);
      $chapter->save();
      $result['chapter_url'] = $chapter->toUrl('canonical', ['absolute' => TRUE])->toString();
      $result['project_id'] = $projectId;
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_project', $projectId);
      $form_state->set('result', $result);
      $form_state->setRebuild();
      $this->messenger()->addStatus($this->t('Saved the generated narration as Chapter @position in %project.', ['@position' => $position, '%project' => $project->label()]));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

  /** Creates an editable Chapter title from the source or generated script. */
  private function chapterTitle(array $result): string {
    $title = trim((string) ($result['source'] ?? ''));
    if ($title === '') {
      $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($result['text'] ?? '')));
    }
    if ($title === '') {
      $title = $this->t('Generated narration @date', ['@date' => $this->formatGenerationDate((int) ($result['generated_at'] ?? time()))]);
    }
    return mb_substr((string) $title, 0, 255);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $text = (string) $form_state->getValue('text');
      $model = (string) $form_state->getValue('model');
      $voice = (string) ($form_state->getValue('voice') ?? '');
      $format = (string) ($form_state->getValue('format') ?? '');
      $parameters = [
        'language' => (string) ($form_state->getValue('language') ?? ''),
        'speed' => $form_state->getValue('speed') ?? 1,
        'text_normalization' => (bool) ($form_state->getValue('text_normalization') ?? FALSE),
      ];
      if (str_starts_with($model, 'elevenlabs__')) {
        foreach (['stability', 'similarity_boost', 'style', 'use_speaker_boost', 'language_code', 'apply_text_normalization', 'apply_language_text_normalization', 'seed', 'previous_text', 'next_text', 'use_pvc_as_ivc'] as $key) {
          $parameters[$key] = $form_state->getValue($key);
        }
        foreach (['pronunciation_dictionary_locators', 'previous_request_ids', 'next_request_ids'] as $key) {
          $value = trim((string) $form_state->getValue($key));
          if ($value !== '') {
            $decoded = json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) > 3) {
              throw new \InvalidArgumentException("$key must be a JSON array with no more than three entries.");
            }
            $parameters[$key] = $decoded;
          }
        }
      }
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_text', $text);
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_model', $model);
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_voice', $voice);
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_format', $format);
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'player_mode', (string) $form_state->getValue('player_mode'));
      $source = $form_state->get('source_snapshot');
      $sourceDescription = NULL;
      if (is_array($source) && in_array($source['type'] ?? '', ['remote_url', 'entity'], TRUE)) {
        $sourceDescription = (string) ($source['label'] ?? '') ?: (string) ($source['url'] ?? '');
      }
      $started = microtime(TRUE);
      $generatedAt = \Drupal::service('datetime.time')->getRequestTime();
      $limit = $this->generator->getInputCharacterLimit($model) ?? 4096;
      $files = [];
      foreach (\Drupal::service('ai_narration.text_chunker')->split($text, $limit) as $index => $chunk) {
        $files[] = $this->generator->generate($chunk, $model, $voice, $format, $parameters, 'narration-' . ($index + 1));
      }
      $file = end($files);
      $entire = \Drupal::service('ai_narration.audio_merger')->merge($files, 'narration-entire');
      $segment_markers = array_map(fn ($audio_file, int $index) => ['duration' => $this->getDurationInspector()->getDuration($audio_file) ?? 0.0, 'label' => 'Part ' . ($index + 1)], $files, array_keys($files));
      $result_file = $entire ?: $file;
      $url = $this->fileUrlGenerator->generateAbsoluteString($result_file->getFileUri());
      $form_state->set('result', [
        'url' => $url,
        'fid' => $result_file->id(),
        'segments' => array_map(fn ($audio_file) => ['url' => $this->fileUrlGenerator->generateAbsoluteString($audio_file->getFileUri())], $files),
        'entire' => $entire ? ['url' => $url, 'markers' => $segment_markers] : NULL,
        'model' => $model,
        'voice' => $voice,
        'format' => $format,
        'parameters' => $parameters,
        'characters' => mb_strlen($text),
        'bytes' => array_sum(array_map(static fn ($audio_file) => $audio_file->getSize(), $files)),
        'mime' => $file->getMimeType(),
        'duration' => $this->getDurationInspector()->getDuration($file),
        'seconds' => microtime(TRUE) - $started,
        'generated_at' => $generatedAt,
        'source' => $sourceDescription,
        'text' => $text,
      ]);
      $form_state->setRebuild();
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
