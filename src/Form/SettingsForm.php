<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Form;

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

    $bundle = (string) $form_state->getValue('media_bundle');
    $field = (string) $form_state->getValue('media_source_field');
    $definitions = $this->fieldManager->getFieldDefinitions('media', $bundle);
    if (!isset($definitions[$field]) || $definitions[$field]->getType() !== 'image') {
      $form_state->setErrorByName('media_source_field', $this->t('The selected Media type must contain this image field.'));
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
      ->set('media_bundle', $form_state->getValue('media_bundle'))
      ->set('media_source_field', $form_state->getValue('media_source_field'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
