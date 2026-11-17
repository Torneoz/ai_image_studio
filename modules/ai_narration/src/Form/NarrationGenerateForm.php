<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\ai_narration\Service\NarrationMediaPublisher;
use Drupal\ai_narration\Service\NarrationDurationInspector;
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

  public function __construct(
    NarrationGenerator $generator,
    ConfigFactoryInterface $narration_config_factory,
    FileUrlGeneratorInterface $file_url_generator,
    UserDataInterface $user_data,
    NarrationMediaPublisher $media_publisher,
    EntityTypeManagerInterface $entity_type_manager,
    NarrationDurationInspector $duration_inspector,
  ) {
    $this->generator = $generator;
    $this->narrationConfigFactory = $narration_config_factory;
    $this->fileUrlGenerator = $file_url_generator;
    $this->userData = $user_data;
    $this->mediaPublisher = $media_publisher;
    $this->entityTypeManager = $entity_type_manager;
    $this->durationInspector = $duration_inspector;
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
    $textDefault = (string) ($form_state->getValue('text') ?? $savedText);
    $submittedModel = (string) ($form_state->getValue('model') ?? '');
    $model = isset($options[$submittedModel]) ? $submittedModel : (isset($options[$savedModel]) ? $savedModel : (isset($options[$default]) ? $default : ''));
    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Create a single narrated audio file from a script. Select a text-to-speech model and voice, adjust the available request controls, then preview, download, or save the completed narration to the Media Library.'),
    ];
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Narration text'),
      '#description' => $this->t('Use only text you are authorized to turn into speech. Your selected provider applies its own length and content limits.'),
      '#required' => TRUE,
      '#rows' => 10,
      '#default_value' => $textDefault,
      '#attributes' => ['data-ai-narration-text' => TRUE],
    ];
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
        '#attributes' => ['controls' => TRUE, 'src' => $result['url']],
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
      $form['feedback']['metadata'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Generation details'),
        '#items' => [
          $this->t('Model: @model', ['@model' => $result['model']]),
          $result['voice'] === '' ? $this->t('Voice: provider default') : $this->t('Voice: @voice', ['@voice' => $result['voice']]),
          $result['format'] === '' ? $this->t('Format: provider default') : $this->t('Format: @format', ['@format' => strtoupper($result['format'])]),
          $this->t('Input: @characters characters', ['@characters' => $result['characters']]),
          $this->t('File: @size bytes (@mime)', ['@size' => number_format($result['bytes']), '@mime' => $result['mime']]),
          $this->t('Generation time: @seconds seconds', ['@seconds' => number_format($result['seconds'], 2)]),
          $result['duration'] === NULL ? $this->t('Clip runtime: unavailable') : $this->t('Clip runtime: @duration', ['@duration' => $this->formatDuration($result['duration'])]),
          $result['parameters'] === [] ? $this->t('Request parameters: provider defaults') : $this->t('Request parameters: @parameters', ['@parameters' => json_encode($result['parameters'], JSON_UNESCAPED_SLASHES)]),
          $this->t('Provider-reported price: unavailable'),
        ],
      ];
    }
    return $form;
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
      $started = microtime(TRUE);
      $file = $this->generator->generate($text, $model, $voice, $format, $parameters);
      $url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      $form_state->set('result', [
        'url' => $url,
        'fid' => $file->id(),
        'model' => $model,
        'voice' => $voice,
        'format' => $format,
        'parameters' => $parameters,
        'characters' => mb_strlen($text),
        'bytes' => $file->getSize(),
        'mime' => $file->getMimeType(),
        'duration' => $this->getDurationInspector()->getDuration($file),
        'seconds' => microtime(TRUE) - $started,
      ]);
      $form_state->setRebuild();
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
