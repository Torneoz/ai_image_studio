<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio_vbo\Plugin\Action;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_image_studio_vbo\Service\BulkGenerationManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
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
      'publish_media' => FALSE,
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
    $selected_bundle = (string) ($form_state->getValue('destination_bundle')
      ?: $configuration['destination_bundle']);
    $form['destination'] = [
      '#type' => 'details',
      '#title' => $this->t('Result destination'),
      '#open' => $selected_bundle !== '',
      '#prefix' => '<div id="ai-image-studio-vbo-destination">',
      '#suffix' => '</div>',
    ];
    $form['destination']['destination_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#options' => $bundle_options,
      '#empty_option' => $this->t('- Do not attach results to content -'),
      '#default_value' => $selected_bundle,
      '#ajax' => [
        'callback' => [static::class, 'updateDestinationFields'],
        'wrapper' => 'ai-image-studio-vbo-destination',
      ],
    ];
    $form['destination']['destination_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Destination field'),
      '#options' => $this->destinationFieldOptions($selected_bundle),
      '#empty_option' => $selected_bundle === ''
        ? $this->t('- Select a content type first -')
        : $this->t('- Select a destination field -'),
      '#default_value' => $configuration['destination_field'],
      '#required' => $selected_bundle !== '',
      '#description' => $this->t('Image fields receive the generated file. Media reference fields receive a newly published image Media item. Existing field values are replaced.'),
    ];
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
    $bundle = (string) $form_state->getValue('destination_bundle');
    $field = (string) $form_state->getValue('destination_field');
    if ($bundle !== '' && !isset($this->destinationFieldOptions($bundle)[$field])) {
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
    if (empty($this->configuration['destination_bundle'])) {
      $this->configuration['destination_field'] = '';
    }
    $this->configuration['variations'] = 1;
  }

  /**
   * Rebuilds the destination controls after selecting a content type.
   */
  public static function updateDestinationFields(array &$form): array {
    return $form['destination'];
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
