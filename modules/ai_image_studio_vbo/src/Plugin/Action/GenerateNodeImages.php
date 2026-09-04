<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio_vbo\Plugin\Action;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_image_studio_vbo\Service\BulkGenerationManager;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Queues image generation for nodes selected in a View.
 */
#[Action(
  id: 'ai_image_studio_generate_node_images',
  label: new TranslatableMarkup('Generate images with AI Image Studio'),
  type: 'node',
)]
final class GenerateNodeImages extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface, PluginFormInterface {

  /**
   * Constructs the VBO action.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly BulkGenerationManager $bulkManager,
    private readonly ImageGenerator $generator,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_image_studio_vbo.batch_manager'),
      $container->get('ai_image_studio.generator'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'prompt_id' => 'ai_image_studio_vbo__editorial_image',
      'source_field' => '',
      'destination_bundle' => '',
      'destination_field' => '',
      'text_model' => '',
      'image_model' => '',
      'aspect_ratio' => 'auto',
      'resolution' => 'auto',
      'quality' => 'medium',
      'file_type' => 'png',
      'show_ai_badge' => (bool) $this->configFactory
        ->get('ai_image_studio.settings')
        ->get('default_show_ai_badge'),
      'ai_badge_text' => (string) ($this->configFactory
        ->get('ai_image_studio.settings')
        ->get('default_ai_badge_text') ?: 'AI Image'),
      'ai_badge_position' => (string) ($this->configFactory
        ->get('ai_image_studio.settings')
        ->get('default_ai_badge_position') ?: 'bottom-right'),
      'publish_media' => FALSE,
      'attach_to_content' => FALSE,
      'media_name_template' => '[node:title] AI image',
      'alt_template' => 'AI-generated illustration for [node:title]',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $configuration = $this->configuration + $this->defaultConfiguration();
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('One image request will be queued for each selected node. Drupal tokens such as [node:title] may be used in text fields.') . '</p>',
    ];
    $prompt_type = $this->configFactory
      ->get('ai.ai_prompt_type.ai_image_studio_vbo');
    $form['prompt_id'] = $prompt_type->isNew()
      ? [
        '#type' => 'select',
        '#title' => $this->t('Prompt'),
        '#options' => [],
        '#empty_option' => $this->t('- Prompt library unavailable -'),
        '#disabled' => TRUE,
        '#description' => $this->t('The AI Image Studio bulk prompt type is missing from active configuration.'),
      ]
      : [
        '#type' => 'ai_prompt',
        '#title' => $this->t('Prompt'),
        '#prompt_types' => ['ai_image_studio_vbo'],
        '#default_value' => $configuration['prompt_id'],
        '#required' => TRUE,
      ];
    $form['source_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Optional source image field'),
      '#default_value' => $configuration['source_field'],
      '#description' => $this->t('Enter a node image, file, or Media reference field machine name. Nodes without a usable image fall back to text-to-image.'),
    ];
    $bundles = $this->bundleInfo->getBundleInfo('node');
    $bundle_options = [];
    foreach ($bundles as $bundle_id => $bundle) {
      $bundle_options[$bundle_id] = $bundle['label'];
    }
    asort($bundle_options);
    $selected_bundle = (string) ($configuration['destination_bundle'] ?: $this->selectedNodeBundle());
    $triggering_element = $form_state->getTriggeringElement();
    $triggering_parents = $triggering_element['#parents'] ?? [];
    if (end($triggering_parents) === 'destination_bundle') {
      // VBO embeds action configuration in a subform. Reading the triggering
      // element directly works both there and when the plugin form is built
      // on its own.
      $selected_bundle = (string) ($triggering_element['#value'] ?? '');
    }
    elseif ($form_state->getValue('destination_bundle') !== NULL) {
      $selected_bundle = (string) $form_state->getValue('destination_bundle');
    }
    $destination_options = $this->destinationFieldOptions($selected_bundle);
    $selected_field = isset($destination_options[$configuration['destination_field']])
      ? $configuration['destination_field']
      : '';
    $attach_to_content = array_key_exists('attach_to_content', $this->configuration)
      ? (bool) $configuration['attach_to_content']
      : $selected_field !== '';
    $form['text_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Text-to-image model'),
      '#options' => $this->generator->getModelOptions('text_to_image'),
      '#default_value' => $configuration['text_model'] ?: $this->generator->getDefaultModel('text_to_image'),
      '#required' => TRUE,
    ];
    $form['image_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Image-to-image model'),
      '#options' => $this->generator->getModelOptions('image_to_image'),
      '#default_value' => $configuration['image_model'] ?: $this->generator->getDefaultModel('image_to_image'),
      '#empty_option' => $this->t('- Use text-to-image instead -'),
      '#description' => $this->t('Used only when the configured source field contains an image.'),
    ];
    $form['image_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Image settings'),
      '#open' => FALSE,
    ];
    $form['image_settings']['aspect_ratio'] = [
      '#type' => 'select',
      '#title' => $this->t('Aspect ratio'),
      // Machine-readable ratios do not require translation.
      // phpcs:disable DrupalPractice.General.OptionsT.TforValue
      '#options' => [
        'auto' => $this->t('Automatic'),
        '1:1' => '1:1',
        '3:2' => '3:2',
        '2:3' => '2:3',
        '16:9' => '16:9',
        '9:16' => '9:16',
      ],
      // phpcs:enable DrupalPractice.General.OptionsT.TforValue
      '#default_value' => $configuration['aspect_ratio'],
    ];
    $form['image_settings']['resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Resolution'),
      // Pixel dimensions do not require translation.
      // phpcs:disable DrupalPractice.General.OptionsT.TforValue
      '#options' => [
        'auto' => $this->t('Automatic'),
        '1024x1024' => '1024×1024',
        '1536x1024' => '1536×1024',
        '1024x1536' => '1024×1536',
      ],
      // phpcs:enable DrupalPractice.General.OptionsT.TforValue
      '#default_value' => $configuration['resolution'],
    ];
    $form['image_settings']['quality'] = [
      '#type' => 'select',
      '#title' => $this->t('Quality'),
      '#options' => [
        'auto' => $this->t('Automatic'),
        'low' => $this->t('Low'),
        'medium' => $this->t('Medium'),
        'high' => $this->t('High'),
      ],
      '#default_value' => $configuration['quality'],
    ];
    $form['image_settings']['file_type'] = [
      '#type' => 'select',
      '#title' => $this->t('File type'),
      // File format abbreviations do not require translation.
      // phpcs:disable DrupalPractice.General.OptionsT.TforValue
      '#options' => ['png' => 'PNG', 'jpeg' => 'JPEG', 'webp' => 'WebP'],
      // phpcs:enable DrupalPractice.General.OptionsT.TforValue
      '#default_value' => $configuration['file_type'],
    ];
    $can_render_badge = $this->generator->canRenderBadge(FALSE);
    $form['image_settings']['show_ai_badge'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Render an AI badge into saved Media files'),
      '#default_value' => $can_render_badge && $configuration['show_ai_badge'],
      '#disabled' => !$can_render_badge,
      '#description' => $can_render_badge
        ? $this->t('Creates a separate Media file with the badge permanently embedded. The original Studio result is preserved.')
        : $this->t('Badge rendering is unavailable because PHP GD is not available to the web server.'),
    ];
    $form['image_settings']['ai_badge_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Badge text'),
      '#default_value' => $configuration['ai_badge_text'],
      '#maxlength' => 80,
      '#states' => [
        'visible' => [
          ':input[name="show_ai_badge"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['image_settings']['ai_badge_position'] = [
      '#type' => 'select',
      '#title' => $this->t('Badge position'),
      '#options' => [
        'top-left' => $this->t('Top left'),
        'top-right' => $this->t('Top right'),
        'bottom-left' => $this->t('Bottom left'),
        'bottom-right' => $this->t('Bottom right'),
      ],
      '#default_value' => $configuration['ai_badge_position'],
      '#states' => [
        'visible' => [
          ':input[name="show_ai_badge"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['publishing'] = [
      '#type' => 'details',
      '#title' => $this->t('Media publishing'),
      '#open' => FALSE,
    ];
    $form['publishing']['publish_media'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish completed images to Media automatically'),
      '#default_value' => $configuration['publish_media'],
      '#access' => $this->currentUser->hasPermission('publish ai image studio image'),
    ];
    $form['publishing']['destination'] = [
      '#type' => 'details',
      '#title' => $this->t('Save to content'),
      '#open' => $selected_bundle !== '',
      '#prefix' => '<div id="ai-image-studio-vbo-destination">',
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [':input[name="publish_media"]' => ['checked' => TRUE]],
      ],
    ];
    $form['publishing']['destination']['attach_to_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Save the published Media to content'),
      '#default_value' => $attach_to_content,
      '#description' => $this->t('Assign the new Media item to a field on each selected content item. Leave unchecked to publish Media without changing content.'),
    ];
    $form['publishing']['destination']['destination_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#options' => $bundle_options,
      '#empty_option' => $this->t('- Do not attach Media to content -'),
      '#default_value' => $selected_bundle,
      '#states' => [
        'visible' => [':input[name="attach_to_content"]' => ['checked' => TRUE]],
      ],
      '#ajax' => [
        'callback' => [static::class, 'updateDestinationFields'],
        'wrapper' => 'ai-image-studio-vbo-destination',
      ],
    ];
    $form['publishing']['destination']['destination_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Destination field'),
      '#options' => $destination_options,
      '#empty_option' => $selected_bundle === ''
        ? $this->t('- Select a content type first -')
        : $this->t('- Do not attach Media to content -'),
      '#default_value' => $selected_field,
      '#description' => $this->t('Optionally assign the published Media item to a compatible field. An existing field value will be replaced.'),
      '#states' => [
        'visible' => [':input[name="attach_to_content"]' => ['checked' => TRUE]],
      ],
    ];
    $form['publishing']['media_name_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Media name template'),
      '#default_value' => $configuration['media_name_template'],
      '#states' => [
        'visible' => [':input[name="publish_media"]' => ['checked' => TRUE]],
      ],
    ];
    $form['publishing']['alt_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Alternative text template'),
      '#default_value' => $configuration['alt_template'],
      '#states' => [
        'visible' => [':input[name="publish_media"]' => ['checked' => TRUE]],
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $prompt_id = (string) $form_state->getValue('prompt_id');
    $prompt = $this->configFactory->get('ai.ai_prompt.' . $prompt_id);
    if ($prompt_id === '' || $prompt->isNew() || trim((string) $prompt->get('prompt')) === '') {
      $form_state->setErrorByName('prompt_id', $this->t('Select a valid prompt.'));
    }
    elseif ((string) $prompt->get('type') !== 'ai_image_studio_vbo') {
      $form_state->setErrorByName('prompt_id', $this->t('Select an AI Image Studio bulk image prompt.'));
    }
    if ($form_state->getValue('publish_media')
      && !$this->currentUser->hasPermission('publish ai image studio image')) {
      $form_state->setErrorByName('publish_media', $this->t('You do not have permission to publish generated images.'));
    }
    $attach_to_content = $form_state->getValue('publish_media')
      && $form_state->getValue('attach_to_content');
    $bundle = $attach_to_content ? (string) $form_state->getValue('destination_bundle') : '';
    $field = $attach_to_content ? (string) $form_state->getValue('destination_field') : '';
    if ($attach_to_content && ($bundle === '' || $field === '')) {
      $form_state->setErrorByName('destination_field', $this->t('Select a content type and destination field, or disable saving Media to content.'));
    }
    elseif ($field !== '' && !isset($this->destinationFieldOptions($bundle)[$field])) {
      $form_state->setErrorByName('destination_field', $this->t('Select a compatible destination field from the chosen content type.'));
    }
    if ($bundle !== '' && $field !== '') {
      $definition = $this->entityFieldManager
        ->getFieldDefinitions('node', $bundle)[$field] ?? NULL;
      if ($definition?->getType() === 'entity_reference'
        && !$this->currentUser->hasPermission('publish ai image studio image')) {
        $form_state->setErrorByName('destination_field', $this->t('You do not have permission to publish generated images to a Media reference field.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    $prompt_id = (string) $this->configuration['prompt_id'];
    $prompt = $this->configFactory->get('ai.ai_prompt.' . $prompt_id);
    $this->configuration['run_uuid'] = bin2hex(random_bytes(16));
    $this->configuration['initiating_uid'] = (int) $this->currentUser->id();
    $this->configuration['prompt_template'] = trim((string) $prompt->get('prompt'));
    $this->configuration['source_field'] = trim((string) $this->configuration['source_field']);
    $this->configuration['show_ai_badge'] = $this->generator->canRenderBadge(FALSE)
      && !empty($this->configuration['show_ai_badge']);
    $this->configuration['ai_badge_text'] = trim((string) $this->configuration['ai_badge_text']) ?: 'AI Image';
    if (!in_array($this->configuration['ai_badge_position'], ['top-left', 'top-right', 'bottom-left', 'bottom-right'], TRUE)) {
      $this->configuration['ai_badge_position'] = 'bottom-right';
    }
    if (empty($this->configuration['publish_media'])
      || empty($this->configuration['attach_to_content'])) {
      $this->configuration['destination_bundle'] = '';
      $this->configuration['destination_field'] = '';
    }
    elseif (empty($this->configuration['destination_bundle'])) {
      $this->configuration['destination_field'] = '';
    }
    $this->configuration['variations'] = 1;
  }

  /**
   * Rebuilds the destination controls after selecting a content type.
   */
  public static function updateDestinationFields(array &$form, FormStateInterface $form_state): array {
    $triggering_element = $form_state->getTriggeringElement();
    $array_parents = $triggering_element['#array_parents'] ?? [];
    array_pop($array_parents);
    $destination = NestedArray::getValue($form, $array_parents, $exists);
    if ($exists && is_array($destination)) {
      return $destination;
    }

    // Retain support for contexts that build the plugin form at the root.
    return $form['publishing']['destination'];
  }

