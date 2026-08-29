<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Form;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_image_studio\Service\PromptResolver;
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
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ImageGenerator $generator,
    protected ConfigFactoryInterface $studioConfigFactory,
    protected AccountProxyInterface $currentUserProxy,
    protected PromptResolver $promptResolver,
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
      $container->get('ai_image_studio.prompt_resolver'),
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
    $max_upload_bytes = (int) ($settings->get('max_source_image_size_mb') ?: 20)
      * 1024 * 1024;
    $default_output_type = (string) ($settings->get('default_output_type') ?: 'image');

    $form['#attached']['library'][] = 'ai_image_studio/studio';
    $variation_limits = [];
    foreach (['text_to_image', 'image_to_image'] as $operation) {
      $model_options = $this->generator->getModelOptions($operation);
      array_walk_recursive(
        $model_options,
        function (mixed $label, string|int $model) use (&$variation_limits): void {
          if (is_string($model) && str_contains($model, '__')) {
            $variation_limits[$model] = $this->generator->getMaxVariations($model);
          }
        },
      );
    }
    $form['#attached']['drupalSettings']['aiImageStudio']['variationLimits'] = $variation_limits;
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
        '#wrapper_attributes' => [
          'class' => ['ai-image-studio-choice-group'],
          'data-ai-image-studio-choice-group' => '',
        ],
      ];
      $form['output_type'] = [
        '#type' => 'radios',
        '#title' => $this->t('Create'),
        '#options' => [
          'image' => $this->t('Image'),
          'video' => $this->t('Video'),
        ],
        '#default_value' => $default_output_type,
        '#description' => $this->t('Video requests are queued. You can leave this page while generation continues.'),
        '#wrapper_attributes' => [
          'class' => ['ai-image-studio-choice-group'],
          'data-ai-image-studio-choice-group' => '',
        ],
      ];
      $form['source_image'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Starting image'),
        '#multiple' => TRUE,
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
        '#wrapper_attributes' => [
          'data-ai-image-studio-conditional' => '',
          'data-ai-image-studio-start-mode' => 'upload',
        ],
      ];
      $form['video_mode'] = [
        '#type' => 'radios',
        '#title' => $this->t('Video mode'),
        '#options' => [
          'text' => $this->t('Text to video'),
          'animate' => $this->t('Animate starting image'),
          'reference' => $this->t('Generate from references'),
        ],
        '#default_value' => 'text',
        '#description' => $this->t('Reference images guide subjects and style; they do not become the first frame.'),
        '#states' => [
          'visible' => [':input[name="output_type"]' => ['value' => 'video']],
        ],
        '#wrapper_attributes' => [
          'data-ai-image-studio-conditional' => '',
          'data-ai-image-studio-output-type' => 'video',
        ],
      ];
      $form['source_media'] = [
        '#type' => 'ai_image_studio_media_library',
        '#title' => $this->t('Media image'),
        '#allowed_bundles' => [
          (string) ($settings->get('media_bundle') ?: 'image'),
        ],
        '#cardinality' => 1,
        '#default_value' => $form_state->getValue('source_media') ?: NULL,
        '#description' => $this->t('Choose an existing image or add one through the Media Library.'),
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => ['value' => 'media'],
          ],
          'required' => [
            ':input[name="start_mode"]' => ['value' => 'media'],
          ],
        ],
        '#wrapper_attributes' => [
          'data-ai-image-studio-conditional' => '',
          'data-ai-image-studio-start-mode' => 'media',
        ],
        '#attributes' => [
          'data-ai-image-studio-conditional' => '',
          'data-ai-image-studio-start-mode' => 'media',
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
        '#wrapper_attributes' => [
          'data-ai-image-studio-conditional' => '',
          'data-ai-image-studio-start-mode' => 'prompt',
          'data-ai-image-studio-output-type' => 'image',
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
        '#wrapper_attributes' => [
          'data-ai-image-studio-conditional' => '',
          'data-ai-image-studio-start-mode' => 'source',
          'data-ai-image-studio-output-type' => 'image',
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
        '#wrapper_attributes' => [
          'data-ai-image-studio-conditional' => '',
          'data-ai-image-studio-start-mode' => 'prompt',
          'data-ai-image-studio-output-type' => 'video',
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
        '#wrapper_attributes' => [
          'data-ai-image-studio-conditional' => '',
          'data-ai-image-studio-start-mode' => 'source',
          'data-ai-image-studio-output-type' => 'video',
        ],
      ];
      $form['prompt_start'] = $this->promptStartElement(
        $this->t('Describe the image to create or how to transform the upload.'),
      );
      $form['prompt'] = $this->promptElement();
      $form['generation_controls'] = $this->generationControls();
      $form['video_controls'] = $this->videoControls();
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['generate'] = [
        '#type' => 'submit',
        '#value' => $default_output_type === 'video'
          ? $this->t('Generate Video')
          : $this->t('Generate Image'),
        '#button_type' => 'primary',
        '#studio_action' => 'generate',
        '#attributes' => [
          'data-ai-image-studio-generate' => '',
          'data-generate-image-label' => $this->t('Generate Image'),
          'data-generate-video-label' => $this->t('Generate Video'),
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
    $regenerate_video = $this->completedVideoTurnFromSession(
      (int) $session->id(),
      (int) $this->getRequest()->query->get('regenerate_video'),
    );
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
    if ($regenerate_video !== NULL && $session->access('update')) {
      $form['video_regeneration'] = $this->buildVideoRegenerationForm(
        $regenerate_video,
        $turn_numbers[(int) $regenerate_video->id()] ?? 1,
      );
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
        $source_url = Url::fromRoute('<none>', [], [
          'fragment' => 'ai-image-studio-turn-' . $selected_source->id(),
        ]);
        $form['refine']['source'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['ai-image-studio-source-preview'],
            'data-ai-image-studio-source-preview' => '',
          ],
          '#states' => [
            'invisible' => [
              ':input[name="output_type"]' => ['value' => 'prompt'],
            ],
          ],
          'image' => $source_file instanceof FileInterface
            ? [
              '#type' => 'link',
              '#title' => [
                '#theme' => 'image',
                '#uri' => $source_file->getFileUri(),
                '#alt' => $source_prompt,
                '#attributes' => [
                  'data-ai-image-studio-source-preview-image' => '',
                ],
              ],
              '#url' => $source_url,
              '#attributes' => [
                'class' => ['ai-image-studio-source-preview__link'],
                'data-ai-image-studio-source-preview-link' => '',
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
              '#type' => 'link',
              '#title' => [
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
              '#url' => $source_url,
              '#attributes' => [
                'class' => ['ai-image-studio-source-preview__link'],
                'data-ai-image-studio-source-preview-link' => '',
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
          'prompt' => $this->t('New asset from prompt'),
        ],
        '#default_value' => $default_output_type,
      ];
      $form['refine']['video_mode'] = [
        '#type' => 'radios',
        '#title' => $this->t('Video mode'),
        '#options' => [
          'animate' => $this->t('Animate starting image'),
          'reference' => $this->t('Generate from references'),
        ],
        '#default_value' => 'animate',
        '#description' => $this->t('Animate uses Image 1 as the initial frame. References guide people, products, objects, wardrobe, or style.'),
        '#states' => [
          'visible' => [':input[name="output_type"]' => ['value' => 'video']],
        ],
      ];
      $reference_options = [];
      foreach ($turns as $candidate) {
        if ((int) $candidate->id() === $selected_turn_id
          || $candidate->get('status')->value !== 'completed'
          || $candidate->get('image')->isEmpty()) {
          continue;
        }
        $reference_options[(int) $candidate->id()] = $this->t('Image @number — @prompt', [
          '@number' => $turn_numbers[(int) $candidate->id()] ?? 1,
          '@prompt' => $this->promptSummary((string) $candidate->get('prompt')->value),
        ]);
      }
      $form['refine']['references'] = [
        '#type' => 'details',
        '#title' => $this->t('Additional reference images'),
        '#open' => FALSE,
        '#description' => $this->t('Image order matters. The selected source is Image 1; session images are added in the order shown, followed by Media and uploads.'),
        '#attributes' => ['data-ai-image-studio-references' => ''],
        '#states' => [
          'visible' => [
            ':input[name="output_type"]' => [
              ['value' => 'image'],
              'or',
              ['value' => 'video'],
            ],
          ],
        ],
        'turn_ids' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Session versions'),
          '#options' => $reference_options,
          '#attributes' => ['data-ai-image-studio-reference-options' => ''],
        ],
        'order' => [
          '#type' => 'hidden',
          '#attributes' => ['data-ai-image-studio-reference-order' => ''],
        ],
        'ordered_preview' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['ai-image-studio-reference-chips'],
            'data-ai-image-studio-reference-chips' => '',
            'aria-live' => 'polite',
          ],
        ],
        'media' => [
          '#type' => 'ai_image_studio_media_library',
          '#title' => $this->t('Media reference'),
          '#allowed_bundles' => [(string) ($settings->get('media_bundle') ?: 'image')],
          '#default_value' => $form_state->getValue(['references', 'media']) ?: NULL,
        ],
        'uploads' => [
          '#type' => 'managed_file',
          '#title' => $this->t('Uploaded references'),
          '#multiple' => TRUE,
          '#upload_location' => 'temporary://ai-image-studio',
          '#upload_validators' => [
            'FileExtension' => ['extensions' => 'png jpg jpeg webp'],
            'FileSizeLimit' => ['fileLimit' => $max_upload_bytes],
          ],
        ],
        'tokens' => [
          '#markup' => '<p class="description">' . $this->t('Reference-video prompts can identify inputs as &lt;IMAGE_1&gt;, &lt;IMAGE_2&gt;, and so on.') . '</p>',
        ],
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
      $form['refine']['text_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and model'),
        '#options' => $this->generator->getModelOptions('text_to_image'),
        '#default_value' => $this->configuredDefaultModel('text_to_image'),
        '#description' => $this->t('Creates a new image from the prompt without using a previous version as its source.'),
        '#required' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="output_type"]' => ['value' => 'prompt'],
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
      $form['refine']['prompt_start'] = $this->promptStartElement(
        $selected_source
          ? $this->t('Describe only the change you want to make to the selected image.')
          : $this->t('Describe the image to create.'),
      );
      $form['refine']['prompt'] = $this->promptElement();
      $form['refine']['prompt_start']['#states'] = [
        'disabled' => [
          ':input[name="regenerate_with_new_settings"]' => ['checked' => TRUE],
        ],
      ];
      $form['refine']['prompt']['#states'] = [
        'disabled' => [
          ':input[name="regenerate_with_new_settings"]' => ['checked' => TRUE],
        ],
      ];
      $form['refine']['regenerate_with_new_settings'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Regenerate with new settings'),
        '#description' => $this->t('Reuse the selected version’s prompt and apply the settings below.'),
        '#default_value' => FALSE,
        '#states' => [
          'visible' => [
            ':input[name="source_turn_id"]' => ['!value' => ''],
          ],
        ],
      ];
      $form['refine']['generation_controls'] = $this->generationControls($selected_settings);
      $form['refine']['video_controls'] = $this->videoControls($selected_settings);
      $form['refine']['actions'] = ['#type' => 'actions'];
      $form['refine']['actions']['generate'] = [
        '#type' => 'submit',
        '#value' => $default_output_type === 'video'
          ? $this->t('Generate Video')
          : $this->t('Generate Image'),
        '#button_type' => 'primary',
        '#studio_action' => 'generate',
        '#attributes' => [
          'data-ai-image-studio-generate' => '',
          'data-generate-image-label' => $this->t('Generate Image'),
          'data-generate-video-label' => $this->t('Generate Video'),
          'data-generating-image-label' => $this->t('Generating image…'),
          'data-generating-video-label' => $this->t('Generating video…'),
        ],
      ];
    }

    $form['session_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-image-studio-actions']],
    ];
    $completed_assets = array_filter($turns, static function (object $turn): bool {
      return $turn->get('status')->value === 'completed'
        && (!$turn->get('image')->isEmpty() || !$turn->get('video')->isEmpty());
    });
    $unpublished_assets = array_filter($completed_assets, static fn (object $turn): bool =>
      $turn->get('media_id')->isEmpty());
    $can_publish_images = $this->currentUserProxy
      ->hasPermission('publish ai image studio image');
    $can_publish_videos = $this->currentUserProxy
      ->hasPermission('publish ai image studio video');
    $has_publishable_asset = array_filter(
      $unpublished_assets,
      static fn (object $turn): bool => $turn->get('video')->isEmpty()
        ? $can_publish_images
        : $can_publish_videos,
    ) !== [];
    if ($session->access('update') && $has_publishable_asset) {
      $form['session_actions']['publish_all'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save all results to Media'),
        '#studio_action' => 'publish_all',
        '#limit_validation_errors' => [],
      ];
    }
    if ($completed_assets !== []) {
      $form['session_actions']['download_all'] = [
        '#type' => 'link',
        '#title' => $this->t('Download all images and videos'),
        '#url' => Url::fromRoute('ai_image_studio.download_all', [
          'ai_image_studio_session' => $session->id(),
        ]),
        '#attributes' => ['class' => ['button']],
      ];
    }
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
   * Creates the editor-written first portion of a prompt.
   */
  private function promptStartElement(string|\Stringable $description): array {
    return [
      '#type' => 'textarea',
      '#title' => $this->t('Start prompt'),
      '#description' => $description,
      '#required' => FALSE,
      '#maxlength' => $this->maximumPromptLength(),
      '#rows' => 5,
    ];
  }

  /**
   * Creates the reusable portion appended after the editor prompt.
   */
  private function promptElement(): array {
    if (!$this->promptResolver->promptTypeExists()) {
      return $this->unavailablePromptElement($this->t('After prompt'));
    }
    return [
      '#type' => 'ai_prompt',
      '#title' => $this->t('After prompt'),
      '#description' => $this->t('Optionally append a reusable style or instruction prompt after the start prompt.'),
      '#prompt_types' => [PromptResolver::PROMPT_TYPE],
      '#default_value' => '',
      // Validate this only for generation submissions. HTML's required
      // attribute would otherwise block the per-version Media submit buttons
      // before Drupal can apply their limited validation scope.
      '#required' => FALSE,
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
          ':input[name="output_type"]' => [
            ['value' => 'image'],
            'or',
            ['value' => 'prompt'],
          ],
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
        '#options' => array_combine(range(1, 10), range(1, 10)),
        '#default_value' => (int) ($defaults['variations'] ?? ($settings->get('default_image_variations') ?: 1)),
        '#description' => $this->t('Requests multiple results in one call when the selected provider supports it. Each result is retained as a separate version.'),
        '#wrapper_attributes' => [
          'data-ai-image-studio-variations-control' => '',
        ],
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
      'auto_levels' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Apply auto levels to the result'),
        '#default_value' => array_key_exists('auto_levels', $defaults)
          ? (bool) $defaults['auto_levels']
          : (bool) $settings->get('default_auto_levels'),
        '#disabled' => !$this->generator->canAutoLevels(),
        '#description' => $this->generator->canAutoLevels()
          ? $this->t('Automatically expands the RGB tonal range after generation. Transparency is preserved.')
          : $this->t('Auto levels is unavailable because the PHP Imagick extension is not installed.'),
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
        '#title' => $this->t('Show an AI video badge'),
        '#default_value' => array_key_exists('show_ai_badge', $defaults)
          ? (bool) $defaults['show_ai_badge']
          : (bool) $settings->get('default_show_ai_badge'),
      ],
      'video_ai_badge_text' => [
        '#type' => 'textfield',
        '#title' => $this->t('Badge text'),
        '#default_value' => $defaults['ai_badge_text'] ?? ($settings->get('default_video_ai_badge_text') ?: 'AI Video'),
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
   * Builds controls for repeating a completed video request.
   */
  private function buildVideoRegenerationForm(
    object $turn,
    int $number,
  ): array {
    $settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
    $operation = (string) $turn->get('operation')->value === 'text_to_video'
      ? 'text_to_video'
      : 'image_to_video';
    $models = $this->generator->getModelOptions($operation);
    $inherited_model = $this->turnModelOption($turn);
    $video_controls = $this->videoControls($settings);
    unset($video_controls['#states']);
    $video_controls['video_show_ai_badge']['#parents'] = [
      'video_regeneration',
      'settings',
      'video_show_ai_badge',
    ];
    $video_controls['video_ai_badge_text']['#states'] = [
      'visible' => [
        ':input[name="video_regeneration[settings][video_show_ai_badge]"]' => [
          'checked' => TRUE,
        ],
      ],
    ];

    return [
      '#type' => 'details',
      '#tree' => TRUE,
      '#weight' => -45,
      '#open' => TRUE,
      '#attributes' => ['id' => 'edit-video-regeneration'],
      '#title' => $this->t('Regenerate video version @number', [
        '@number' => $number,
      ]),
      '#description' => $this->t('This repeats the original video request using its original image inputs. It does not edit the generated video.'),
      'turn_id' => [
        '#type' => 'hidden',
        '#value' => (int) $turn->id(),
      ],
      'model' => [
        '#type' => 'select',
        '#title' => $this->t('Provider and video model'),
        '#options' => $models,
        '#default_value' => isset($models[$inherited_model])
          ? $inherited_model
          : $this->configuredDefaultModel($operation),
        '#required' => TRUE,
      ],
      'prompt_start' => [
        '#type' => 'textarea',
        '#title' => $this->t('Replacement start prompt'),
        '#description' => $this->t('Optional. Leave both replacement prompt fields empty to reuse the previous prompt: @prompt', [
          '@prompt' => $this->promptSummary((string) $turn->get('prompt')->value),
        ]),
        '#maxlength' => $this->maximumPromptLength(),
        '#rows' => 5,
      ],
      'prompt' => $this->replacementPromptElement($turn),
      'settings' => $video_controls,
      'actions' => [
        '#type' => 'actions',
        'generate' => [
          '#type' => 'submit',
          '#value' => $this->t('Regenerate video'),
          '#button_type' => 'primary',
          '#studio_action' => 'regenerate_video',
          '#attributes' => [
            'data-ai-image-studio-generate' => '',
            'data-ai-image-studio-output-type' => 'video',
            'data-generating-video-label' => $this->t('Generating video…'),
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
      'data-ai-image-studio-auto-levels' => !empty($settings['auto_levels']) ? '1' : '0',
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
          $video_attributes = [
            'class' => ['ai-image-studio-turn__video'],
            'controls' => 'controls',
            'preload' => 'auto',
            'src' => $file->createFileUrl(),
          ];
          $poster = $turn->get('source_file_id')->entity;
          if ($poster instanceof FileInterface) {
            $video_attributes['poster'] = $poster->createFileUrl();
          }
          $build['video'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-image-studio-turn__image-wrapper']],
            'asset' => [
              '#type' => 'html_tag',
              '#tag' => 'video',
              '#attributes' => $video_attributes,
            ],
            'badge' => !empty($settings['show_ai_badge']) ? [
              '#type' => 'html_tag',
              '#tag' => 'span',
              '#value' => Html::escape((string) ($settings['ai_badge_text'] ?? $this->t('AI Video'))),
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
      else {
        $build['regenerate_video'] = [
          '#type' => 'link',
          '#title' => $this->t('Regenerate with new settings'),
          '#url' => Url::fromRoute(
            'entity.ai_image_studio_session.canonical',
            [
              'ai_image_studio_session' => $turn->get('session_id')->target_id,
            ],
            [
              'query' => ['regenerate_video' => (int) $turn->id()],
              'fragment' => 'edit-video-regeneration',
            ],
          ),
          '#attributes' => ['class' => ['button']],
        ];
      }
      if ($published_media instanceof MediaInterface) {
        $build['published'] = $published_media
          ->toLink($this->t('View published Media'))
          ->toRenderable();
        if ($file instanceof FileInterface) {
          $build['download'] = [
            '#type' => 'link',
            '#title' => $this->t('Download Media'),
            '#url' => Url::fromUserInput($file->createFileUrl()),
            '#attributes' => [
              'class' => ['button'],
              'download' => TRUE,
            ],
          ];
        }
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
          '#title' => $this->t('Publish Media'),
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
                '@badge' => $settings['ai_badge_text'] ?? ($is_video
                  ? $this->t('AI Video')
                  : $this->t('AI Image')),
              ])
              : ($is_video
                ? $this->t('Badge rendering is unavailable because PHP GD or FFmpeg is not available to the web server. The original video can still be published.')
                : $this->t('Badge rendering is unavailable because PHP GD is not available to the web server. The original image can still be published.')),
          ],
          'actions' => [
            '#type' => 'actions',
            'submit' => [
              '#type' => 'submit',
              '#value' => $this->t('Publish to Media'),
              '#name' => 'publish_turn_' . $turn->id(),
              '#studio_action' => 'publish',
              '#turn_id' => $turn->id(),
              '#limit_validation_errors' => [
                ['history', 'turn_' . $turn->id(), 'publish', 'name'],
                ['history', 'turn_' . $turn->id(), 'publish', 'alt'],
                ['history', 'turn_' . $turn->id(), 'publish', 'render_badge'],
              ],
            ],
            'download' => $file instanceof FileInterface ? [
              '#type' => 'link',
              '#title' => $this->t('Download Media'),
              '#url' => Url::fromUserInput($file->createFileUrl()),
              '#attributes' => [
                'class' => ['button'],
                'download' => TRUE,
              ],
            ] : [],
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
      'reference_to_video' => $this->t('Reference video'),
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
      'reference_to_video',
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
    $input_count = $turn->hasField('source_file_ids')
      ? $turn->get('source_file_ids')->count()
      : 0;
    if ($input_count > 0) {
      $input_items = [];
      foreach ($turn->get('source_file_ids')->referencedEntities() as $index => $file) {
        if ($file instanceof FileInterface) {
          $input_items[] = $this->t('Image @number — @name', [
            '@number' => $index + 1,
            '@name' => $file->getFilename(),
          ]);
        }
      }
      $feedback['inputs'] = $this->feedbackItem(
        $this->t('Ordered inputs'),
        [
          '#theme' => 'item_list',
          '#items' => $input_items,
          '#attributes' => ['class' => ['ai-image-studio-input-provenance']],
        ],
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
      'reference_to_video',
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
        'reference_to_video',
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
          'reference_to_video',
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
          'reference_to_video' => $this->t('Reference video'),
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
    if (($trigger['#studio_action'] ?? '') === 'regenerate_video') {
      $values = (array) $form_state->getValue('video_regeneration');
      $turn = $this->completedVideoTurnFromSession(
        (int) $form_state->get('session_id'),
        (int) ($values['turn_id'] ?? 0),
      );
      if ($turn === NULL) {
        $form_state->setErrorByName(
          'video_regeneration][turn_id',
          $this->t('The video selected for regeneration is unavailable.'),
        );
        return;
      }
      $max_turns = (int) ($this->studioConfigFactory
        ->get('ai_image_studio.settings')
        ->get('max_turns') ?: 25);
      if ($this->countTurns((int) $form_state->get('session_id')) >= $max_turns) {
        $form_state->setErrorByName(
          'video_regeneration][turn_id',
          $this->t('This session has reached its limit of @count turns.', [
            '@count' => $max_turns,
          ]),
        );
      }
      if ($turn->get('operation')->value !== 'text_to_video'
        && $turn->get('source_file_ids')->referencedEntities() === []
        && !$turn->get('source_file_id')->entity instanceof FileInterface) {
        $form_state->setErrorByName(
          'video_regeneration][turn_id',
          $this->t('The original image inputs for this video are unavailable.'),
        );
      }
      $operation = (string) $turn->get('operation')->value === 'text_to_video'
        ? 'text_to_video'
        : 'image_to_video';
      $model = (string) ($values['model'] ?? '');
      if (!isset($this->generator->getModelOptions($operation)[$model])) {
        $form_state->setErrorByName(
          'video_regeneration][model',
          $this->t('The selected provider and model is not available for this request type.'),
        );
      }
      elseif ($turn->get('operation')->value === 'reference_to_video'
        && !$this->generator->supportsReferenceVideo($model)) {
        $form_state->setErrorByName(
          'video_regeneration][model',
          $this->t('The selected model does not support reference-to-video.'),
        );
      }
      $replacement_id = (string) ($values['prompt'] ?? '');
      $replacement_start = trim((string) ($values['prompt_start'] ?? ''));
      if ($replacement_id !== '' || $replacement_start !== '') {
        $replacement = $this->promptResolver->compose(
          $replacement_start,
          $replacement_id,
        );
        if ($replacement === '') {
          $form_state->setErrorByName(
            'video_regeneration][prompt_start',
            $this->t('Select a valid AI Image Studio prompt.'),
          );
        }
        elseif (mb_strlen($replacement) > $this->maximumPromptLength()) {
          $form_state->setErrorByName(
            'video_regeneration][prompt_start',
            $this->t('The selected prompt exceeds the maximum length of @count characters.', [
              '@count' => $this->maximumPromptLength(),
            ]),
          );
        }
      }
      return;
    }
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

    if ($this->generationPrompt($form_state) === '') {
      $form_state->setErrorByName(
        'prompt_start',
        $this->t('Enter a prompt to generate a result.'),
      );
    }
    elseif (mb_strlen($this->generationPrompt($form_state)) > $this->maximumPromptLength()) {
      $form_state->setErrorByName(
        'prompt_start',
        $this->t('The selected prompt exceeds the maximum length of @count characters.', [
          '@count' => $this->maximumPromptLength(),
        ]),
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
      $creation_mode = (string) ($form_state->getValue('output_type') ?: 'image');
      $requested_turns = $creation_mode !== 'video'
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
      if ($creation_mode !== 'prompt'
        && $this->completedTurnFromSession((int) $session_id, $source_turn_id) === NULL) {
        $form_state->setErrorByName(
          'source_turn_id',
          $this->t('Select a completed image from this session to refine.'),
        );
      }
    }
    $creation_mode = (string) ($form_state->getValue('output_type') ?: 'image');
    $output_type = $creation_mode === 'prompt' ? 'image' : $creation_mode;
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
          $this->mediaLibrarySelectionId($form_state->getValue('source_media')),
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
    else {
      $model_key = match ($creation_mode) {
        'video' => 'video_model',
        'prompt' => 'text_model',
        default => 'model',
      };
      if (!$form_state->getValue($model_key)) {
        $form_state->setErrorByName(
          $model_key,
          $this->t('Select a configured provider and model.'),
        );
      }
    }

    if (!$form_state->hasAnyErrors()) {
      $has_source = ($session_id !== NULL && $creation_mode !== 'prompt')
        || in_array($start_mode, ['upload', 'media'], TRUE);
      $operation = match ([$output_type, $has_source]) {
        ['video', TRUE] => 'image_to_video',
        ['video', FALSE] => 'text_to_video',
        ['image', TRUE] => 'image_to_image',
        default => 'text_to_image',
      };
      if ($session_id !== NULL) {
        $model_key = match ($creation_mode) {
          'video' => 'video_model',
          'prompt' => 'text_model',
          default => 'model',
        };
      }
      else {
        $model_key = $has_source
          ? ($output_type === 'video' ? 'image_video_model' : 'image_model')
          : ($output_type === 'video' ? 'text_video_model' : 'text_model');
      }
      $model = (string) $form_state->getValue($model_key);
      if (!isset($this->generator->getModelOptions($operation)[$model])) {
        $form_state->setErrorByName(
          $model_key,
          $this->t('The selected provider and model is not available for this request type.'),
        );
      }
      elseif ($output_type === 'image') {
        $variations = max(1, (int) ($form_state->getValue('variations') ?: 1));
        $maximum_variations = $this->generator->getMaxVariations($model);
        if ($variations > $maximum_variations) {
          $form_state->setErrorByName('variations', $this->t(
            'The selected model supports at most @count variations per request.',
            ['@count' => $maximum_variations],
          ));
        }
      }

      $reference_files = $this->referenceFilesFromForm($form_state, $session_id);
      $video_mode = (string) ($form_state->getValue('video_mode') ?: 'animate');
      $reference_mode = $output_type === 'video' && $video_mode === 'reference';
      $maximum = $reference_mode ? 7 : 3;
      if (count($reference_files) > $maximum) {
        $form_state->setErrorByName('references', $this->t(
          'This mode accepts at most @count ordered input images.',
          ['@count' => $maximum],
        ));
      }
      $maximum_bytes = (int) ($this->studioConfigFactory
        ->get('ai_image_studio.settings')->get('max_source_image_size_mb') ?: 20)
        * 1024 * 1024;
      foreach ($reference_files as $reference_file) {
        if (!in_array($reference_file->getMimeType(), [
          'image/jpeg',
          'image/png',
          'image/webp',
        ], TRUE)) {
          $form_state->setErrorByName('references', $this->t('Reference images must be PNG, JPEG, or WebP files.'));
          break;
        }
        if ((int) $reference_file->getSize() > $maximum_bytes) {
          $form_state->setErrorByName('references', $this->t('A reference image exceeds the configured upload limit.'));
          break;
        }
      }
      if (count($reference_files) !== count(array_unique(array_map(
        static fn (FileInterface $file): int => (int) $file->id(),
        $reference_files,
      )))) {
        $form_state->setErrorByName('references', $this->t('The same image cannot be supplied more than once.'));
      }
      if (count($reference_files) > 1
        && !$reference_mode
        && !$this->generator->supportsMultipleImages($model)) {
        $form_state->setErrorByName('references', $this->t('The selected provider does not support multiple image inputs.'));
      }
      if ($reference_mode) {
        if (!$this->generator->supportsReferenceVideo($model)) {
          $form_state->setErrorByName('video_model', $this->t('The selected model does not support reference-to-video.'));
        }
        if ($reference_files === []) {
          $form_state->setErrorByName('references', $this->t('Add at least one reference image.'));
        }
        if ((int) $form_state->getValue('duration') > 10) {
          $form_state->setErrorByName('duration', $this->t('Reference-to-video is limited to 10 seconds.'));
        }
        if ($form_state->getValue('video_resolution') === '1080p') {
          $form_state->setErrorByName('video_resolution', $this->t('Reference-to-video supports 480p or 720p output.'));
        }
        preg_match_all('/<IMAGE_(\d+)>/', $this->generationPrompt($form_state), $matches);
        foreach (array_map('intval', $matches[1] ?? []) as $number) {
          if ($number < 1 || $number > count($reference_files)) {
            $form_state->setErrorByName('prompt_start', $this->t(
              'The token <IMAGE_@number> has no corresponding reference image.',
              ['@number' => $number],
            ));
          }
        }
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
    if (($trigger['#studio_action'] ?? '') === 'publish_all') {
      $this->publishAllTurns($form_state);
      return;
    }
    if (($trigger['#studio_action'] ?? '') === 'regenerate_video') {
      $this->regenerateVideo($form_state);
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

    $creation_mode = (string) ($form_state->getValue('output_type') ?: 'image');
    $parent = $session_id === NULL || $creation_mode === 'prompt'
      ? NULL
      : $this->completedTurnFromSession(
        (int) $session->id(),
        (int) $form_state->getValue('source_turn_id'),
      );
    $source = NULL;
    $output_type = $creation_mode === 'prompt' ? 'image' : $creation_mode;
    $model = $session_id === NULL
      ? (string) $form_state->getValue(
        $output_type === 'video' ? 'image_video_model' : 'image_model',
      )
      : (string) $form_state->getValue(match ($creation_mode) {
        'video' => 'video_model',
        'prompt' => 'text_model',
        default => 'model',
      });
    if ($session_id === NULL) {
      if ($form_state->getValue('start_mode') === 'upload') {
        $file_ids = array_filter((array) $form_state->getValue('source_image'));
        $source = $this->entityTypeManager->getStorage('file')->load(reset($file_ids));
      }
      elseif ($form_state->getValue('start_mode') === 'media') {
        $media_id = $this->mediaLibrarySelectionId(
          $form_state->getValue('source_media'),
        );
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
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

    $reference_files = $this->referenceFilesFromForm($form_state, $session_id);
    $video_mode = (string) ($form_state->getValue('video_mode') ?: 'animate');
    $prompt = $this->generationPrompt($form_state);
    if ($output_type === 'video' && $video_mode === 'reference') {
      preg_match_all('/<IMAGE_(\d+)>/', $prompt, $matches);
      if (count(array_unique($matches[1] ?? [])) < count($reference_files)) {
        $this->messenger()->addWarning($this->t('One or more reference images are not named in the prompt. Use <IMAGE_1>, <IMAGE_2>, and so on when the distinction matters.'));
      }
    }

    $turn = $this->generator->generate(
      $session,
      $prompt,
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
        'prompt' => $prompt,
        'transparent_background' => $form_state->getValue('transparent_background'),
        'file_type' => $form_state->getValue('file_type') ?: 'png',
        'auto_levels' => (bool) $form_state->getValue('auto_levels'),
        'show_ai_badge' => (bool) $form_state->getValue(
          $output_type === 'video' ? 'video_show_ai_badge' : 'show_ai_badge',
        ),
        'ai_badge_text' => trim((string) $form_state->getValue(
          $output_type === 'video' ? 'video_ai_badge_text' : 'ai_badge_text',
        )) ?: ($output_type === 'video' ? 'AI Video' : 'AI Image'),
        'reference_file_ids' => array_map(
          static fn (FileInterface $file): int => (int) $file->id(),
          $reference_files,
        ),
        'video_mode' => $video_mode,
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
        'The video was queued. Studio starts it in the background and refreshes this page while it processes.',
      ));
    }
    else {
      $this->messenger()->addError($this->t('Generation failed: @message', [
        '@message' => $turn->get('error_message')->value,
      ]));
    }
    $form_state->setRedirect('entity.ai_image_studio_session.canonical', [
      'ai_image_studio_session' => $session->id(),
    ], $output_type === 'video'
      ? ['fragment' => 'ai-image-studio-turn-' . $turn->id()]
      : []);
  }

  /**
   * Repeats a completed video request with updated settings and prompt.
   */
  private function regenerateVideo(FormStateInterface $form_state): void {
    $session_id = (int) $form_state->get('session_id');
    $values = (array) $form_state->getValue('video_regeneration');
    $turn = $this->completedVideoTurnFromSession(
      $session_id,
      (int) ($values['turn_id'] ?? 0),
    );
    $session = $this->entityTypeManager
      ->getStorage('ai_image_studio_session')
      ->load($session_id);
    if ($turn === NULL || $session === NULL || !$session->access('update')) {
      $this->messenger()->addError($this->t('The video selected for regeneration is unavailable.'));
      return;
    }

    $sources = array_values(array_filter(
      $turn->get('source_file_ids')->referencedEntities(),
      static fn (mixed $file): bool => $file instanceof FileInterface,
    ));
    if ($sources === [] && $turn->get('source_file_id')->entity instanceof FileInterface) {
      $sources[] = $turn->get('source_file_id')->entity;
    }
    $settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
    $submitted_settings = (array) ($values['settings'] ?? []);
    $settings = array_replace($settings, [
      'duration' => $submitted_settings['duration'] ?? $settings['duration'] ?? NULL,
      'aspect_ratio' => $submitted_settings['video_aspect_ratio'] ?? $settings['aspect_ratio'] ?? 'auto',
      'resolution' => $submitted_settings['video_resolution'] ?? $settings['resolution'] ?? '720p',
      'show_ai_badge' => !empty($submitted_settings['video_show_ai_badge']),
      'ai_badge_text' => trim((string) ($submitted_settings['video_ai_badge_text'] ?? '')) ?: 'AI Video',
      'reference_file_ids' => array_map(
        static fn (FileInterface $file): int => (int) $file->id(),
        $sources,
      ),
    ]);
    $prompt = $this->promptResolver->compose(
      $values['prompt_start'] ?? '',
      $values['prompt'] ?? '',
    );
    if ($prompt === '') {
      $prompt = trim((string) $turn->get('prompt')->value);
    }
    $parent = $turn->get('parent_id')->entity;
    $result = $this->generator->generate(
      $session,
      $prompt,
      (string) ($values['model'] ?? ''),
      $parent,
      $sources[0] ?? NULL,
      $settings,
      'video',
    );
    if ($result->get('status')->value === 'queued') {
      $this->messenger()->addStatus($this->t('The regenerated video was queued.'));
    }
    elseif ($result->get('status')->value === 'completed') {
      $this->messenger()->addStatus($this->t('The video was regenerated successfully.'));
    }
    else {
      $this->messenger()->addError($this->t('Video regeneration failed: @message', [
        '@message' => $result->get('error_message')->value,
      ]));
    }
    $form_state->setRedirect('entity.ai_image_studio_session.canonical', [
      'ai_image_studio_session' => $session_id,
    ], [
      'fragment' => 'ai-image-studio-turn-' . $result->id(),
    ]);
  }

  /**
   * Resolves the prompt submitted for a generation request.
   */
  private function generationPrompt(FormStateInterface $form_state): string {
    if (!$form_state->getValue('regenerate_with_new_settings')) {
      return $this->promptResolver->compose(
        $form_state->getValue('prompt_start'),
        $form_state->getValue('prompt'),
      );
    }

    $session_id = (int) $form_state->get('session_id');
    $source = $this->completedTurnFromSession(
      $session_id,
      (int) $form_state->getValue('source_turn_id'),
    );
    return trim((string) ($source?->get('prompt')->value ?? ''));
  }

  /**
   * Builds an optional managed prompt for video regeneration.
   */
  private function replacementPromptElement(object $turn): array {
    if (!$this->promptResolver->promptTypeExists()) {
      return $this->unavailablePromptElement($this->t('Replacement after prompt'));
    }
    return [
      '#type' => 'ai_prompt',
      '#title' => $this->t('Replacement after prompt'),
      '#description' => $this->t('Optionally append a reusable prompt after the replacement start prompt.'),
      '#prompt_types' => [PromptResolver::PROMPT_TYPE],
      '#default_value' => '',
      '#required' => FALSE,
    ];
  }

  /**
   * Builds a safe placeholder when managed prompt configuration is missing.
   */
  private function unavailablePromptElement(string|\Stringable $title): array {
    return [
      '#type' => 'select',
      '#title' => $title,
      '#options' => [],
      '#empty_option' => $this->t('- Prompt library unavailable -'),
      '#disabled' => TRUE,
      '#description' => $this->t('The reusable AI Image Studio prompt type is missing from active configuration. The start prompt can still be used.'),
    ];
  }

  /**
   * Returns the configured prompt length limit.
   */
  private function maximumPromptLength(): int {
    return (int) ($this->studioConfigFactory
      ->get('ai_image_studio.settings')
      ->get('max_prompt_length') ?: 4000);
  }

  /**
   * Extracts the single entity ID returned by the Media Library form element.
   */
  private function mediaLibrarySelectionId(mixed $value): int {
    $ids = array_values(array_filter(array_map(
      static fn (string $id): int => (int) trim($id),
      explode(',', (string) $value),
    )));
    return $ids[0] ?? 0;
  }

  /**
   * Loads ordered, accessible image inputs selected in the generation form.
   */
  private function referenceFilesFromForm(
    FormStateInterface $form_state,
    mixed $session_id,
  ): array {
    $ids = [];
    if ($session_id === NULL) {
      $start_mode = (string) $form_state->getValue('start_mode');
      if ($start_mode === 'upload') {
        $ids = array_values(array_filter(array_map(
          'intval',
          (array) $form_state->getValue('source_image'),
        )));
      }
      elseif ($start_mode === 'media') {
        $media_id = $this->mediaLibrarySelectionId($form_state->getValue('source_media'));
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        $field = (string) ($this->studioConfigFactory
          ->get('ai_image_studio.settings')->get('media_source_field') ?: 'field_media_image');
        if ($media instanceof MediaInterface
          && $media->hasField($field)
          && $media->get($field)->entity instanceof FileInterface) {
          $ids[] = (int) $media->get($field)->target_id;
        }
      }
    }
    else {
      if ($form_state->getValue('output_type') === 'prompt') {
        return [];
      }
      $primary = $this->completedTurnFromSession(
        (int) $session_id,
        (int) $form_state->getValue('source_turn_id'),
      );
      if ($primary !== NULL && !$primary->get('image')->isEmpty()) {
        $ids[] = (int) $primary->get('image')->target_id;
      }
      $selected_turn_ids = array_values(array_filter(array_map(
        'intval',
        (array) $form_state->getValue('turn_ids'),
      )));
      $requested_order = array_values(array_filter(array_map(
        'intval',
        explode(',', (string) $form_state->getValue('order')),
      )));
      $ordered_turn_ids = array_values(array_intersect($requested_order, $selected_turn_ids));
      $ordered_turn_ids = array_merge(
        $ordered_turn_ids,
        array_values(array_diff($selected_turn_ids, $ordered_turn_ids)),
      );
      foreach ($ordered_turn_ids as $turn_id) {
        $turn = $this->completedTurnFromSession((int) $session_id, (int) $turn_id);
        if ($turn !== NULL && !$turn->get('image')->isEmpty()) {
          $ids[] = (int) $turn->get('image')->target_id;
        }
      }
      $media_id = $this->mediaLibrarySelectionId($form_state->getValue('media'));
      if ($media_id > 0) {
        $media = $this->entityTypeManager->getStorage('media')->load($media_id);
        $field = (string) ($this->studioConfigFactory
          ->get('ai_image_studio.settings')->get('media_source_field') ?: 'field_media_image');
        if ($media instanceof MediaInterface
          && $media->hasField($field)
          && $media->get($field)->entity instanceof FileInterface) {
          $ids[] = (int) $media->get($field)->target_id;
        }
      }
      $ids = array_merge($ids, array_values(array_filter(array_map(
        'intval',
        (array) $form_state->getValue('uploads'),
      ))));
    }
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple($ids);
    return array_values(array_filter(array_map(
      static fn (int $id): mixed => $files[$id] ?? NULL,
      $ids,
    ), static fn (mixed $file): bool => $file instanceof FileInterface));
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
   * Publishes every eligible result in the current session to Media.
   */
  private function publishAllTurns(FormStateInterface $form_state): void {
    $session_id = (int) $form_state->get('session_id');
    $session = $this->entityTypeManager
      ->getStorage('ai_image_studio_session')
      ->load($session_id);
    if ($session === NULL || !$session->access('update')) {
      $this->messenger()->addError($this->t('The image session is unavailable.'));
      $form_state->setRedirect('entity.ai_image_studio_session.collection');
      return;
    }

    $published = 0;
    $failed = 0;
    foreach (array_values($this->loadTurns($session_id)) as $index => $turn) {
      $is_video = !$turn->get('video')->isEmpty();
      $has_asset = $is_video || !$turn->get('image')->isEmpty();
      $permission = $is_video
        ? 'publish ai image studio video'
        : 'publish ai image studio image';
      if ($turn->get('status')->value !== 'completed' || !$has_asset
        || !$turn->get('media_id')->isEmpty()
        || !$this->currentUserProxy->hasPermission($permission)) {
        continue;
      }
      try {
        $this->generator->publish(
          $turn,
          $this->defaultMediaName($turn, $index + 1),
          $is_video ? '' : $this->suggestedAltText($turn),
          FALSE,
        );
        $published++;
      }
      catch (\Throwable $exception) {
        $failed++;
        $this->getLogger('ai_image_studio')->error(
          'Could not publish Studio turn @turn to Media: @message',
          ['@turn' => $turn->id(), '@message' => $exception->getMessage()],
        );
      }
    }

    if ($published > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $published,
        'Saved 1 result to Media.',
        'Saved @count results to Media.',
      ));
    }
    if ($failed > 0) {
      $this->messenger()->addError($this->formatPlural(
        $failed,
        '1 result could not be saved. Check the log for details.',
        '@count results could not be saved. Check the log for details.',
      ));
    }
    if ($published === 0 && $failed === 0) {
      $this->messenger()->addStatus($this->t('All eligible results are already saved to Media.'));
    }
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

  /**
   * Loads a completed video turn belonging to the requested session.
   */
  private function completedVideoTurnFromSession(
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
      || $turn->get('video')->isEmpty()) {
      return NULL;
    }
    return $turn;
  }

}
