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
  protected NarrationDurationInspector $durationInspector;

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
      '#description' => $options === [] ? $this->t('No usable text-to-speech provider is configured.') : $this->t('Voices are supplied by the enabled Drupal AI providers.'),
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
        '#description' => $this->t('Voice is applied to this request without changing the provider-wide default.'),
      ];
    }
    $formats = $this->generator->getFormatOptions($model);
    if ($formats !== []) {
      $form['voice_wrapper']['format'] = [
        '#type' => 'select',
        '#title' => $this->t('Output format'),
        '#options' => $formats,
        '#default_value' => isset($formats[$form_state->getValue('format') ?? $savedFormat]) ? ($form_state->getValue('format') ?? $savedFormat) : array_key_first($formats),
        '#description' => $this->t('The selected provider determines the available formats.'),
      ];
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
      $media = $this->mediaPublisher->publish($file, $this->t('AI narration: @filename', ['@filename' => $file->getFilename()]));
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
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_text', $text);
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_model', $model);
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_voice', $voice);
      $this->userData->set('ai_narration', $this->currentUser()->id(), 'last_format', $format);
      $started = microtime(TRUE);
      $file = $this->generator->generate($text, $model, $voice, $format);
      $url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      $form_state->set('result', [
        'url' => $url,
        'fid' => $file->id(),
        'model' => $model,
        'voice' => $voice,
        'format' => $format,
        'characters' => mb_strlen($text),
        'bytes' => $file->getSize(),
        'mime' => $file->getMimeType(),
        'duration' => $this->durationInspector->getDuration($file),
        'seconds' => microtime(TRUE) - $started,
      ]);
      $form_state->setRebuild();
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
