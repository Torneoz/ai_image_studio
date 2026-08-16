<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Form;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the conversational image workspace.
 */
final class StudioForm extends FormBase {

  /**
   * Constructs the Studio form.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ImageGenerator $generator,
    private readonly ConfigFactoryInterface $studioConfigFactory,
    private readonly AccountProxyInterface $currentUserProxy,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ai_image_studio.generator'),
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

  /**
   * Returns the session page title.
   */
  public function title(object $ai_image_studio_session): string {
    return (string) $ai_image_studio_session->label();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_image_studio_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?object $ai_image_studio_session = NULL,
  ): array {
    $session = $ai_image_studio_session;
    $form_state->set('session_id', $session?->id());
    $settings = $this->studioConfigFactory->get('ai_image_studio.settings');
    $max_length = (int) ($settings->get('max_prompt_length') ?: 4000);
    $max_upload_bytes = (int) ($settings->get('max_source_image_size_mb') ?: 20)
      * 1024 * 1024;
    $default_output_type = (string) ($settings->get('default_output_type') ?: 'image');

    $form['#attached']['library'][] = 'ai_image_studio/studio';
    $form['#attributes']['class'][] = 'ai-image-studio-layout';
    if ($session !== NULL && $this->sessionHasActiveGeneration((int) $session->id())) {
      $form['#attached']['html_head'][] = [[
        '#tag' => 'meta',
        '#attributes' => [
          'http-equiv' => 'refresh',
          'content' => '5',
        ],
      ], 'ai_image_studio_generation_refresh',
      ];
    }
    $form['generation_feedback'] = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => [
        'class' => ['ai-image-studio-generation-feedback'],
        'data-ai-image-studio-generation-feedback' => '',
        'hidden' => 'hidden',
        'role' => 'status',
        'aria-live' => 'polite',
        'aria-atomic' => 'true',
      ],
      'spinner' => [
        '#markup' => '<span class="ai-image-studio-generation-feedback__spinner" aria-hidden="true"></span>',
      ],
      'copy' => [
        '#type' => 'container',
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => $this->t('Starting generation…'),
          '#attributes' => [
            'data-ai-image-studio-generation-title' => '',
          ],
        ],
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $this->t('Your request is being sent to the selected provider. Keep this page open.'),
          '#attributes' => [
            'data-ai-image-studio-generation-message' => '',
          ],
        ],
      ],
    ];

    if ($session === NULL) {
      $form['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Session title'),
        '#required' => TRUE,
        '#maxlength' => 255,
      ];
      $form['start_mode'] = [
        '#type' => 'radios',
        '#title' => $this->t('Start from'),
        '#options' => [
          'prompt' => $this->t('A text prompt'),
          'upload' => $this->t('An uploaded image'),
          'media' => $this->t('A Media image'),
        ],
        '#default_value' => 'prompt',
      ];
      $form['output_type'] = [
        '#type' => 'radios',
        '#title' => $this->t('Create'),
        '#options' => [
          'image' => $this->t('Image'),
          'video' => $this->t('Video'),
        ],
        '#default_value' => $default_output_type,
        '#description' => $this->t('Video generation is experimental and runs synchronously. Keep the page open until the request completes.'),
      ];
      $form['source_image'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Starting image'),
        '#upload_location' => 'temporary://ai-image-studio',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg webp'],
          'FileSizeLimit' => [
            'fileLimit' => $max_upload_bytes,
          ],
        ],
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => ['value' => 'upload'],
          ],
          'required' => [
            ':input[name="start_mode"]' => ['value' => 'upload'],
          ],
        ],
      ];
      $form['source_media'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Media image'),
        '#target_type' => 'media',
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => [
            (string) ($settings->get('media_bundle') ?: 'image'),
          ],
        ],
        '#description' => $this->t('Search for an existing image in Media.'),
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => ['value' => 'media'],
          ],
          'required' => [
            ':input[name="start_mode"]' => ['value' => 'media'],
          ],
        ],
      ];
      $form['text_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and model'),
        '#options' => $this->generator->getModelOptions('text_to_image'),
        '#default_value' => $this->configuredDefaultModel('text_to_image'),
        '#description' => $this->t('Uses the Text-to-Image default configured in Drupal AI. Only configured providers and models are listed.'),
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => ['value' => 'prompt'],
            ':input[name="output_type"]' => ['value' => 'image'],
          ],
        ],
      ];
      $form['image_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and model'),
        '#options' => $this->generator->getModelOptions('image_to_image'),
        '#default_value' => $this->configuredDefaultModel('image_to_image'),
        '#description' => $this->t('Only providers that advertise Image-to-Image editing support are listed.'),
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => [
              ['value' => 'upload'],
              'or',
              ['value' => 'media'],
            ],
            ':input[name="output_type"]' => ['value' => 'image'],
          ],
        ],
      ];
      $form['text_video_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and video model'),
        '#options' => $this->generator->getModelOptions('text_to_video'),
        '#default_value' => $this->configuredDefaultModel('text_to_video'),
        '#description' => $this->t('Only configured providers that advertise Text-to-Video support are listed.'),
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => ['value' => 'prompt'],
            ':input[name="output_type"]' => ['value' => 'video'],
          ],
        ],
      ];
      $form['image_video_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and video model'),
        '#options' => $this->generator->getModelOptions('image_to_video'),
        '#default_value' => $this->configuredDefaultModel('image_to_video'),
        '#description' => $this->t('Animates the selected image using an Image-to-Video provider.'),
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => [
              ['value' => 'upload'],
              'or',
              ['value' => 'media'],
            ],
            ':input[name="output_type"]' => ['value' => 'video'],
          ],
        ],
      ];
      $form['prompt'] = $this->promptElement(
        $this->t('Describe the image to create or how to transform the upload.'),
        $max_length,
      );
      $form['generation_controls'] = $this->generationControls();
      $form['video_controls'] = $this->videoControls();
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['generate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Create'),
        '#button_type' => 'primary',
        '#studio_action' => 'generate',
        '#attributes' => [
          'data-ai-image-studio-generate' => '',
          'data-generating-image-label' => $this->t('Generating image…'),
          'data-generating-video-label' => $this->t('Generating video…'),
        ],
      ];
      return $form;
    }

    $turns = $this->loadTurns((int) $session->id());
    $latest = $this->latestCompletedTurn((int) $session->id());
    $turn_numbers = [];
    foreach (array_values($turns) as $index => $turn) {
      $turn_numbers[(int) $turn->id()] = $index + 1;
    }
    $selected_turn_id = (int) (
      $form_state->getValue('source_turn_id') ?: $latest?->id()
    );
    $selected_source = $this->completedTurnFromSession(
      (int) $session->id(),
      $selected_turn_id,
    ) ?? $latest;
    $selected_turn_id = (int) ($selected_source?->id() ?? 0);
    $selected_settings = $selected_source === NULL
      ? []
      : (array) ($selected_source->get('generation_settings')->first()?->getValue() ?? []);

    $form['history_order'] = [
      '#type' => 'select',
      '#weight' => -30,
      '#title' => $this->t('Version order'),
      '#options' => [
        'oldest' => $this->t('Oldest first'),
        'newest' => $this->t('Newest first'),
      ],
      '#default_value' => $settings->get('default_history_order') ?: 'newest',
      '#description' => $this->t('Changes how this session’s versions are displayed. Version numbers and refinement relationships stay the same.'),
      '#attributes' => [
        'class' => ['ai-image-studio-history-order'],
        'data-ai-image-studio-history-order' => '',
      ],
      '#wrapper_attributes' => [
        'class' => ['ai-image-studio-history-order-wrapper'],
      ],
    ];
    $form['history'] = [
      '#type' => 'container',
      '#weight' => -20,
      '#tree' => TRUE,
      '#attributes' => ['class' => ['ai-image-studio-turns']],
    ];
    $version_number = 1;
    foreach ($turns as $turn) {
      $form['history']['turn_' . $turn->id()] = $this->buildTurn(
        $turn,
        $version_number,
        $selected_turn_id,
        $turn_numbers,
      );
      $version_number++;
    }
    if ($settings->get('show_session_report') !== FALSE
      && $settings->get('show_costs') !== FALSE) {
      $form['session_report'] = $this->buildSessionReport($turns, $turn_numbers);
      $form['session_report']['#weight'] = -10;
    }

    $max_turns = (int) ($settings->get('max_turns') ?: 25);
    if (count($turns) >= $max_turns) {
      $form['limit'] = [
        '#type' => 'status_messages',
        '#weight' => -40,
        '#message_list' => [
          'warning' => [
            $this->t('This session has reached its limit of @count turns.', ['@count' => $max_turns]),
          ],
        ],
      ];
    }
    elseif ($session->access('update')) {
      $form['refine'] = [
        '#type' => 'details',
        '#weight' => -40,
        '#title' => $selected_source
          ? $this->t('Create from selected image')
          : $this->t('Create another result'),
        '#open' => $settings->get('generation_form_open') !== FALSE,
      ];
      if ($selected_source !== NULL) {
        $source_number = $turn_numbers[(int) $selected_source->id()] ?? 1;
        $source_prompt = (string) $selected_source->get('prompt')->value;
        $source_file = $selected_source->get('image')->entity;
        $form['refine']['source'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['ai-image-studio-source-preview'],
            'data-ai-image-studio-source-preview' => '',
          ],
          'image' => $source_file instanceof FileInterface
            ? [
              '#theme' => 'image',
              '#uri' => $source_file->getFileUri(),
              '#alt' => $source_prompt,
              '#attributes' => [
                'data-ai-image-studio-source-preview-image' => '',
              ],
            ]
            : [],
          'copy' => [
            '#type' => 'container',
            'eyebrow' => [
              '#markup' => '<span class="ai-image-studio-source-preview__eyebrow">'
              . $this->t('Refining from') . '</span>',
            ],
            'title' => [
              '#type' => 'html_tag',
              '#tag' => 'strong',
              '#value' => $this->t('Version @number · @prompt', [
                '@number' => $source_number,
                '@prompt' => $this->promptSummary($source_prompt),
              ]),
              '#attributes' => [
                'data-ai-image-studio-source-preview-title' => '',
              ],
            ],
            'description' => [
              '#markup' => '<div class="description">'
              . $this->t('The new version will branch from this image. Select another source from any completed version in the results below.')
              . '</div>',
            ],
          ],
        ];
      }
      $form['refine']['output_type'] = [
        '#type' => 'radios',
        '#title' => $this->t('Create'),
        '#options' => [
          'image' => $this->t('Refined image'),
          'video' => $this->t('Video from this image'),
        ],
        '#default_value' => $default_output_type,
      ];
      $operation = $selected_source ? 'image_to_image' : 'text_to_image';
      $model_options = $this->generator->getModelOptions($operation);
      $inherited_model = $selected_source === NULL
        ? ''
        : $this->turnModelOption($selected_source);
      $form['refine']['model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and model'),
        '#options' => $model_options,
        '#default_value' => isset($model_options[$inherited_model])
          ? $inherited_model
          : $this->configuredDefaultModel($operation),
        '#description' => $selected_source
          ? $this->t('Only providers that advertise Image-to-Image editing support are listed.')
          : $this->t('Uses the Text-to-Image default configured in Drupal AI.'),
        '#required' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="output_type"]' => ['value' => 'image'],
          ],
        ],
      ];
      $video_operation = $selected_source ? 'image_to_video' : 'text_to_video';
      $form['refine']['video_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and video model'),
        '#options' => $this->generator->getModelOptions($video_operation),
        '#default_value' => $this->configuredDefaultModel($video_operation),
        '#description' => $selected_source
          ? $this->t('Animates the selected image using an Image-to-Video provider.')
          : $this->t('Uses a configured Text-to-Video provider.'),
        '#states' => [
          'visible' => [
            ':input[name="output_type"]' => ['value' => 'video'],
          ],
        ],
      ];
      $form['refine']['prompt'] = $this->promptElement(
        $selected_source
          ? $this->t('Describe only the change you want to make to the selected image.')
          : $this->t('Describe the image to create.'),
        $max_length,
      );
      $form['refine']['generation_controls'] = $this->generationControls($selected_settings);
      $form['refine']['video_controls'] = $this->videoControls($selected_settings);
      $form['refine']['actions'] = ['#type' => 'actions'];
      $form['refine']['actions']['generate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Generate'),
        '#button_type' => 'primary',
        '#studio_action' => 'generate',
        '#attributes' => [
          'data-ai-image-studio-generate' => '',
          'data-generating-image-label' => $this->t('Generating image…'),
          'data-generating-video-label' => $this->t('Generating video…'),
        ],
      ];
    }

    $form['session_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-image-studio-actions']],
    ];
    if ($session->access('delete')) {
      $form['session_actions']['delete'] = [
        '#type' => 'link',
        '#title' => $this->t('Delete session'),
        '#url' => Url::fromRoute('entity.ai_image_studio_session.delete_form', [
          'ai_image_studio_session' => $session->id(),
        ]),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }

    return $form;
  }

  /**
   * Creates the reusable prompt element.
   */
  private function promptElement(string|\Stringable $description, int $max_length): array {
    return [
      '#type' => 'textarea',
      '#title' => $this->t('Prompt'),
      '#description' => $description,
      // Validate this only for generation submissions. HTML's required
      // attribute would otherwise block the per-version Media submit buttons
      // before Drupal can apply their limited validation scope.
      '#required' => FALSE,
      '#maxlength' => $max_length,
      '#rows' => 5,
    ];
  }

  /**
   * Returns a valid Studio override or the Drupal AI operation default.
   */
  private function configuredDefaultModel(string $operation): string {
    $options = $this->generator->getModelOptions($operation);
    $configured = (string) $this->studioConfigFactory
      ->get('ai_image_studio.settings')
      ->get('default_' . $operation . '_model');
    return $configured !== '' && isset($options[$configured])
      ? $configured
      : $this->generator->getDefaultModel($operation);
  }

  /**
   * Builds common image output controls.
   */
  private function generationControls(array $defaults = []): array {
    $settings = $this->studioConfigFactory->get('ai_image_studio.settings');
    return [
      '#type' => 'details',
      '#title' => $this->t('Image settings'),
      '#open' => $settings->get('image_settings_open') !== FALSE,
      '#states' => [
        'visible' => [
          ':input[name="output_type"]' => ['value' => 'image'],
        ],
      ],
      'aspect_ratio' => [
        '#type' => 'select',
        '#title' => $this->t('Aspect ratio'),
        '#options' => [
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
          '19.5:9' => $this->t('Phone landscape — 19.5:9'),
          '9:19.5' => $this->t('Phone portrait — 9:19.5'),
          '20:9' => $this->t('Phone landscape — 20:9'),
          '9:20' => $this->t('Phone portrait — 9:20'),
        ],
        '#default_value' => $defaults['aspect_ratio'] ?? ($settings->get('default_aspect_ratio') ?: 'auto'),
        '#description' => $this->t('Automatic uses the selected or uploaded source image’s proportions when they can be detected. Without a source image, the provider chooses its default aspect ratio. Explicit ratios remain provider-dependent.'),
      ],
      'resolution' => [
        '#type' => 'select',
        '#title' => $this->t('Resolution'),
        '#options' => [
          'auto' => $this->t('Automatic — match source'),
          '1k' => $this->t('1K — faster and lower cost'),
          '2k' => $this->t('2K — higher detail and cost'),
        ],
        '#default_value' => $defaults['resolution'] ?? ($settings->get('default_image_resolution') ?: 'auto'),
        '#description' => $this->t('Automatic chooses the closest supported tier from the source image’s longest edge. Without a source, it uses 1K. Grok Imagine supports 1K and 2K; other providers may map or ignore this setting.'),
      ],
      'quality' => [
        '#type' => 'select',
        '#title' => $this->t('Generation quality'),
        '#options' => [
          'medium' => $this->t('Medium — standard quality'),
          'low' => $this->t('Low — faster generation'),
        ],
        '#default_value' => $defaults['quality'] ?? ($settings->get('default_image_quality') ?: 'medium'),
        '#description' => $this->t('Supported by Grok Imagine Image 2.0. Other models may ignore this setting.'),
        '#wrapper_attributes' => [
          'data-ai-image-studio-quality-control' => '',
        ],
      ],
      'variations' => [
        '#type' => 'select',
        '#title' => $this->t('Variations'),
        '#options' => [
          1 => '1',
          2 => '2',
          3 => '3',
          4 => '4',
        ],
        '#default_value' => (int) ($defaults['variations'] ?? ($settings->get('default_image_variations') ?: 1)),
        '#description' => $this->t('Requests multiple results in one call when the selected provider supports it. Each result is retained as a separate version.'),
      ],
      'transparent_background' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Request a transparent background'),
        '#default_value' => array_key_exists('transparent_background', $defaults)
          ? (bool) $defaults['transparent_background']
          : (bool) $settings->get('default_transparent_background'),
        '#description' => $this->t('Requests transparency when the provider supports it. Grok applies this as a best-effort text-to-image instruction; image editing and other providers may ignore it.'),
      ],
      'file_type' => [
        '#type' => 'select',
        '#title' => $this->t('File type'),
        '#options' => [
          'png' => $this->t('PNG'),
          'jpeg' => $this->t('JPEG'),
          'webp' => $this->t('WebP'),
        ],
        '#default_value' => $defaults['file_type'] ?? ($settings->get('default_image_file_type') ?: 'png'),
        '#description' => $this->t('PNG is the default. Availability of JPEG and WebP depends on the selected provider.'),
      ],
      'show_ai_badge' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show an AI image badge'),
        '#default_value' => array_key_exists('show_ai_badge', $defaults)
          ? (bool) $defaults['show_ai_badge']
          : (bool) $settings->get('default_show_ai_badge'),
      ],
      'ai_badge_text' => [
        '#type' => 'textfield',
        '#title' => $this->t('Badge text'),
        '#default_value' => $defaults['ai_badge_text'] ?? ($settings->get('default_ai_badge_text') ?: 'AI Image'),
        '#maxlength' => 80,
        '#states' => [
          'visible' => [
            ':input[name="show_ai_badge"]' => ['checked' => TRUE],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds video output controls.
   */
  private function videoControls(array $defaults = []): array {
    $settings = $this->studioConfigFactory->get('ai_image_studio.settings');
    $max_duration = (int) ($settings->get('max_video_duration') ?: 15);
    return [
      '#type' => 'details',
      '#title' => $this->t('Video settings'),
      '#open' => $settings->get('video_settings_open') !== FALSE,
      '#states' => [
        'visible' => [
          ':input[name="output_type"]' => ['value' => 'video'],
        ],
      ],
      'duration' => [
        '#type' => 'number',
        '#title' => $this->t('Duration'),
        '#field_suffix' => $this->t('seconds'),
        '#default_value' => min(
          $max_duration,
          (int) ($defaults['duration'] ?? ($settings->get('default_video_duration') ?: 5)),
        ),
        '#min' => 1,
        '#max' => $max_duration,
        '#description' => $this->t('The provider may limit or adjust the requested duration.'),
      ],
      'video_aspect_ratio' => [
        '#type' => 'select',
        '#title' => $this->t('Aspect ratio'),
        '#options' => [
          'auto' => $this->t('Automatic — match source'),
          '1:1' => $this->t('Square — 1:1'),
          '16:9' => $this->t('Landscape — 16:9'),
          '9:16' => $this->t('Portrait — 9:16'),
          '4:3' => $this->t('Landscape — 4:3'),
          '3:4' => $this->t('Portrait — 3:4'),
          '3:2' => $this->t('Landscape — 3:2'),
          '2:3' => $this->t('Portrait — 2:3'),
        ],
        '#default_value' => $defaults['aspect_ratio'] ?? ($settings->get('default_aspect_ratio') ?: 'auto'),
        '#description' => $this->t('Automatic matches the selected source image where possible.'),
      ],
      'video_resolution' => [
        '#type' => 'select',
        '#title' => $this->t('Resolution'),
        '#options' => [
          '480p' => $this->t('480p — faster and lower cost'),
          '720p' => $this->t('720p — standard'),
          '1080p' => $this->t('1080p — provider dependent'),
        ],
        '#default_value' => $defaults['resolution'] ?? ($settings->get('default_video_resolution') ?: '720p'),
        '#description' => $this->t('Text-to-video providers may not support 1080p.'),
        '#attributes' => [
          'data-ai-image-studio-video-resolution' => '',
        ],
      ],
      'video_show_ai_badge' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show an AI image badge'),
        '#default_value' => array_key_exists('show_ai_badge', $defaults)
          ? (bool) $defaults['show_ai_badge']
          : (bool) $settings->get('default_show_ai_badge'),
      ],
      'video_ai_badge_text' => [
        '#type' => 'textfield',
        '#title' => $this->t('Badge text'),
        '#default_value' => $defaults['ai_badge_text'] ?? ($settings->get('default_ai_badge_text') ?: 'AI Image'),
        '#maxlength' => 80,
        '#states' => [
          'visible' => [
            ':input[name="video_show_ai_badge"]' => ['checked' => TRUE],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds one conversation turn.
   */
  private function buildTurn(
    object $turn,
    int $number,
    int $selected_turn_id,
    array $turn_numbers,
  ): array {
    $studio_settings = $this->studioConfigFactory->get('ai_image_studio.settings');
    $prompt = (string) $turn->get('prompt')->value;
    $published_media = !$turn->get('media_id')->isEmpty()
      ? $turn->get('media_id')->entity
      : NULL;
    $heading = $this->t('Version @number · @prompt', [
      '@number' => $number,
      '@prompt' => $this->promptSummary($prompt),
    ]);
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => array_filter([
          'ai-image-studio-turn',
          (int) $turn->id() === $selected_turn_id
            ? 'is-refinement-source'
            : NULL,
        ]),
        'data-ai-image-studio-turn' => (string) $turn->id(),
        'data-ai-image-studio-source-title' => (string) $heading,
        'id' => 'ai-image-studio-turn-' . $turn->id(),
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-image-studio-turn__header']],
        'top' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-image-studio-turn__top']],
          'heading' => [
            '#markup' => '<h3>' . $heading . '</h3>',
          ],
          'result' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-image-studio-turn__result']],
            'media_status' => $published_media instanceof MediaInterface
              ? [
                '#markup' => '<span class="ai-image-studio-media-status">'
                . $this->t('Saved to Media') . '</span>',
              ]
              : [],
            'status' => $this->buildStatus($turn),
            'cost' => $studio_settings->get('show_costs') !== FALSE
              ? $this->buildCost($turn)
              : [],
          ],
        ],
        'summary' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-image-studio-turn__summary']],
          'feedback' => $studio_settings->get('show_request_metadata') !== FALSE
            ? $this->buildRequestFeedback($turn, $turn_numbers)
            : [],
        ],
      ],
      'prompt' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Prompt'),
        '#items' => [['#plain_text' => $prompt]],
      ],
    ];
    $settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
    $build['#attributes'] += [
      'data-ai-image-studio-model' => $this->turnModelOption($turn),
      'data-ai-image-studio-aspect-ratio' => (string) ($settings['aspect_ratio'] ?? ''),
      'data-ai-image-studio-resolution' => (string) ($settings['resolution'] ?? ''),
      'data-ai-image-studio-quality' => (string) ($settings['quality'] ?? ''),
      'data-ai-image-studio-variations' => (string) ($settings['variations'] ?? '1'),
      'data-ai-image-studio-duration' => (string) ($settings['duration'] ?? ''),
      'data-ai-image-studio-transparent-background' => !empty($settings['transparent_background']) ? '1' : '0',
      'data-ai-image-studio-file-type' => (string) ($settings['file_type'] ?? 'png'),
    ];
    if ($settings) {
      $build['settings'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-image-studio-meta']],
        'value' => [
          '#plain_text' => $this->t('Aspect ratio: @ratio · Resolution: @resolution · File type: @file_type@transparent', [
            '@ratio' => $settings['aspect_ratio'] ?? $this->t('Automatic'),
            '@resolution' => strtoupper((string) ($settings['resolution'] ?? '1k')),
            '@file_type' => strtoupper((string) ($settings['file_type'] ?? 'png')),
            '@transparent' => !empty($settings['transparent_background'])
              ? ' · ' . $this->t('Transparent background requested')
              : '',
          ]),
        ],
      ];
    }

    $is_video = !$turn->get('video')->isEmpty();
    if ($turn->get('status')->value === 'completed'
      && (!$turn->get('image')->isEmpty() || $is_video)) {
      if ($is_video) {
        $file = $turn->get('video')->entity;
        if ($file instanceof FileInterface) {
          $build['video'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-image-studio-turn__image-wrapper']],
            'asset' => [
              '#type' => 'html_tag',
              '#tag' => 'video',
              '#attributes' => [
                'class' => ['ai-image-studio-turn__video'],
                'controls' => 'controls',
                'preload' => 'metadata',
                'src' => $file->createFileUrl(),
              ],
            ],
            'badge' => !empty($settings['show_ai_badge']) ? [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => Html::escape((string) ($settings['ai_badge_text'] ?? $this->t('AI Image'))),
              '#attributes' => ['class' => ['ai-image-studio-ai-badge']],
            ] : [],
          ];
        }
      }
      else {
        $file = $turn->get('image')->entity;
        if ($file instanceof FileInterface) {
          $build['image'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-image-studio-turn__image-wrapper']],
            'asset' => [
              '#theme' => 'image',
              '#uri' => $file->getFileUri(),
              '#alt' => (string) $turn->get('prompt')->value,
              '#title' => $this->t('Generated version @number', ['@number' => $number]),
              '#attributes' => ['class' => ['ai-image-studio-turn__image']],
            ],
            'badge' => !empty($settings['show_ai_badge']) ? [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => Html::escape((string) ($settings['ai_badge_text'] ?? $this->t('AI Image'))),
              '#attributes' => ['class' => ['ai-image-studio-ai-badge']],
            ] : [],
          ];
        }
      }
      if (!$is_video) {
        $build['source_choice'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-image-studio-source-action']],
          'choice' => [
            '#type' => 'radio',
            '#title' => $this->t('Use as refinement source'),
            '#return_value' => (string) $turn->id(),
            '#default_value' => (string) $selected_turn_id,
            '#parents' => ['source_turn_id'],
            '#attributes' => [
              'class' => ['ai-image-studio-source-choice'],
            ],
          ],
          'selected' => [
            '#markup' => '<span class="ai-image-studio-source-badge">'
            . $this->t('Selected source') . '</span>',
          ],
        ];
      }
      if ($published_media instanceof MediaInterface) {
        $build['published'] = $published_media
          ->toLink($this->t('View published Media'))
          ->toRenderable();
      }
      elseif ($turn->get('session_id')->entity?->access('update')
        && $this->currentUserProxy->hasPermission(
        $is_video
          ? 'publish ai image studio video'
          : 'publish ai image studio image',
      )) {
        $can_render_badge = $this->generator->canRenderBadge($is_video);
        $build['publish'] = [
          '#type' => 'details',
          '#tree' => TRUE,
          '#title' => $this->t('Publish to Media'),
          'name' => [
            '#type' => 'textfield',
            '#title' => $this->t('Media name'),
            '#default_value' => $this->defaultMediaName($turn, $number),
            '#maxlength' => 255,
            '#required' => TRUE,
          ],
          'alt' => $is_video ? [] : [
            '#type' => 'textfield',
            '#title' => $this->t('Alternative text'),
            '#default_value' => $this->suggestedAltText($turn),
            '#maxlength' => 512,
            '#description' => $studio_settings->get('require_image_alt') === FALSE
              ? $this->t('Optional. Suggested from the session title and prompt.')
              : $this->t('Suggested from the session title and this version’s prompt. Review it for accuracy before publishing.'),
          ],
          'render_badge' => [
            '#type' => 'checkbox',
            '#title' => $this->t('Render the badge into the saved Media file'),
            '#default_value' => $can_render_badge
            && !empty($settings['show_ai_badge']),
            '#disabled' => !$can_render_badge,
            '#description' => $can_render_badge
              ? $this->t('Creates a separate Media file with “@badge” permanently embedded. The original Studio result is preserved.', [
                '@badge' => $settings['ai_badge_text'] ?? $this->t('AI Image'),
              ])
              : ($is_video
                ? $this->t('Badge rendering is unavailable because PHP GD or FFmpeg is not available to the web server. The original video can still be published.')
                : $this->t('Badge rendering is unavailable because PHP GD is not available to the web server. The original image can still be published.')),
          ],
          'submit' => [
            '#type' => 'submit',
            '#value' => $this->t('Publish this version'),
            '#name' => 'publish_turn_' . $turn->id(),
            '#studio_action' => 'publish',
            '#turn_id' => $turn->id(),
            '#limit_validation_errors' => [
              ['history', 'turn_' . $turn->id(), 'publish', 'name'],
              ['history', 'turn_' . $turn->id(), 'publish', 'alt'],
              ['history', 'turn_' . $turn->id(), 'publish', 'render_badge'],
            ],
          ],
        ];
      }
    }
    elseif ($turn->get('status')->value === 'failed') {
      $build['error'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', 'messages--error']],
        'message' => ['#plain_text' => (string) $turn->get('error_message')->value],
      ];
    }
    return $build;
  }

  /**
   * Creates a short, single-line card title from a prompt.
   */
  private function promptSummary(string $prompt): string {
    $prompt = trim((string) preg_replace('/\s+/u', ' ', $prompt));
    if ($prompt === '') {
      return (string) $this->t('Untitled prompt');
    }

    $sentences = preg_split('/(?<=[.!?])\s+/u', $prompt, 2);
    $summary = rtrim((string) ($sentences[0] ?? $prompt), " \t\n\r\0\x0B.!?");
    if (mb_strlen($summary) > 64) {
      $summary = rtrim(mb_substr($summary, 0, 61)) . '…';
    }
    return $summary;
  }

  /**
   * Reassembles the Drupal AI provider/model option stored on a turn.
   */
  private function turnModelOption(object $turn): string {
    return (string) $turn->get('provider_id')->value
      . '__'
      . (string) $turn->get('model_id')->value;
  }

  /**
   * Creates a concise and unique default Media name.
   */
  private function defaultMediaName(object $turn, int $number): string {
    $session = $turn->get('session_id')->entity;
    $fallback = !$turn->get('video')->isEmpty()
      ? $this->t('AI video')
      : $this->t('AI image');
    $session_title = $session ? (string) $session->label() : (string) $fallback;
    return mb_substr((string) $this->t('@session — Version @number', [
      '@session' => $session_title,
      '@number' => $number,
    ]), 0, 255);
  }

  /**
   * Creates an editable alternative-text suggestion for a generated image.
   */
  private function suggestedAltText(object $turn): string {
    $session = $turn->get('session_id')->entity;
    $session_title = $session ? trim((string) $session->label()) : '';
    $prompt = $this->promptSummary((string) $turn->get('prompt')->value);
    $suggestion = $session_title === ''
      ? $prompt
      : (string) $this->t('@session: @prompt', [
        '@session' => $session_title,
        '@prompt' => $prompt,
      ]);
    return mb_substr($suggestion, 0, 512);
  }

  /**
   * Builds readable request feedback for a generated version.
   */
  private function buildRequestFeedback(object $turn, array $turn_numbers): array {
    $settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
    $operation = match ((string) $turn->get('operation')->value) {
      'image_to_image' => $this->t('Image edit'),
      'text_to_video' => $this->t('Video generation'),
      'image_to_video' => $this->t('Image animation'),
      default => $this->t('Image generation'),
    };
    $ratio = (string) ($settings['aspect_ratio'] ?? 'auto');
    $ratio = $ratio === 'auto' ? $this->t('Automatic ratio') : $ratio;
    $resolution = strtoupper((string) ($settings['resolution'] ?? '1k'));
    $detail = ($settings['resolution'] ?? '1k') === '2k'
      ? $this->t('High detail')
      : $this->t('Standard detail');
    $output_summary = in_array($turn->get('operation')->value, [
      'text_to_video',
      'image_to_video',
    ], TRUE)
      ? $this->t('@ratio · @resolution · @duration seconds', [
        '@ratio' => $ratio,
        '@resolution' => $resolution,
        '@duration' => (int) ($settings['duration'] ?? 5),
      ])
      : $this->t('@ratio · @resolution · @detail · @file_type', [
        '@ratio' => $ratio,
        '@resolution' => $resolution,
        '@detail' => $detail,
        '@file_type' => strtoupper((string) ($settings['file_type'] ?? 'png')),
      ]);
    $duration_ms = (int) ($turn->get('duration_ms')->value ?? 0);
    $duration = $duration_ms > 0
      ? number_format($duration_ms / 1000, 1) . 's'
      : $this->t('Time unavailable');
    $provider_metadata = (array) ($turn->get('provider_metadata')->first()?->getValue() ?? []);
    $requested_model = (string) $turn->get('model_id')->value;
    $actual_model = (string) ($provider_metadata['actual_model'] ?? '');
    $model_summary = $actual_model !== '' && $actual_model !== $requested_model
      ? $this->t('@requested → @actual', [
        '@requested' => $requested_model,
        '@actual' => $actual_model,
      ])
      : $requested_model;
    $feedback = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-image-studio-feedback']],
      'provider' => $this->feedbackItem(
        $this->t('Provider'),
        (string) $turn->get('provider_id')->value,
      ),
      'model' => $this->feedbackItem(
        $this->t('Model'),
        (string) $model_summary,
      ),
      'operation' => $this->feedbackItem($this->t('Request'), (string) $operation),
      'output' => $this->feedbackItem(
        $this->t('Output'),
        (string) $output_summary,
      ),
      'duration' => $this->feedbackItem($this->t('Processing'), (string) $duration),
    ];
    if (isset($provider_metadata['respect_moderation'])) {
      $feedback['moderation'] = $this->feedbackItem(
        $this->t('Moderation'),
        $provider_metadata['respect_moderation']
          ? $this->t('Passed')
          : $this->t('Filtered'),
      );
    }
    if (!empty($provider_metadata['request_id'])) {
      $feedback['request_id'] = $this->feedbackItem(
        $this->t('Provider request'),
        (string) $provider_metadata['request_id'],
      );
    }
    if ((int) ($provider_metadata['output_count'] ?? 1) > 1) {
      $feedback['outputs'] = $this->feedbackItem(
        $this->t('Request outputs'),
        (string) $provider_metadata['output_count'],
      );
    }
    if ((int) $turn->get('attempt_count')->value > 1) {
      $feedback['attempts'] = $this->feedbackItem(
        $this->t('Attempts'),
        (string) $turn->get('attempt_count')->value,
      );
    }
    if ($this->studioConfigFactory->get('ai_image_studio.settings')
      ->get('show_token_usage') !== FALSE) {
      $feedback['tokens'] = $this->feedbackItem(
        $this->t('Tokens'),
        $this->formatTokens($turn),
      );
    }
    $parent_id = (int) ($turn->get('parent_id')->target_id ?? 0);
    if ($parent_id > 0) {
      $feedback['source'] = $this->feedbackItem(
        $this->t('Source image'),
        [
          '#type' => 'link',
          '#title' => $this->t('Version @number', [
            '@number' => $turn_numbers[$parent_id] ?? $parent_id,
          ]),
          '#url' => Url::fromRoute(
            'entity.ai_image_studio_session.canonical',
            [
              'ai_image_studio_session' => (int) $turn->get('session_id')->target_id,
            ],
            ['fragment' => 'ai-image-studio-turn-' . $parent_id],
          ),
        ],
      );
    }
    elseif (in_array($turn->get('operation')->value, [
      'image_to_image',
      'image_to_video',
    ], TRUE)) {
      $feedback['source'] = $this->feedbackItem(
        $this->t('Source image'),
        $this->t('Uploaded image'),
      );
    }
    return $feedback;
  }

  /**
   * Builds the session-wide version and cost report.
   */
  private function buildSessionReport(array $turns, array $turn_numbers): array {
    $rows = [];
    $reported_total = 0.0;
    $estimated_total = 0.0;
    $unavailable_count = 0;

    foreach ($turns as $turn) {
      $turn_id = (int) $turn->id();
      $settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
      $ratio = (string) ($settings['aspect_ratio'] ?? 'auto');
      $ratio = $ratio === 'auto' ? (string) $this->t('Automatic') : $ratio;
      $resolution = strtoupper((string) ($settings['resolution'] ?? '1k'));
      $output = in_array($turn->get('operation')->value, [
        'text_to_video',
        'image_to_video',
      ], TRUE)
        ? $this->t('@ratio · @resolution · @duration seconds', [
          '@ratio' => $ratio,
          '@resolution' => $resolution,
          '@duration' => (int) ($settings['duration'] ?? 5),
        ])
        : $this->t('@ratio · @resolution', [
          '@ratio' => $ratio,
          '@resolution' => $resolution,
        ]);
      $parent_id = (int) ($turn->get('parent_id')->target_id ?? 0);
      $source = $parent_id > 0
        ? $this->t('Version @number', [
          '@number' => $turn_numbers[$parent_id] ?? $parent_id,
        ])
        : (in_array($turn->get('operation')->value, [
          'image_to_image',
          'image_to_video',
        ], TRUE)
          ? $this->t('Uploaded image')
          : $this->t('Text prompt'));
      $cost_source = (string) $turn->get('cost_source')->value;
      $cost = $turn->get('estimated_cost')->isEmpty()
        ? NULL
        : (float) $turn->get('estimated_cost')->value;
      if ($cost !== NULL && $cost_source === 'reported') {
        $reported_total += $cost;
      }
      elseif ($cost !== NULL && $cost_source === 'estimated') {
        $estimated_total += $cost;
      }
      else {
        $unavailable_count++;
      }
      $cost_label = match ($cost_source) {
        'reported' => $this->t('Reported'),
        'estimated' => $this->t('Estimated'),
        default => $this->t('Unavailable'),
      };

      $rows[] = [
        'version' => [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Version @number', [
              '@number' => $turn_numbers[$turn_id] ?? $turn_id,
            ]),
            '#url' => Url::fromRoute(
              'entity.ai_image_studio_session.canonical',
              [
                'ai_image_studio_session' => (int) $turn->get('session_id')->target_id,
              ],
              ['fragment' => 'ai-image-studio-turn-' . $turn_id],
            ),
          ],
        ],
        'prompt' => $this->promptSummary((string) $turn->get('prompt')->value),
        'provider' => $this->t('@provider · @model', [
          '@provider' => (string) $turn->get('provider_id')->value,
          '@model' => (string) $turn->get('model_id')->value,
        ]),
        'request' => match ((string) $turn->get('operation')->value) {
          'image_to_image' => $this->t('Image edit'),
          'text_to_video' => $this->t('Video generation'),
          'image_to_video' => $this->t('Image animation'),
          default => $this->t('Image generation'),
        },
        'source' => $source,
        'output' => $output,
        'processing' => $this->formatDuration($turn),
        'tokens' => $this->formatTokens($turn),
        'cost' => $cost === NULL
          ? $cost_label
          : $this->t('@source · $@cost USD', [
            '@source' => $cost_label,
            '@cost' => number_format($cost, 6, '.', ''),
          ]),
      ];
    }

    $total = $reported_total + $estimated_total;
    $warning = (float) ($this->studioConfigFactory
      ->get('ai_image_studio.settings')
      ->get('session_cost_warning') ?? 0);
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => array_filter([
          'ai-image-studio-report',
          $warning > 0 && $total >= $warning ? 'is-cost-warning' : NULL,
        ]),
      ],
      'heading' => [
        '#markup' => '<h2>' . $this->t('Session report') . '</h2>',
      ],
      'description' => [
        '#markup' => '<p class="description">'
        . $this->t('All generated versions in this session. Cost totals combine provider-reported charges with estimates where reported cost was unavailable.')
        . '</p>',
      ],
      'table_wrapper' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-image-studio-report__table-wrapper']],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Version'),
            $this->t('Prompt'),
            $this->t('Provider and model'),
            $this->t('Request'),
            $this->t('Source'),
            $this->t('Output'),
            $this->t('Processing'),
            $this->t('Tokens'),
            $this->t('Cost'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('No versions have been generated.'),
          '#attributes' => ['class' => ['ai-image-studio-report__table']],
        ],
      ],
      'totals' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-image-studio-report__totals']],
        'total' => [
          '#markup' => '<span>' . $this->t('Aggregate cost') . '</span>'
          . '<strong>$' . number_format($total, 6, '.', '') . ' USD</strong>',
        ],
        'breakdown' => [
          '#markup' => '<span>'
          . $this->t('Reported: $@reported USD · Estimated: $@estimated USD · Unavailable: @count', [
            '@reported' => number_format($reported_total, 6, '.', ''),
            '@estimated' => number_format($estimated_total, 6, '.', ''),
            '@count' => $unavailable_count,
          ])
          . '</span>',
        ],
        'warning' => $warning > 0 && $total >= $warning
          ? [
            '#markup' => '<strong class="ai-image-studio-report__warning">'
            . $this->t('This session has reached the configured $@amount USD warning threshold.', [
              '@amount' => number_format($warning, 2, '.', ''),
            ])
            . '</strong>',
          ]
          : [],
      ],
    ];
  }

  /**
   * Builds the compact generation status pill.
   */
  private function buildStatus(object $turn): array {
    $status = (string) $turn->get('status')->value;
    $label = match ($status) {
      'pending' => $this->t('Pending'),
      'queued' => $this->t('Queued'),
      'processing' => $this->t('Processing'),
      'completed' => $this->t('Completed'),
      'failed' => $this->t('Failed'),
      'expired' => $this->t('Expired'),
      'cancelled' => $this->t('Cancelled'),
      default => $this->t('Unknown'),
    };
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $label,
      '#attributes' => [
        'class' => [
          'ai-image-studio-status',
          'is-' . $status,
        ],
      ],
    ];
  }

  /**
   * Builds the prominent cost summary.
   */
  private function buildCost(object $turn): array {
    $source = (string) $turn->get('cost_source')->value;
    $label = match ($source) {
      'reported' => $this->t('Reported cost'),
      'estimated' => $this->t('Estimated cost'),
      default => $this->t('Cost'),
    };
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-image-studio-cost']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label,
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => Html::escape($this->formatCost($turn)),
      ],
    ];
  }

  /**
   * Builds one labeled request-feedback value.
   */
  private function feedbackItem(
    string|\Stringable $label,
    string|\Stringable|array $value,
    array $classes = [],
  ): array {
    if (is_array($value)) {
      $value['#attributes']['class'][] = 'ai-image-studio-feedback__value';
      $value_element = $value;
    }
    else {
      $value_element = [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => Html::escape((string) $value),
        '#attributes' => ['class' => ['ai-image-studio-feedback__value']],
      ];
    }
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => array_merge(['ai-image-studio-feedback__item'], $classes),
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => Html::escape((string) $label),
        '#attributes' => ['class' => ['ai-image-studio-feedback__label']],
      ],
      'value' => $value_element,
    ];
  }

  /**
   * Reports whether a session has queued or processing turns.
   */
  private function sessionHasActiveGeneration(int $session_id): bool {
    $count = $this->entityTypeManager
      ->getStorage('ai_image_studio_turn')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $session_id)
      ->condition('status', ['queued', 'processing'], 'IN')
      ->count()
      ->execute();
    return (int) $count > 0;
  }

  /**
   * Formats provider-reported or estimated USD cost.
   */
  private function formatCost(object $turn): string {
    if ($turn->get('estimated_cost')->isEmpty()) {
      return (string) $this->t('Cost unavailable');
    }
    return (string) $this->t('$@cost USD', [
      '@cost' => number_format((float) $turn->get('estimated_cost')->value, 6, '.', ''),
    ]);
  }

  /**
   * Formats token usage or explains image-based billing.
   */
  private function formatTokens(object $turn): string {
    $tokens = (array) ($turn->get('token_usage')->first()?->getValue() ?? []);
    if ($tokens === []) {
      return (string) $this->t('Not reported · media-billed');
    }

    $parts = [];
    foreach ([
      'input' => $this->t('in'),
      'output' => $this->t('out'),
      'cached' => $this->t('cached'),
      'reasoning' => $this->t('reasoning'),
    ] as $key => $label) {
      if (isset($tokens[$key])) {
        $parts[] = number_format((int) $tokens[$key]) . ' ' . $label;
      }
    }
    if ($parts === [] && isset($tokens['total'])) {
      $parts[] = number_format((int) $tokens['total']) . ' ' . $this->t('total');
    }
    return implode(' · ', $parts);
  }

  /**
   * Formats a turn's processing duration.
   */
  private function formatDuration(object $turn): string {
    $duration_ms = (int) ($turn->get('duration_ms')->value ?? 0);
    return $duration_ms > 0
      ? number_format($duration_ms / 1000, 1) . 's'
      : (string) $this->t('Unavailable');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#studio_action'] ?? '') === 'publish') {
      $turn_id = (int) ($trigger['#turn_id'] ?? 0);
      $turn = $this->entityTypeManager
        ->getStorage('ai_image_studio_turn')
        ->load($turn_id);
      $values = $form_state->getValue([
        'history',
        'turn_' . $turn_id,
        'publish',
      ]);
      $require_alt = $this->studioConfigFactory
        ->get('ai_image_studio.settings')
        ->get('require_image_alt') !== FALSE;
      if ($require_alt && $turn !== NULL && $turn->get('video')->isEmpty()
        && trim((string) ($values['alt'] ?? '')) === '') {
        $form_state->setErrorByName(
          'history][turn_' . $turn_id . '][publish][alt',
          $this->t('Alternative text is required when publishing an image to Media.'),
        );
      }
      return;
    }

    if (($trigger['#studio_action'] ?? '') !== 'generate') {
      return;
    }

    if (trim((string) $form_state->getValue('prompt')) === '') {
      $form_state->setErrorByName(
        'prompt',
        $this->t('Enter a prompt to generate a result.'),
      );
    }

    $session_id = $form_state->get('session_id');
    if ($session_id !== NULL) {
      $session = $this->entityTypeManager
        ->getStorage('ai_image_studio_session')
        ->load($session_id);
      if ($session === NULL || !$session->access('update')) {
        $form_state->setErrorByName(
          'prompt',
          $this->t('You are not allowed to modify this session.'),
        );
        return;
      }
      $max_turns = (int) ($this->studioConfigFactory
        ->get('ai_image_studio.settings')
        ->get('max_turns') ?: 25);
      $requested_turns = (string) ($form_state->getValue('output_type') ?: 'image') === 'image'
        ? max(1, (int) ($form_state->getValue('variations') ?: 1))
        : 1;
      if ($this->countTurns((int) $session_id) + $requested_turns > $max_turns) {
        $form_state->setErrorByName(
          'variations',
          $this->t('This request would exceed the session limit of @count turns.', [
            '@count' => $max_turns,
          ]),
        );
        return;
      }
      $source_turn_id = (int) $form_state->getValue('source_turn_id');
      if ($this->completedTurnFromSession((int) $session_id, $source_turn_id) === NULL) {
        $form_state->setErrorByName(
          'source_turn_id',
          $this->t('Select a completed image from this session to refine.'),
        );
      }
    }
    $output_type = (string) ($form_state->getValue('output_type') ?: 'image');
    if ($session_id === NULL && $output_type === 'image') {
      $max_turns = (int) ($this->studioConfigFactory
        ->get('ai_image_studio.settings')
        ->get('max_turns') ?: 25);
      if ((int) ($form_state->getValue('variations') ?: 1) > $max_turns) {
        $form_state->setErrorByName(
          'variations',
          $this->t('This request would exceed the session limit of @count turns.', [
            '@count' => $max_turns,
          ]),
        );
      }
    }
    $start_mode = (string) $form_state->getValue('start_mode');
    if ($session_id === NULL && in_array($start_mode, ['upload', 'media'], TRUE)) {
      $files = array_filter((array) $form_state->getValue('source_image'));
      if ($start_mode === 'upload' && !$files) {
        $form_state->setErrorByName('source_image', $this->t('Upload a starting image.'));
      }
      if ($start_mode === 'media' && !$form_state->getValue('source_media')) {
        $form_state->setErrorByName('source_media', $this->t('Select a Media image.'));
      }
      elseif ($start_mode === 'media') {
        $media = $this->entityTypeManager->getStorage('media')->load(
          $form_state->getValue('source_media'),
        );
        $source_field = (string) ($this->studioConfigFactory
          ->get('ai_image_studio.settings')
          ->get('media_source_field') ?: 'field_media_image');
        if (!$media instanceof MediaInterface
          || !$media->hasField($source_field)
          || !$media->get($source_field)->entity instanceof FileInterface) {
          $form_state->setErrorByName(
            'source_media',
            $this->t('Select Media that contains a valid image.'),
          );
        }
      }
      $model_key = $output_type === 'video'
        ? 'image_video_model'
        : 'image_model';
      if (!$form_state->getValue($model_key)) {
        $form_state->setErrorByName(
          $model_key,
          $this->t('Select a configured provider and model.'),
        );
      }
    }
    elseif ($session_id === NULL) {
      $model_key = $output_type === 'video'
        ? 'text_video_model'
        : 'text_model';
      if (!$form_state->getValue($model_key)) {
        $form_state->setErrorByName(
          $model_key,
          $this->t('Select a configured provider and model.'),
        );
      }
    }
    elseif (!$form_state->getValue(
      $output_type === 'video' ? 'video_model' : 'model',
    )) {
      $form_state->setErrorByName(
        $output_type === 'video' ? 'video_model' : 'model',
        $this->t('Select a configured provider and model.'),
      );
    }

    if (!$form_state->hasAnyErrors()) {
      $has_source = $session_id !== NULL
        || in_array($start_mode, ['upload', 'media'], TRUE);
      $operation = match ([$output_type, $has_source]) {
        ['video', TRUE] => 'image_to_video',
        ['video', FALSE] => 'text_to_video',
        ['image', TRUE] => 'image_to_image',
        default => 'text_to_image',
      };
      $model_key = $session_id !== NULL
        ? ($output_type === 'video' ? 'video_model' : 'model')
        : ($has_source
          ? ($output_type === 'video' ? 'image_video_model' : 'image_model')
          : ($output_type === 'video' ? 'text_video_model' : 'text_model'));
      $model = (string) $form_state->getValue($model_key);
      if (!isset($this->generator->getModelOptions($operation)[$model])) {
        $form_state->setErrorByName(
          $model_key,
          $this->t('The selected provider and model is not available for this request type.'),
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#studio_action'] ?? '') === 'publish') {
      $this->publishTurn($form_state, (int) $trigger['#turn_id']);
      return;
    }

    $session_id = $form_state->get('session_id');
    $storage = $this->entityTypeManager->getStorage('ai_image_studio_session');
    if ($session_id === NULL) {
      $session = $storage->create([
        'title' => trim((string) $form_state->getValue('title')),
        'uid' => $this->currentUserProxy->id(),
        'status' => 'active',
      ]);
      $session->save();
    }
    else {
      $session = $storage->load($session_id);
      if ($session === NULL || !$session->access('update')) {
        $this->messenger()->addError($this->t('The image session is unavailable.'));
        $form_state->setRedirect('entity.ai_image_studio_session.collection');
        return;
      }
    }

    $parent = $session_id === NULL
      ? NULL
      : $this->completedTurnFromSession(
        (int) $session->id(),
        (int) $form_state->getValue('source_turn_id'),
      );
    $source = NULL;
    $output_type = (string) ($form_state->getValue('output_type') ?: 'image');
    $model = $session_id === NULL
      ? (string) $form_state->getValue(
        $output_type === 'video' ? 'image_video_model' : 'image_model',
      )
      : (string) $form_state->getValue(
        $output_type === 'video' ? 'video_model' : 'model',
      );
    if ($session_id === NULL) {
      if ($form_state->getValue('start_mode') === 'upload') {
        $file_ids = array_filter((array) $form_state->getValue('source_image'));
        $source = $this->entityTypeManager->getStorage('file')->load(reset($file_ids));
      }
      elseif ($form_state->getValue('start_mode') === 'media') {
        $media = $this->entityTypeManager->getStorage('media')->load(
          $form_state->getValue('source_media'),
        );
        $source_field = (string) ($this->studioConfigFactory
          ->get('ai_image_studio.settings')
          ->get('media_source_field') ?: 'field_media_image');
        if ($media instanceof MediaInterface && $media->hasField($source_field)) {
          $source = $media->get($source_field)->entity;
        }
      }
      else {
        $model = (string) $form_state->getValue(
          $output_type === 'video' ? 'text_video_model' : 'text_model',
        );
      }
    }

    $turn = $this->generator->generate(
      $session,
      trim((string) $form_state->getValue('prompt')),
      $model,
      $parent,
      $source instanceof FileInterface ? $source : NULL,
      [
        'aspect_ratio' => $output_type === 'video'
          ? $form_state->getValue('video_aspect_ratio')
          : $form_state->getValue('aspect_ratio'),
        'resolution' => $output_type === 'video'
          ? $form_state->getValue('video_resolution')
          : $form_state->getValue('resolution'),
        'quality' => $form_state->getValue('quality') ?: 'medium',
        'variations' => $output_type === 'image'
          ? (int) ($form_state->getValue('variations') ?: 1)
          : 1,
        'duration' => $form_state->getValue('duration'),
        'prompt' => trim((string) $form_state->getValue('prompt')),
        'transparent_background' => $form_state->getValue('transparent_background'),
        'file_type' => $form_state->getValue('file_type') ?: 'png',
        'show_ai_badge' => (bool) $form_state->getValue(
          $output_type === 'video' ? 'video_show_ai_badge' : 'show_ai_badge',
        ),
        'ai_badge_text' => trim((string) $form_state->getValue(
          $output_type === 'video' ? 'video_ai_badge_text' : 'ai_badge_text',
        )) ?: 'AI Image',
      ],
      $output_type,
    );
    if ($turn->get('status')->value === 'completed') {
      $this->messenger()->addStatus($output_type === 'video'
        ? $this->t('The video was generated successfully.')
        : $this->t('The image was generated successfully.'));
      $warning = (float) ($this->studioConfigFactory
        ->get('ai_image_studio.settings')
        ->get('request_cost_warning') ?? 0);
      $cost = $turn->get('estimated_cost')->isEmpty()
        ? NULL
        : (float) $turn->get('estimated_cost')->value;
      if ($warning > 0 && $cost !== NULL && $cost >= $warning) {
        $this->messenger()->addWarning($this->t(
          'This request cost $@cost USD, meeting the configured $@threshold warning threshold.',
          [
            '@cost' => number_format($cost, 6, '.', ''),
            '@threshold' => number_format($warning, 2, '.', ''),
          ],
        ));
      }
    }
    elseif ($turn->get('status')->value === 'queued') {
      $this->messenger()->addStatus($this->t(
        'The video was queued. This page refreshes while a queue worker processes it.',
      ));
    }
    else {
      $this->messenger()->addError($this->t('Generation failed: @message', [
        '@message' => $turn->get('error_message')->value,
      ]));
    }
    $form_state->setRedirect('entity.ai_image_studio_session.canonical', [
      'ai_image_studio_session' => $session->id(),
    ]);
  }

  /**
   * Publishes the selected turn.
   */
  private function publishTurn(FormStateInterface $form_state, int $turn_id): void {
    $turn = $this->entityTypeManager->getStorage('ai_image_studio_turn')->load($turn_id);
    $session_id = (int) $form_state->get('session_id');
    if ($turn === NULL || (int) $turn->get('session_id')->target_id !== $session_id) {
      $this->messenger()->addError($this->t('The selected image version is unavailable.'));
      $form_state->setRedirect('entity.ai_image_studio_session.canonical', [
        'ai_image_studio_session' => $session_id,
      ]);
      return;
    }
    $session = $this->entityTypeManager->getStorage('ai_image_studio_session')->load($session_id);
    $is_video = !$turn->get('video')->isEmpty();
    $permission = $is_video
      ? 'publish ai image studio video'
      : 'publish ai image studio image';
    if ($session === NULL || !$session->access('update')
      || !$this->currentUserProxy->hasPermission($permission)) {
      $this->messenger()->addError($this->t('You are not allowed to publish this result.'));
      $form_state->setRedirect('entity.ai_image_studio_session.canonical', [
        'ai_image_studio_session' => $session_id,
      ]);
      return;
    }

    $values = $form_state->getValue([
      'history',
      'turn_' . $turn_id,
      'publish',
    ]);
    try {
      $media = $this->generator->publish(
        $turn,
        trim((string) ($values['name'] ?? '')),
        trim((string) ($values['alt'] ?? '')),
        !empty($values['render_badge']),
      );
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('The result could not be published: @message', [
        '@message' => $exception->getMessage(),
      ]));
      $form_state->setRedirect('entity.ai_image_studio_session.canonical', [
        'ai_image_studio_session' => $session_id,
      ]);
      return;
    }
    $this->messenger()->addStatus($this->t('Published as Media “@name”.', [
      '@name' => $media->label(),
    ]));
    $form_state->setRedirect('entity.ai_image_studio_session.canonical', [
      'ai_image_studio_session' => $session_id,
    ]);
  }

  /**
   * Loads turns in conversation order.
   */
  private function loadTurns(int $session_id): array {
    $storage = $this->entityTypeManager->getStorage('ai_image_studio_turn');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $session_id)
      ->sort('created', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Counts turns belonging to a session without applying entity query access.
   */
  private function countTurns(int $session_id): int {
    return (int) $this->entityTypeManager
      ->getStorage('ai_image_studio_turn')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $session_id)
      ->count()
      ->execute();
  }

  /**
   * Finds the last successful image to refine.
   */
  private function latestCompletedTurn(int $session_id): ?object {
    $storage = $this->entityTypeManager->getStorage('ai_image_studio_turn');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $session_id)
      ->condition('status', 'completed')
      ->condition('image.target_id', NULL, 'IS NOT NULL')
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    return $ids ? $storage->load(reset($ids)) : NULL;
  }

  /**
   * Loads a completed image turn that belongs to the requested session.
   */
  private function completedTurnFromSession(
    int $session_id,
    int $turn_id,
  ): ?object {
    if ($turn_id <= 0) {
      return NULL;
    }

    $turn = $this->entityTypeManager
      ->getStorage('ai_image_studio_turn')
      ->load($turn_id);
    if ($turn === NULL
      || (int) $turn->get('session_id')->target_id !== $session_id
      || $turn->get('status')->value !== 'completed'
      || $turn->get('image')->isEmpty()) {
      return NULL;
    }
    return $turn;
  }

}
