<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Form;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures Studio limits, storage, and Media publishing.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly ImageGenerator $generator,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('ai_image_studio.generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_image_studio_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_image_studio.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_image_studio.settings');
    $bundles = [];
    foreach ($this->bundleInfo->getBundleInfo('media') as $id => $definition) {
      $bundles[$id] = $definition['label'];
    }

    $form['interface'] = [
      '#type' => 'details',
      '#title' => $this->t('Studio interface'),
      '#open' => TRUE,
    ];
    $form['interface']['default_history_order'] = [
      '#type' => 'select',
      '#title' => $this->t('Default sort in Studio'),
      '#options' => [
        'newest' => $this->t('Newest first'),
        'oldest' => $this->t('Oldest first'),
      ],
      '#default_value' => $config->get('default_history_order') ?: 'newest',
      '#description' => $this->t('Sets the initial order of image versions when a session is opened or a new image is generated. Users can change the order while viewing a session.'),
    ];
    $form['interface']['generation_form_open'] = $this->checkbox(
      $this->t('Open the generation form initially'),
      $config->get('generation_form_open'),
      TRUE,
    );
    $form['interface']['image_settings_open'] = $this->checkbox(
      $this->t('Open image settings initially'),
      $config->get('image_settings_open'),
      TRUE,
    );
    $form['interface']['video_settings_open'] = $this->checkbox(
      $this->t('Open video settings initially'),
      $config->get('video_settings_open'),
      TRUE,
    );
    $form['interface']['show_request_metadata'] = $this->checkbox(
      $this->t('Show provider, model, request, and processing metadata'),
      $config->get('show_request_metadata'),
      TRUE,
    );
    $form['interface']['show_token_usage'] = $this->checkbox(
      $this->t('Show token usage when available'),
      $config->get('show_token_usage'),
      TRUE,
    );
    $form['interface']['show_costs'] = $this->checkbox(
      $this->t('Show reported and estimated costs'),
      $config->get('show_costs'),
      TRUE,
    );
    $form['interface']['show_session_report'] = $this->checkbox(
      $this->t('Show the session-wide version and cost report'),
      $config->get('show_session_report'),
      TRUE,
    );

    $form['generation'] = [
      '#type' => 'details',
      '#title' => $this->t('Generation defaults'),
      '#open' => TRUE,
    ];
    $form['generation']['default_output_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default output type'),
      '#options' => [
        'image' => $this->t('Image'),
        'video' => $this->t('Video'),
      ],
      '#default_value' => $config->get('default_output_type') ?: 'image',
    ];
    $form['generation']['default_aspect_ratio'] = [
      '#type' => 'select',
      '#title' => $this->t('Default aspect ratio'),
      '#options' => $this->aspectRatioOptions(),
      '#default_value' => $config->get('default_aspect_ratio') ?: 'auto',
    ];
    $form['generation']['default_image_resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Default image resolution'),
      '#options' => [
        'auto' => $this->t('Automatic'),
        '1k' => $this->t('1K'),
        '2k' => $this->t('2K'),
      ],
      '#default_value' => $config->get('default_image_resolution') ?: 'auto',
    ];
    $form['generation']['default_image_quality'] = [
      '#type' => 'select',
      '#title' => $this->t('Default image quality'),
      '#options' => [
        'medium' => $this->t('Medium'),
        'low' => $this->t('Low'),
      ],
      '#default_value' => $config->get('default_image_quality') ?: 'medium',
      '#description' => $this->t('Grok Imagine Image 2.0 supports low and medium quality. Other models may ignore this setting.'),
    ];
    $form['generation']['default_image_variations'] = [
      '#type' => 'select',
      '#title' => $this->t('Default image variations'),
      '#options' => [1 => '1', 2 => '2', 3 => '3', 4 => '4'],
      '#default_value' => (int) ($config->get('default_image_variations') ?: 1),
    ];
    $form['generation']['default_video_resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Default video resolution'),
      '#options' => [
        '480p' => $this->t('480p'),
        '720p' => $this->t('720p'),
        '1080p' => $this->t('1080p'),
      ],
      '#default_value' => $config->get('default_video_resolution') ?: '720p',
    ];
    $form['generation']['default_video_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Default video duration'),
      '#field_suffix' => $this->t('seconds'),
      '#default_value' => $config->get('default_video_duration') ?: 5,
      '#min' => 1,
      '#max' => 15,
      '#required' => TRUE,
    ];
    $form['generation']['default_transparent_background'] = $this->checkbox(
      $this->t('Request a transparent background by default'),
      $config->get('default_transparent_background'),
    );
    $form['generation']['default_image_file_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default image file type'),
      '#options' => [
        'png' => $this->t('PNG'),
        'jpeg' => $this->t('JPEG'),
        'webp' => $this->t('WebP'),
      ],
      '#default_value' => $config->get('default_image_file_type') ?: 'png',
      '#description' => $this->t('The selected provider must support the requested format. PNG is used by default.'),
    ];
    $form['generation']['default_show_ai_badge'] = $this->checkbox(
      $this->t('Show an AI image badge by default'),
      $config->get('default_show_ai_badge'),
    );
    $form['generation']['default_ai_badge_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default AI image badge text'),
      '#default_value' => $config->get('default_ai_badge_text') ?: 'AI Image',
      '#maxlength' => 80,
      '#states' => [
        'visible' => [
          ':input[name="default_show_ai_badge"]' => ['checked' => TRUE],
        ],
      ],
    ];
    foreach ([
      'text_to_image' => $this->t('Default Text-to-Image model'),
      'image_to_image' => $this->t('Default Image-to-Image model'),
      'text_to_video' => $this->t('Default Text-to-Video model'),
      'image_to_video' => $this->t('Default Image-to-Video model'),
    ] as $operation => $label) {
      $key = 'default_' . $operation . '_model';
      $form['generation'][$key] = [
        '#type' => 'select',
        '#title' => $label,
        '#options' => $this->generator->getModelOptions($operation),
        '#default_value' => $config->get($key) ?: '',
        '#description' => $this->t('Leave as “None” to inherit the default configured by Drupal AI.'),
      ];
    }
    $form['storage'] = [
      '#type' => 'details',
      '#title' => $this->t('Draft storage'),
      '#open' => TRUE,
    ];
    $form['storage']['file_scheme'] = [
      '#type' => 'select',
      '#title' => $this->t('File scheme'),
      '#options' => ['private' => $this->t('Private'), 'public' => $this->t('Public')],
      '#default_value' => $config->get('file_scheme'),
      '#description' => $this->t('Private storage is recommended because generated drafts may contain sensitive material.'),
    ];
    $form['storage']['file_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('File directory'),
      '#default_value' => $config->get('file_directory'),
      '#required' => TRUE,
    ];
    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Limits'),
      '#open' => TRUE,
    ];
    $form['limits']['max_prompt_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum prompt length'),
      '#default_value' => $config->get('max_prompt_length'),
      '#min' => 100,
      '#max' => 20000,
      '#required' => TRUE,
    ];
    $form['limits']['max_turns'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum turns per session'),
      '#default_value' => $config->get('max_turns'),
      '#min' => 1,
      '#max' => 250,
      '#required' => TRUE,
    ];
    $form['limits']['max_source_image_size_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum uploaded source image size'),
      '#field_suffix' => $this->t('MB'),
      '#default_value' => $config->get('max_source_image_size_mb') ?: 20,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];
    $form['limits']['max_video_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum requested video duration'),
      '#field_suffix' => $this->t('seconds'),
      '#default_value' => $config->get('max_video_duration') ?: 15,
      '#min' => 1,
      '#max' => 15,
      '#required' => TRUE,
    ];
    $form['limits']['async_video_generation'] = $this->checkbox(
      $this->t('Queue video generation'),
      $config->get('async_video_generation'),
      TRUE,
    );
    $form['limits']['async_video_generation']['#description'] = $this->t('Recommended. Video requests run through Drupal’s queue and no longer hold the browser request open. Ensure cron or another queue runner is configured.');
    $form['limits']['generation_retry_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Generation retry limit'),
      '#default_value' => $config->get('generation_retry_limit') ?: 3,
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
    ];
    $form['limits']['request_cost_warning'] = [
      '#type' => 'number',
      '#title' => $this->t('Per-request cost warning'),
      '#field_prefix' => '$',
      '#field_suffix' => 'USD',
      '#default_value' => $config->get('request_cost_warning') ?? 0,
      '#min' => 0,
      '#step' => 0.01,
      '#description' => $this->t('Display a warning after a request reaches this cost. Use zero to disable.'),
    ];
    $form['limits']['session_cost_warning'] = [
      '#type' => 'number',
      '#title' => $this->t('Session cost warning'),
      '#field_prefix' => '$',
      '#field_suffix' => 'USD',
      '#default_value' => $config->get('session_cost_warning') ?? 0,
      '#min' => 0,
      '#step' => 0.01,
      '#description' => $this->t('Highlight session totals at or above this amount. Use zero to disable.'),
    ];
    $form['media'] = [
      '#type' => 'details',
      '#title' => $this->t('Media publishing'),
      '#open' => TRUE,
    ];
    $form['media']['media_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Media type'),
      '#options' => $bundles,
      '#default_value' => $config->get('media_bundle'),
      '#required' => TRUE,
    ];
    $form['media']['media_source_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image source field machine name'),
      '#default_value' => $config->get('media_source_field'),
      '#required' => TRUE,
    ];
    $form['media']['require_image_alt'] = $this->checkbox(
      $this->t('Require alternative text when publishing images'),
      $config->get('require_image_alt'),
      TRUE,
    );
    $form['media']['video_media_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Video Media type'),
      '#options' => $bundles,
      '#default_value' => $config->get('video_media_bundle') ?: 'video',
      '#required' => TRUE,
    ];
    $form['media']['video_media_source_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Video source field machine name'),
      '#default_value' => $config->get('video_media_source_field') ?: 'field_media_video_file',
      '#required' => TRUE,
    ];

    $diagnostic_rows = [];
    foreach ([
      'text_to_image' => $this->t('Text to image'),
      'image_to_image' => $this->t('Image to image'),
      'text_to_video' => $this->t('Text to video'),
      'image_to_video' => $this->t('Image to video'),
    ] as $operation => $label) {
      $options = array_filter(
        $this->generator->getModelOptions($operation),
        static fn (string $key): bool => $key !== '',
        ARRAY_FILTER_USE_KEY,
      );
      $diagnostic_rows[] = [
        $label,
        count($options) === 1
          ? $this->t('1 configured model')
          : $this->t('@count configured models', [
            '@count' => count($options),
          ]),
        $this->configuredModelLabel($config, $operation, $options),
      ];
    }
    $form['diagnostics'] = [
      '#type' => 'details',
      '#title' => $this->t('Provider diagnostics'),
      '#open' => FALSE,
      'description' => [
        '#markup' => '<p class="description">'
        . $this->t('Models are discovered from Drupal AI and filtered by the operation each provider advertises.')
        . '</p>',
      ],
      'operations' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Operation'),
          $this->t('Availability'),
          $this->t('Studio default'),
        ],
        '#rows' => $diagnostic_rows,
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $directory = (string) $form_state->getValue('file_directory');
    if (!preg_match('/^[a-zA-Z0-9_\\/-]+$/', $directory) || str_contains($directory, '..')) {
      $form_state->setErrorByName('file_directory', $this->t('Use a relative directory containing only letters, numbers, underscores, dashes, and slashes.'));
    }
    if ((int) $form_state->getValue('default_video_duration')
      > (int) $form_state->getValue('max_video_duration')) {
      $form_state->setErrorByName(
        'default_video_duration',
        $this->t('The default video duration cannot exceed the configured maximum.'),
      );
    }

    $bundle = (string) $form_state->getValue('media_bundle');
    $field = (string) $form_state->getValue('media_source_field');
    $definitions = $this->fieldManager->getFieldDefinitions('media', $bundle);
    if (!isset($definitions[$field]) || $definitions[$field]->getType() !== 'image') {
      $form_state->setErrorByName('media_source_field', $this->t('The selected Media type must contain this image field.'));
    }

    $video_bundle = (string) $form_state->getValue('video_media_bundle');
    $video_field = (string) $form_state->getValue('video_media_source_field');
    $video_definitions = $this->fieldManager->getFieldDefinitions('media', $video_bundle);
    if (!isset($video_definitions[$video_field])
      || $video_definitions[$video_field]->getType() !== 'file') {
      $form_state->setErrorByName('video_media_source_field', $this->t('The selected Media type must contain this video file field.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_image_studio.settings')
      ->set('file_scheme', $form_state->getValue('file_scheme'))
      ->set('file_directory', trim((string) $form_state->getValue('file_directory'), '/'))
      ->set('max_prompt_length', (int) $form_state->getValue('max_prompt_length'))
      ->set('max_turns', (int) $form_state->getValue('max_turns'))
      ->set('default_history_order', $form_state->getValue('default_history_order'))
      ->set('generation_form_open', (bool) $form_state->getValue('generation_form_open'))
      ->set('image_settings_open', (bool) $form_state->getValue('image_settings_open'))
      ->set('video_settings_open', (bool) $form_state->getValue('video_settings_open'))
      ->set('show_request_metadata', (bool) $form_state->getValue('show_request_metadata'))
      ->set('show_token_usage', (bool) $form_state->getValue('show_token_usage'))
      ->set('show_costs', (bool) $form_state->getValue('show_costs'))
      ->set('show_session_report', (bool) $form_state->getValue('show_session_report'))
      ->set('default_output_type', $form_state->getValue('default_output_type'))
      ->set('default_aspect_ratio', $form_state->getValue('default_aspect_ratio'))
      ->set('default_image_resolution', $form_state->getValue('default_image_resolution'))
      ->set('default_image_quality', $form_state->getValue('default_image_quality'))
      ->set('default_image_variations', (int) $form_state->getValue('default_image_variations'))
      ->set('default_video_resolution', $form_state->getValue('default_video_resolution'))
      ->set('default_video_duration', (int) $form_state->getValue('default_video_duration'))
      ->set('default_transparent_background', (bool) $form_state->getValue('default_transparent_background'))
      ->set('default_image_file_type', $form_state->getValue('default_image_file_type'))
      ->set('default_show_ai_badge', (bool) $form_state->getValue('default_show_ai_badge'))
      ->set('default_ai_badge_text', trim((string) $form_state->getValue('default_ai_badge_text')) ?: 'AI Image')
      ->set('default_text_to_image_model', $form_state->getValue('default_text_to_image_model'))
      ->set('default_image_to_image_model', $form_state->getValue('default_image_to_image_model'))
      ->set('default_text_to_video_model', $form_state->getValue('default_text_to_video_model'))
      ->set('default_image_to_video_model', $form_state->getValue('default_image_to_video_model'))
      ->set('max_source_image_size_mb', (int) $form_state->getValue('max_source_image_size_mb'))
      ->set('max_video_duration', (int) $form_state->getValue('max_video_duration'))
      ->set('async_video_generation', (bool) $form_state->getValue('async_video_generation'))
      ->set('generation_retry_limit', (int) $form_state->getValue('generation_retry_limit'))
      ->set('request_cost_warning', (float) $form_state->getValue('request_cost_warning'))
      ->set('session_cost_warning', (float) $form_state->getValue('session_cost_warning'))
      ->set('media_bundle', $form_state->getValue('media_bundle'))
      ->set('media_source_field', $form_state->getValue('media_source_field'))
      ->set('require_image_alt', (bool) $form_state->getValue('require_image_alt'))
      ->set('video_media_bundle', $form_state->getValue('video_media_bundle'))
      ->set('video_media_source_field', $form_state->getValue('video_media_source_field'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Builds a checkbox with a safe default for upgraded installations.
   */
  private function checkbox(
    string|\Stringable $title,
    mixed $configured,
    bool $fallback = FALSE,
  ): array {
    return [
      '#type' => 'checkbox',
      '#title' => $title,
      '#default_value' => $configured === NULL ? $fallback : (bool) $configured,
    ];
  }

  /**
   * Returns shared aspect-ratio options.
   */
  private function aspectRatioOptions(): array {
    return [
      'auto' => $this->t('Automatic — match source'),
      '1:1' => $this->t('Square — 1:1'),
      '16:9' => $this->t('Landscape — 16:9'),
      '9:16' => $this->t('Portrait — 9:16'),
      '4:3' => $this->t('Landscape — 4:3'),
      '3:4' => $this->t('Portrait — 3:4'),
      '3:2' => $this->t('Landscape — 3:2'),
      '2:3' => $this->t('Portrait — 2:3'),
      '2:1' => $this->t('Wide — 2:1'),
      '1:2' => $this->t('Tall — 1:2'),
    ];
  }

  /**
   * Describes the effective model selection for diagnostics.
   */
  private function configuredModelLabel(
    object $config,
    string $operation,
    array $options,
  ): string|\Stringable {
    $configured = (string) $config->get('default_' . $operation . '_model');
    if ($configured !== '' && isset($options[$configured])) {
      return $options[$configured];
    }
    $default = $this->generator->getDefaultModel($operation);
    return $default !== '' && isset($options[$default])
      ? $this->t('@model (Drupal AI default)', ['@model' => $options[$default]])
      : $this->t('No model available');
  }

}
