<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
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
    $display_style = (string) ($form_state->getValue('player_display_style') ?? $this->config('ai_narration.settings')->get('player_display_style') ?: 'waveform');
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
    $form['player_colour_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Enhanced player colours'),
      '#options' => [
        'default' => $this->t('Default colours'),
        'custom' => $this->t('Custom colours'),
      ],
      '#default_value' => $this->config('ai_narration.settings')->get('player_colour_mode') ?: 'default',
    ];
    $custom_state = [':input[name="player_colour_mode"]' => ['value' => 'custom']];
    $form['player_accent_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Accent colour'),
      '#default_value' => $this->config('ai_narration.settings')->get('player_accent_colour') ?: '#24c7b9',
      '#description' => $this->t('Used for the player border, play control, speed control, and segment markers.'),
      '#states' => ['visible' => $custom_state],
    ];
    $form['player_waveform_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Played waveform colour'),
      '#default_value' => $this->config('ai_narration.settings')->get('player_waveform_colour') ?: '#74fff0',
      '#states' => ['visible' => $custom_state],
    ];
    $form['player_background_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Player background colour'),
      '#default_value' => $this->config('ai_narration.settings')->get('player_background_colour') ?: '#102b2d',
      '#states' => ['visible' => $custom_state],
    ];
    $form['player_text_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Player text colour'),
      '#default_value' => $this->config('ai_narration.settings')->get('player_text_colour') ?: '#e9fffc',
      '#states' => ['visible' => $custom_state],
    ];
    $form['player_height'] = [
      '#type' => 'select',
      '#title' => $this->t('Enhanced player height'),
      '#options' => [
        '4.5rem' => $this->t('4.5 rem'),
        '4rem' => $this->t('4 rem'),
        '3.5rem' => $this->t('3.5 rem'),
        '3rem' => $this->t('3 rem'),
        '2.5rem' => $this->t('2.5 rem'),
        '2rem' => $this->t('2 rem'),
        '1.5rem' => $this->t('1.5 rem'),
      ],
      '#default_value' => $this->config('ai_narration.settings')->get('player_height') ?: '4rem',
    ];
    $form['player_display_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Enhanced player display'),
      '#options' => [
        'waveform' => $this->t('Actual waveform'),
        'bars' => $this->t('Generic bars'),
      ],
      '#default_value' => $display_style,
      '#description' => $this->t('Actual waveform uses the audio file’s amplitude data. Generic bars are a lightweight equalizer-style display.'),
      '#ajax' => [
        'callback' => '::refreshPlayerPreview',
        'wrapper' => 'ai-narration-player-settings-preview',
      ],
    ];
    $module_path = \Drupal::service('extension.list.module')->getPath('ai_narration');
    $test_audio_url = Url::fromUri('base:' . $module_path . '/css/ui-test-file.mp3')->toString();
    $form['player_preview'] = [
      '#type' => 'container',
      '#title' => $this->t('Player preview'),
      '#attributes' => ['id' => 'ai-narration-player-settings-preview', 'class' => ['ai-narration-result']],
      'audio_test' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-narration-result']],
        'audio' => [
          '#type' => 'html_tag',
          '#tag' => 'audio',
          '#attributes' => ['controls' => TRUE, 'preload' => 'metadata', 'src' => $test_audio_url],
        ],
      ],
      '#weight' => 20,
    ];
    $player_settings = [
      'playerModeDefault' => 'enhanced',
      'playerAccentColour' => $this->config('ai_narration.settings')->get('player_accent_colour') ?: '#24c7b9',
      'playerColourMode' => $this->config('ai_narration.settings')->get('player_colour_mode') ?: 'default',
      'playerWaveformColour' => $this->config('ai_narration.settings')->get('player_waveform_colour') ?: '#74fff0',
      'playerBackgroundColour' => $this->config('ai_narration.settings')->get('player_background_colour') ?: '#102b2d',
      'playerTextColour' => $this->config('ai_narration.settings')->get('player_text_colour') ?: '#e9fffc',
      'playerHeight' => $this->config('ai_narration.settings')->get('player_height') ?: '4rem',
      'playerDisplayStyle' => $display_style,
    ];
    $form['player_preview']['#attached'] = [
      'library' => ['ai_narration/enhanced_player'],
      'drupalSettings' => ['aiNarration' => $player_settings],
    ];
    return parent::buildForm($form, $form_state);
  }

  /** Refreshes the real player preview for the selected display style. */
  public function refreshPlayerPreview(array &$form, FormStateInterface $form_state): array {
    return $form['player_preview'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_narration.settings')
      ->set('default_model', (string) $form_state->getValue('default_model'))
      ->set('player_mode', (string) $form_state->getValue('player_mode'))
      ->set('player_accent_colour', (string) $form_state->getValue('player_accent_colour'))
      ->set('player_colour_mode', (string) $form_state->getValue('player_colour_mode'))
      ->set('player_waveform_colour', (string) $form_state->getValue('player_waveform_colour'))
      ->set('player_background_colour', (string) $form_state->getValue('player_background_colour'))
      ->set('player_text_colour', (string) $form_state->getValue('player_text_colour'))
      ->set('player_height', (string) $form_state->getValue('player_height'))
      ->set('player_display_style', (string) $form_state->getValue('player_display_style'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
