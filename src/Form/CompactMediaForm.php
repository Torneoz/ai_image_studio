<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Form;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_image_studio\Service\PromptResolver;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media_library\Form\AddFormBase;

/**
 * Builds the compact Studio embedded in Media image forms.
 */
final class CompactMediaForm {

  /**
   * Constructs the compact Media form helper.
   */
  public function __construct(
    private readonly ImageGenerator $generator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly PromptResolver $promptResolver,
  ) {}

  /**
   * Builds the compact generation controls.
   */
  public function build(FormStateInterface $form_state): array {
    $settings = $this->configFactory->get('ai_image_studio.settings');
    $models = $this->generator->getModelOptions('text_to_image');
    $configured = (string) $settings->get('default_text_to_image_model');
    $default_model = isset($models[$configured])
      ? $configured
      : $this->generator->getDefaultModel('text_to_image');
    $turn = $this->loadTurn($form_state);
    $is_media_library = $form_state->get('media_library_state') !== NULL;
    $ajax_url = $is_media_library
      ? Url::fromRoute(
        'media_library.ui',
        $form_state->get('media_library_state')->all(),
      )
      : NULL;

    $element = [
      '#type' => 'details',
      '#title' => t('AI Image Studio'),
      '#weight' => 2,
      '#open' => TRUE,
      '#tree' => TRUE,
      '#attributes' => ['class' => ['ai-image-studio-compact']],
      '#states' => [
        'visible' => [
          'select[name="image_source"]' => ['value' => 'ai_image_studio'],
        ],
      ],
      '#attached' => [
        'library' => ['ai_image_studio/media_source'],
      ],
      'messages' => [
        '#type' => 'status_messages',
        '#weight' => -100,
      ],
      'prompt_start' => [
        '#type' => 'textarea',
        '#title' => t('Start prompt'),
        '#description' => t('Describe the image to create.'),
        '#rows' => 3,
        '#maxlength' => (int) ($settings->get('max_prompt_length') ?: 4000),
        '#default_value' => $form_state->getValue([
          'ai_image_studio_compact',
          'prompt_start',
        ]),
      ],
      'style_prompt' => $this->stylePromptElement($form_state),
      'prompt' => $this->promptElement($form_state),
      'model' => [
        '#type' => 'select',
        '#title' => t('Provider and model'),
        '#options' => $models,
        '#default_value' => $default_model,
        '#required' => TRUE,
      ],
      'settings' => [
        '#type' => 'details',
        '#title' => t('Image settings'),
        '#open' => FALSE,
        'aspect_ratio' => [
          '#type' => 'select',
          '#title' => t('Aspect ratio'),
          '#options' => [
            'auto' => t('Automatic'),
            '1:1' => t('Square — 1:1'),
            '16:9' => t('Landscape — 16:9'),
            '9:16' => t('Portrait — 9:16'),
            '4:3' => t('Landscape — 4:3'),
            '3:4' => t('Portrait — 3:4'),
          ],
          '#default_value' => $settings->get('default_aspect_ratio') ?: 'auto',
        ],
        'resolution' => [
          '#type' => 'select',
          '#title' => t('Resolution'),
          '#options' => [
            'auto' => t('Automatic'),
            '1k' => t('1K'),
            '2k' => t('2K'),
          ],
          '#default_value' => $settings->get('default_image_resolution') ?: 'auto',
        ],
        'transparent_background' => [
          '#type' => 'checkbox',
          '#title' => t('Request a transparent background'),
          '#default_value' => (bool) $settings->get('default_transparent_background'),
        ],
        'auto_levels' => [
          '#type' => 'checkbox',
          '#title' => t('Apply auto levels to the result'),
          '#default_value' => (bool) $settings->get('default_auto_levels'),
          '#disabled' => !$this->generator->canAutoLevels(),
          '#description' => $this->generator->canAutoLevels()
            ? t('Automatically expands the RGB tonal range after generation.')
            : t('Requires the PHP Imagick extension.'),
        ],
      ],
      'actions' => [
        // Do not use an actions element here. Drupal's dialog integration
        // extracts submit buttons from .form-actions and hides the originals.
        // Since this form is nested inside Media Library's add form, that can
        // leave the generator without a visible submit button.
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-image-studio-compact__actions']],
        'generate' => [
          '#type' => 'submit',
          '#value' => $turn ? t('Generate Another') : t('Generate Image'),
          '#name' => 'ai_image_studio_compact_generate',
          '#studio_compact_action' => 'generate',
          '#submit' => ['ai_image_studio_compact_generate_submit'],
          '#limit_validation_errors' => [
            ['ai_image_studio_compact', 'prompt_start'],
            ['ai_image_studio_compact', 'prompt'],
            ['ai_image_studio_compact', 'model'],
            ['ai_image_studio_compact', 'settings'],
          ],
          '#ajax' => [
            'callback' => 'ai_image_studio_compact_generate_ajax',
            'wrapper' => 'ai-image-studio-compact-wrapper',
            'progress' => [
              'type' => 'throbber',
              'message' => t('Generating image…'),
            ],
          ],
        ],
      ],
      '#prefix' => '<div id="ai-image-studio-compact-wrapper">',
      '#suffix' => '</div>',
    ];
    if ($ajax_url !== NULL) {
      $element['actions']['generate']['#ajax']['url'] = $ajax_url;
      $element['actions']['generate']['#ajax']['options']['query'] = [
        FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
      ];
    }

    if ($turn !== NULL && !$turn->get('image')->isEmpty()) {
      $file = $turn->get('image')->entity;
      if ($file instanceof FileInterface) {
        $element['preview'] = [
          '#type' => 'container',
          '#weight' => 10,
          '#attributes' => ['class' => ['ai-image-studio-compact__preview']],
          'image' => [
            '#theme' => 'image',
            '#uri' => $file->getFileUri(),
            '#alt' => (string) $turn->get('prompt')->value,
          ],
          'alt' => [
            '#type' => 'textfield',
            '#title' => t('Alternative text'),
            '#maxlength' => 512,
            '#required' => $settings->get('require_image_alt') !== FALSE,
            '#default_value' => mb_substr(
              (string) $turn->get('prompt')->value,
              0,
              255,
            ),
          ],
          'name' => [
            '#type' => 'textfield',
            '#title' => t('Media name'),
            '#maxlength' => 255,
            '#required' => TRUE,
            '#default_value' => mb_substr(
              (string) $turn->get('prompt')->value,
              0,
              255,
            ),
          ],
          'actions' => [
            '#type' => 'actions',
            'continue' => [
              '#type' => 'link',
              '#title' => t('Continue in full Studio'),
              '#url' => Url::fromRoute(
                'entity.ai_image_studio_session.canonical',
                ['ai_image_studio_session' => $turn->get('session_id')->target_id],
              ),
              '#attributes' => [
                'class' => ['button'],
                'target' => '_blank',
                'rel' => 'noopener',
              ],
            ],
          ],
        ];
        if ($this->currentUser->hasPermission('publish ai image studio image')) {
          $element['preview']['actions']['publish'] = [
            '#type' => 'submit',
            '#value' => $is_media_library
              ? t('Save and select')
              : t('Save to Media Library'),
            '#button_type' => 'primary',
            '#weight' => -10,
            '#name' => 'ai_image_studio_compact_publish',
            '#studio_compact_action' => 'publish',
            '#submit' => ['ai_image_studio_compact_publish_submit'],
            '#limit_validation_errors' => [
              ['ai_image_studio_compact', 'preview'],
            ],
          ];
        }
        if ($is_media_library
          && isset($element['preview']['actions']['publish'])) {
          $element['preview']['actions']['publish']['#ajax'] = [
            'callback' => 'ai_image_studio_compact_publish_ajax',
            'wrapper' => 'media-library-add-form-wrapper',
            'progress' => [
              'type' => 'throbber',
              'message' => t('Saving image…'),
            ],
            'url' => $ajax_url,
            'options' => [
              'query' => [
                FormBuilderInterface::AJAX_FORM_REQUEST => TRUE,
              ],
            ],
          ];
        }
      }
    }

    return $element;
  }

