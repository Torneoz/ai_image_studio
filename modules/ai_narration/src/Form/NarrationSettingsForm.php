<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the default narration voice.
 */
final class NarrationSettingsForm extends ConfigFormBase {

  public function __construct(private readonly NarrationGenerator $generator) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('ai_narration.generator'));
  }

  public function getFormId(): string {
    return 'ai_narration_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['ai_narration.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = $this->generator->getModelOptions();
    $form['default_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Default voice'),
      '#options' => $options,
      '#empty_option' => $this->t('- No default -'),
      '#default_value' => $this->config('ai_narration.settings')->get('default_model'),
      '#description' => $this->t('This only chooses the preselected voice; users may choose any usable provider voice.'),
    ];
    $form['player_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Default audio player'),
      '#options' => [
        'native' => $this->t('Native browser player'),
        'enhanced' => $this->t('Enhanced narration player'),
      ],
      '#default_value' => $this->config('ai_narration.settings')->get('player_mode') ?: 'native',
      '#description' => $this->t('Users can override this choice on narration screens.'),
    ];
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_narration.settings')
      ->set('default_model', (string) $form_state->getValue('default_model'))
      ->set('player_mode', (string) $form_state->getValue('player_mode'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
