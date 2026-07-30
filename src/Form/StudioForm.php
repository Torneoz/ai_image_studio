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

    $form['#attached']['library'][] = 'ai_image_studio/studio';
    $form['#attributes']['class'][] = 'ai-image-studio-layout';

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
        ],
        '#default_value' => 'prompt',
      ];
      $form['source_image'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Starting image'),
        '#upload_location' => 'temporary://ai-image-studio',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'png jpg jpeg webp'],
          'FileSizeLimit' => ['fileLimit' => 20 * 1024 * 1024],
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
      $form['text_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and model'),
        '#options' => $this->generator->getModelOptions('text_to_image'),
        '#default_value' => $this->generator->getDefaultModel('text_to_image'),
        '#description' => $this->t('Uses the Text-to-Image default configured in Drupal AI. Only configured providers and models are listed.'),
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => ['value' => 'prompt'],
          ],
        ],
      ];
      $form['image_model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and model'),
        '#options' => $this->generator->getModelOptions('image_to_image'),
        '#default_value' => $this->generator->getDefaultModel('image_to_image'),
        '#description' => $this->t('Only providers that advertise Image-to-Image editing support are listed.'),
        '#states' => [
          'visible' => [
            ':input[name="start_mode"]' => ['value' => 'upload'],
          ],
        ],
      ];
      $form['prompt'] = $this->promptElement(
        $this->t('Describe the image to create or how to transform the upload.'),
        $max_length,
      );
      $form['generation_controls'] = $this->generationControls();
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['generate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Create image'),
        '#button_type' => 'primary',
        '#studio_action' => 'generate',
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

    $form['history'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['ai-image-studio-turns']],
    ];
    $version_number = 1;
    foreach ($turns as $turn) {
      $form['history']['turn_' . $turn->id()] = $this->buildTurn(
        $turn,
        $version_number,
        $selected_turn_id,
      );
      $version_number++;
    }

    $max_turns = (int) ($settings->get('max_turns') ?: 25);
    if (count($turns) >= $max_turns) {
      $form['limit'] = [
        '#type' => 'status_messages',
        '#message_list' => [
          'warning' => [
            $this->t('This session has reached its limit of @count turns.', ['@count' => $max_turns]),
          ],
        ],
      ];
    }
    else {
      $form['refine'] = [
        '#type' => 'details',
        '#title' => $selected_source
          ? $this->t('Refine selected image')
          : $this->t('Retry image creation'),
        '#open' => TRUE,
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
              . $this->t('The new version will branch from this image. Select another source from any completed version above.')
              . '</div>',
            ],
          ],
        ];
      }
      $operation = $selected_source ? 'image_to_image' : 'text_to_image';
      $form['refine']['model'] = [
        '#type' => 'select',
        '#title' => $this->t('Provider and model'),
        '#options' => $this->generator->getModelOptions($operation),
        '#default_value' => $this->generator->getDefaultModel($operation),
        '#description' => $selected_source
          ? $this->t('Only providers that advertise Image-to-Image editing support are listed.')
          : $this->t('Uses the Text-to-Image default configured in Drupal AI.'),
        '#required' => TRUE,
      ];
      $form['refine']['prompt'] = $this->promptElement(
        $selected_source
          ? $this->t('Describe only the change you want to make to the selected image.')
          : $this->t('Describe the image to create.'),
        $max_length,
      );
      $form['refine']['generation_controls'] = $this->generationControls();
      $form['refine']['actions'] = ['#type' => 'actions'];
      $form['refine']['actions']['generate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Refine image'),
        '#button_type' => 'primary',
        '#studio_action' => 'generate',
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
   * Builds common image output controls.
   */
  private function generationControls(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Image settings'),
      '#open' => TRUE,
      'aspect_ratio' => [
        '#type' => 'select',
        '#title' => $this->t('Aspect ratio'),
        '#options' => [
          'auto' => $this->t('Automatic'),
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
        '#default_value' => 'auto',
        '#description' => $this->t('Availability depends on the selected provider and model.'),
      ],
      'resolution' => [
        '#type' => 'select',
        '#title' => $this->t('Resolution'),
        '#options' => [
          '1k' => $this->t('1K — faster and lower cost'),
          '2k' => $this->t('2K — higher detail and cost'),
        ],
        '#default_value' => '1k',
        '#description' => $this->t('Grok Imagine supports 1K and 2K output. Other providers may ignore this setting.'),
      ],
      'transparent_background' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Request a transparent background'),
        '#default_value' => FALSE,
        '#description' => $this->t('Best effort for Grok text-to-image generation; editing models and other providers may ignore it.'),
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
  ): array {
    $prompt = (string) $turn->get('prompt')->value;
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
            'status' => $this->buildStatus($turn),
            'cost' => $this->buildCost($turn),
          ],
        ],
        'summary' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-image-studio-turn__summary']],
          'feedback' => $this->buildRequestFeedback($turn),
        ],
      ],
      'prompt' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Prompt'),
        '#items' => [['#plain_text' => $prompt]],
      ],
    ];
    $settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
    if ($settings) {
      $build['settings'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-image-studio-meta']],
        'value' => [
          '#plain_text' => $this->t('Aspect ratio: @ratio · Resolution: @resolution@transparent', [
            '@ratio' => $settings['aspect_ratio'] ?? $this->t('Automatic'),
            '@resolution' => strtoupper((string) ($settings['resolution'] ?? '1k')),
            '@transparent' => !empty($settings['transparent_background'])
              ? ' · ' . $this->t('Transparent background requested')
              : '',
          ]),
        ],
      ];
    }

    if ($turn->get('status')->value === 'completed' && !$turn->get('image')->isEmpty()) {
      $file = $turn->get('image')->entity;
      if ($file instanceof FileInterface) {
        $build['image'] = [
          '#theme' => 'image',
          '#uri' => $file->getFileUri(),
          '#alt' => (string) $turn->get('prompt')->value,
          '#title' => $this->t('Generated version @number', ['@number' => $number]),
          '#attributes' => ['class' => ['ai-image-studio-turn__image']],
        ];
      }
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
      if (!$turn->get('media_id')->isEmpty()) {
        $media = $turn->get('media_id')->entity;
        if ($media instanceof MediaInterface) {
          $build['published'] = $media->toLink($this->t('View published Media'))->toRenderable();
        }
      }
      elseif ($this->currentUserProxy->hasPermission('publish ai image studio image')) {
        $build['publish'] = [
          '#type' => 'details',
          '#tree' => TRUE,
          '#title' => $this->t('Publish to Media'),
          'name' => [
            '#type' => 'textfield',
            '#title' => $this->t('Media name'),
            '#default_value' => mb_substr((string) $turn->get('prompt')->value, 0, 120),
            '#maxlength' => 255,
            '#required' => TRUE,
          ],
          'alt' => [
            '#type' => 'textfield',
            '#title' => $this->t('Alternative text'),
            '#maxlength' => 512,
            '#description' => $this->t('Required when this version is published to Media.'),
          ],
          'submit' => [
            '#type' => 'submit',
            '#value' => $this->t('Publish this version'),
            '#studio_action' => 'publish',
            '#turn_id' => $turn->id(),
            '#limit_validation_errors' => [
              ['history', 'turn_' . $turn->id(), 'publish', 'name'],
              ['history', 'turn_' . $turn->id(), 'publish', 'alt'],
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
   * Builds readable request feedback for a generated version.
   */
  private function buildRequestFeedback(object $turn): array {
    $settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
    $operation = $turn->get('operation')->value === 'image_to_image'
      ? $this->t('Image edit')
      : $this->t('Image generation');
    $ratio = (string) ($settings['aspect_ratio'] ?? 'auto');
    $ratio = $ratio === 'auto' ? $this->t('Automatic ratio') : $ratio;
    $resolution = strtoupper((string) ($settings['resolution'] ?? '1k'));
    $detail = ($settings['resolution'] ?? '1k') === '2k'
      ? $this->t('High detail')
      : $this->t('Standard detail');
    $duration_ms = (int) ($turn->get('duration_ms')->value ?? 0);
    $duration = $duration_ms > 0
      ? number_format($duration_ms / 1000, 1) . 's'
      : $this->t('Time unavailable');
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-image-studio-feedback']],
      'provider' => $this->feedbackItem(
        $this->t('Provider'),
        (string) $turn->get('provider_id')->value,
      ),
      'model' => $this->feedbackItem(
        $this->t('Model'),
        (string) $turn->get('model_id')->value,
      ),
      'operation' => $this->feedbackItem($this->t('Request'), (string) $operation),
      'output' => $this->feedbackItem(
        $this->t('Output'),
        (string) $this->t('@ratio · @resolution · @detail', [
          '@ratio' => $ratio,
          '@resolution' => $resolution,
          '@detail' => $detail,
        ]),
      ),
      'duration' => $this->feedbackItem($this->t('Processing'), (string) $duration),
      'tokens' => $this->feedbackItem($this->t('Tokens'), $this->formatTokens($turn)),
    ];
  }

  /**
   * Builds the compact generation status pill.
   */
  private function buildStatus(object $turn): array {
    $status = (string) $turn->get('status')->value;
    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => Html::escape(ucfirst($status)),
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
    string|\Stringable $value,
    array $classes = [],
  ): array {
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
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => Html::escape((string) $value),
        '#attributes' => ['class' => ['ai-image-studio-feedback__value']],
      ],
    ];
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
      return (string) $this->t('Not reported · image-billed');
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
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    if (($trigger['#studio_action'] ?? '') === 'publish') {
      $turn_id = (int) ($trigger['#turn_id'] ?? 0);
      $values = $form_state->getValue([
        'history',
        'turn_' . $turn_id,
        'publish',
      ]);
      if (trim((string) ($values['alt'] ?? '')) === '') {
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
        $this->t('Enter a prompt to generate an image.'),
      );
    }

    $session_id = $form_state->get('session_id');
    if ($session_id !== NULL) {
      $source_turn_id = (int) $form_state->getValue('source_turn_id');
      if ($this->completedTurnFromSession((int) $session_id, $source_turn_id) === NULL) {
        $form_state->setErrorByName(
          'source_turn_id',
          $this->t('Select a completed image from this session to refine.'),
        );
      }
    }
    if ($session_id === NULL && $form_state->getValue('start_mode') === 'upload') {
      $files = array_filter((array) $form_state->getValue('source_image'));
      if (!$files) {
        $form_state->setErrorByName('source_image', $this->t('Upload a starting image.'));
      }
      if (!$form_state->getValue('image_model')) {
        $form_state->setErrorByName('image_model', $this->t('Select an image editing model.'));
      }
    }
    elseif ($session_id === NULL && !$form_state->getValue('text_model')) {
      $form_state->setErrorByName('text_model', $this->t('Select a text-to-image model.'));
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
      if ($session === NULL || !$session->access('view')) {
        throw new \RuntimeException('The image session is unavailable.');
      }
    }

    $parent = $session_id === NULL
      ? NULL
      : $this->completedTurnFromSession(
        (int) $session->id(),
        (int) $form_state->getValue('source_turn_id'),
      );
    $source = NULL;
    $model = $session_id === NULL
      ? (string) $form_state->getValue('image_model')
      : (string) $form_state->getValue('model');
    if ($session_id === NULL) {
      if ($form_state->getValue('start_mode') === 'upload') {
        $file_ids = array_filter((array) $form_state->getValue('source_image'));
        $source = $this->entityTypeManager->getStorage('file')->load(reset($file_ids));
      }
      else {
        $model = (string) $form_state->getValue('text_model');
      }
    }

    $turn = $this->generator->generate(
      $session,
      trim((string) $form_state->getValue('prompt')),
      $model,
      $parent,
      $source instanceof FileInterface ? $source : NULL,
      [
        'aspect_ratio' => $form_state->getValue('aspect_ratio'),
        'resolution' => $form_state->getValue('resolution'),
        'transparent_background' => $form_state->getValue('transparent_background'),
      ],
    );
    if ($turn->get('status')->value === 'completed') {
      $this->messenger()->addStatus($this->t('The image was generated successfully.'));
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
      throw new \RuntimeException('The selected image version is unavailable.');
    }
    $session = $this->entityTypeManager->getStorage('ai_image_studio_session')->load($session_id);
    if ($session === NULL || !$session->access('view')
      || !$this->currentUserProxy->hasPermission('publish ai image studio image')) {
      throw new \RuntimeException('You are not allowed to publish this image.');
    }

    $values = $form_state->getValue([
      'history',
      'turn_' . $turn_id,
      'publish',
    ]);
    $media = $this->generator->publish(
      $turn,
      trim((string) ($values['name'] ?? '')),
      trim((string) ($values['alt'] ?? '')),
    );
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
   * Finds the last successful image to refine.
   */
  private function latestCompletedTurn(int $session_id): ?object {
    $storage = $this->entityTypeManager->getStorage('ai_image_studio_turn');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $session_id)
      ->condition('status', 'completed')
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