  /**
   * Builds the optional managed prompt without failing on missing config.
   */
  private function promptElement(FormStateInterface $form_state): array {
    if (!$this->promptResolver->promptTypeExists()) {
      return [
        '#type' => 'select',
        '#title' => t('After prompt'),
        '#options' => [],
        '#empty_option' => t('- Prompt library unavailable -'),
        '#disabled' => TRUE,
        '#description' => t('The reusable AI Image Studio prompt type is missing from active configuration. The start prompt can still be used.'),
      ];
    }
    return [
      '#type' => 'ai_prompt',
      '#title' => t('After prompt'),
      '#description' => t('Optionally append reusable render-quality or finishing instructions.'),
      '#prompt_types' => [PromptResolver::PROMPT_TYPE],
      '#required' => FALSE,
      '#default_value' => $form_state->getValue([
        'ai_image_studio_compact',
        'prompt',
      ]) ?: '',
    ];
  }

  /**
   * Builds the optional managed visual style prompt.
   */
  private function stylePromptElement(FormStateInterface $form_state): array {
    if (!$this->promptResolver->promptTypeExists(PromptResolver::STYLE_PROMPT_TYPE)) {
      return [
        '#type' => 'select',
        '#title' => t('Style'),
        '#options' => [],
        '#empty_option' => t('- Style library unavailable -'),
        '#disabled' => TRUE,
      ];
    }
    return [
      '#type' => 'ai_prompt',
      '#title' => t('Style'),
      '#description' => t('Optionally apply a reusable visual style.'),
      '#prompt_types' => [PromptResolver::STYLE_PROMPT_TYPE],
      '#required' => FALSE,
      '#default_value' => $form_state->getValue([
        'ai_image_studio_compact',
        'style_prompt',
      ]) ?: '',
    ];
  }

