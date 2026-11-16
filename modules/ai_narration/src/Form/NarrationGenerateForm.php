<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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

  public function __construct(
    NarrationGenerator $generator,
    ConfigFactoryInterface $narration_config_factory,
    FileUrlGeneratorInterface $file_url_generator,
    UserDataInterface $user_data,
  ) {
    $this->generator = $generator;
    $this->narrationConfigFactory = $narration_config_factory;
    $this->fileUrlGenerator = $file_url_generator;
    $this->userData = $user_data;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_narration.generator'),
      $container->get('config.factory'),
      $container->get('file_url_generator'),
      $container->get('user.data'),
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
    $submittedModel = (string) ($form_state->getValue('model') ?? '');
    $model = isset($options[$submittedModel]) ? $submittedModel : (isset($options[$savedModel]) ? $savedModel : (isset($options[$default]) ? $default : ''));
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Narration text'),
      '#description' => $this->t('Use only text you are authorized to turn into speech. Your selected provider applies its own length and content limits.'),
      '#required' => TRUE,
      '#rows' => 10,
      '#default_value' => $form_state->getValue('text') ?? $savedText,
    ];
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Text-to-speech model'),
      '#options' => $options,
      '#default_value' => $model,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a voice -'),
      '#description' => $options === [] ? $this->t('No usable text-to-speech provider is configured.') : $this->t('Voices are supplied by the enabled Drupal AI providers.'),
      '#ajax' => ['callback' => '::refreshVoice', 'wrapper' => 'ai-narration-voice'],
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
      $form['feedback']['message'] = ['#markup' => '<p><strong>' . $this->t('Narration generated.') . '</strong></p>'];
      $form['feedback']['player'] = [
        '#type' => 'html_tag',
        '#tag' => 'audio',
        '#attributes' => ['controls' => TRUE, 'src' => $result['url']],
      ];
      $form['feedback']['download'] = [
        '#type' => 'link',
        '#title' => $this->t('Download generated narration'),
        '#url' => Url::fromUri($result['url']),
        '#attributes' => ['download' => TRUE],
      ];
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
          $this->t('Provider-reported price: unavailable'),
        ],
      ];
    }
    return $form;
  }

  /**
   * Rebuilds request-level voices after a model changes.
   */
  public function refreshVoice(array &$form, FormStateInterface $form_state): array {
    return $form['voice_wrapper'];
  }

  /**
   * Returns completed generation feedback to the AJAX request.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state): array {
    return $form['feedback'];
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
        'model' => $model,
        'voice' => $voice,
        'format' => $format,
        'characters' => mb_strlen($text),
        'bytes' => $file->getSize(),
        'mime' => $file->getMimeType(),
        'seconds' => microtime(TRUE) - $started,
      ]);
      $form_state->setRebuild();
      $this->messenger()->addStatus($this->t('Narration generated. Use the player below to preview or download it.'));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