  /**
   * Returns writable image and Media reference fields for a node bundle.
   */
  private function destinationFieldOptions(string $bundle): array {
    if ($bundle === '') {
      return [];
    }
    $options = [];
    foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $field_name => $definition) {
      if ($definition->isComputed() || $definition->isReadOnly()) {
        continue;
      }
      $type = $definition->getType();
      $is_media_reference = $type === 'entity_reference'
        && $definition->getSetting('target_type') === 'media';
      if ($is_media_reference) {
        $target_bundles = (array) ($definition->getSetting('handler_settings')['target_bundles'] ?? []);
        $is_media_reference = $target_bundles === []
          || isset($target_bundles['image']);
      }
      if ($type === 'image' || $is_media_reference) {
        $options[$field_name] = $this->t('@label (@name)', [
          '@label' => $definition->getLabel(),
          '@name' => $field_name,
        ]);
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Infers a common node bundle from the current VBO selection.
   */
  private function selectedNodeBundle(): string {
    $bundle = '';
    foreach ($this->context['list'] ?? [] as $item) {
      if (!is_array($item) || ($item[2] ?? '') !== 'node') {
        return '';
      }
      $node = $this->entityTypeManager->getStorage('node')->load($item[0] ?? NULL);
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if ($bundle !== '' && $bundle !== $node->bundle()) {
        return '';
      }
      $bundle = $node->bundle();
    }
    return $bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?EntityInterface $entity = NULL): TranslatableMarkup|array {
    if (!$entity instanceof NodeInterface) {
      return ['message' => $this->t('Skipped a non-node result.'), 'type' => 'warning'];
    }
    try {
      $item_id = $this->bulkManager->enqueue($entity, $this->configuration);
      return [
        'message' => $this->t('Queued an image for @label.', [
          '@label' => $entity->label(),
        ]),
        'job_id' => $this->bulkManager->jobIdForItem($item_id),
      ];
    }
    catch (\Throwable $exception) {
      return [
        'message' => $this->t('Could not queue @label: @message', [
          '@label' => $entity->label(),
          '@message' => $exception->getMessage(),
        ]),
        'type' => 'error',
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function finished($success, array $results, array $operations): ?RedirectResponse {
    parent::finished($success, $results, $operations);
    if (!$success) {
      return NULL;
    }
    foreach ($results['operations'] ?? [] as $result) {
      $job_id = (int) ($result['job_id'] ?? 0);
      if ($job_id > 0) {
        return new RedirectResponse(Url::fromRoute(
          'ai_image_studio_vbo.job',
          ['job_id' => $job_id],
        )->toString());
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= $this->currentUser;
    $access = $account->hasPermission('run ai image studio vbo generation')
      && $object instanceof NodeInterface
      && $object->access('view', $account);
    return $return_as_object
      ? AccessResult::allowedIf($access)
      : $access;
  }

}