  /**
   * Generates a Studio turn from the compact form.
   */
  public function generate(array &$form, FormStateInterface $form_state): void {
    $values = (array) $form_state->getValue('ai_image_studio_compact');
    $prompt = $this->promptResolver->compose(
      $values['prompt_start'] ?? '',
      $values['prompt'] ?? '',
      $values['style_prompt'] ?? '',
    );
    $model = (string) ($values['model'] ?? '');
    if ($prompt === '') {
      $form_state->setErrorByName(
        'ai_image_studio_compact][prompt_start',
        t('Enter a start prompt or select a style or after prompt.'),
      );
      return;
    }
    if ($model === '') {
      return;
    }
    $maximum = (int) ($this->configFactory
      ->get('ai_image_studio.settings')
      ->get('max_prompt_length') ?: 4000);
    if (mb_strlen($prompt) > $maximum) {
      $form_state->setErrorByName(
        'ai_image_studio_compact][prompt',
        t('The selected prompt exceeds the maximum length of @count characters.', [
          '@count' => $maximum,
        ]),
      );
      return;
    }

    $session = $this->entityTypeManager
      ->getStorage('ai_image_studio_session')
      ->create([
        'title' => mb_substr($prompt, 0, 255),
        'uid' => $this->currentUser->id(),
        'status' => 'active',
      ]);
    $session->save();
    $generation = (array) ($values['settings'] ?? []);
    $generation['prompt'] = $prompt;
    $turn = $this->generator->generate(
      $session,
      $prompt,
      $model,
      NULL,
      NULL,
      $generation,
    );
    if ($turn->get('status')->value === 'completed') {
      $form_state->set('ai_image_studio_compact_turn', (int) $turn->id());
    }
    else {
      $form_state->setErrorByName(
        'ai_image_studio_compact][prompt',
        t('Image generation failed: @message', [
          '@message' => (string) $turn->get('error_message')->value,
        ]),
      );
    }
    $form_state->setRebuild();
  }

  /**
   * Publishes the generated turn as Media.
   */
  public function publish(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser->hasPermission('publish ai image studio image')) {
      $form_state->setErrorByName(
        'ai_image_studio_compact',
        t('You are not allowed to publish Studio images.'),
      );
      return;
    }
    $turn = $this->loadTurn($form_state);
    if ($turn === NULL) {
      $form_state->setErrorByName(
        'ai_image_studio_compact',
        t('Generate an image before saving it.'),
      );
      return;
    }
    $session = $turn->get('session_id')->entity;
    if ($session === NULL || !$session->access('update')) {
      $form_state->setErrorByName(
        'ai_image_studio_compact',
        t('The generated image is unavailable.'),
      );
      return;
    }
    $values = (array) $form_state->getValue([
      'ai_image_studio_compact',
      'preview',
    ]);
    $media = $this->generator->publish(
      $turn,
      trim((string) ($values['name'] ?? 'Generated image')),
      trim((string) ($values['alt'] ?? '')),
    );
    $form_state->set('media', [$media]);
    if (!$form_state->get('media_library_state')) {
      $form_state->setRedirect('entity.media.canonical', ['media' => $media->id()]);
    }
  }

  /**
   * Returns the normal Media Library selection response after publishing.
   */
  public function publishAjax(array &$form, FormStateInterface $form_state): mixed {
    if ($form_state::hasAnyErrors()) {
      return $form;
    }
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof AddFormBase) {
      return $form_object->updateLibrary($form, $form_state);
    }
    return $form;
  }

  /**
   * Loads the generated turn stored for this form rebuild.
   */
  private function loadTurn(FormStateInterface $form_state): ?object {
    $turn_id = (int) $form_state->get('ai_image_studio_compact_turn');
    if ($turn_id === 0) {
      return NULL;
    }
    return $this->entityTypeManager
      ->getStorage('ai_image_studio_turn')
      ->load($turn_id);
  }

}
