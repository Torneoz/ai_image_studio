<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Configures the default narration voice.
 */
final class NarrationSettingsForm extends ConfigFormBase {

  /** Resolves the service lazily so it also works on AJAX form rebuilds. */
  private function generator(): NarrationGenerator {
    return \Drupal::service('ai_narration.generator');
  }

  public function getFormId(): string {
    return 'ai_narration_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['ai_narration.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = $this->generator()->getModelOptions();
    $setting = function (string $name, mixed $fallback) use ($form_state): mixed {
      $value = $form_state->getValue($name);
      if ($value === NULL) {
        $value = $this->config('ai_narration.settings')->get($name);
      }
      return $value === NULL || $value === '' ? $fallback : $value;
    };
    $preview_ajax = [
      'callback' => '::refreshPlayerPreview',
      'wrapper' => 'ai-narration-player-settings-preview',
      'event' => 'change',
    ];
    $display_style = (string) ($form_state->getValue('player_display_style') ?? $this->config('ai_narration.settings')->get('player_display_style') ?: 'waveform');
    $colour_mode = (string) $setting('player_colour_mode', 'default');
    $accent_colour = (string) $setting('player_accent_colour', '#24c7b9');
    $waveform_colour = (string) $setting('player_waveform_colour', '#74fff0');
    $unplayed_waveform_colour = (string) $setting('player_unplayed_waveform_colour', '#4c898a');
    $background_colour = (string) $setting('player_background_colour', '#102b2d');
    $text_colour = (string) $setting('player_text_colour', '#e9fffc');
    $player_height = (string) $setting('player_height', '4rem');
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
      '#default_value' => $colour_mode,
      '#ajax' => $preview_ajax,
    ];
    $custom_state = [':input[name="player_colour_mode"]' => ['value' => 'custom']];
    $form['player_accent_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Accent colour'),
      '#default_value' => $accent_colour,
      '#description' => $this->t('Used for the player border, play control, speed control, and segment markers.'),
      '#states' => ['visible' => $custom_state],
      '#ajax' => $preview_ajax,
    ];
    $form['player_waveform_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Played waveform colour'),
      '#default_value' => $waveform_colour,
      '#states' => ['visible' => $custom_state],
      '#ajax' => $preview_ajax,
    ];
    $form['player_unplayed_waveform_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Unplayed waveform/bars colour'),
      '#default_value' => $unplayed_waveform_colour,
      '#states' => ['visible' => $custom_state],
      '#ajax' => $preview_ajax,
    ];
    $form['player_background_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Player background colour'),
      '#default_value' => $background_colour,
      '#states' => ['visible' => $custom_state],
      '#ajax' => $preview_ajax,
    ];
    $form['player_text_colour'] = [
      '#type' => 'color',
      '#title' => $this->t('Player text colour'),
      '#default_value' => $text_colour,
      '#states' => ['visible' => $custom_state],
      '#ajax' => $preview_ajax,
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
      '#default_value' => $player_height,
      '#ajax' => $preview_ajax,
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
      '#ajax' => $preview_ajax,
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
          '#attributes' => [
            'controls' => TRUE,
            'preload' => 'metadata',
            'src' => $test_audio_url,
            'data-ai-narration-display-style' => $display_style,
            'data-ai-narration-player-colour-mode' => $colour_mode,
            'data-ai-narration-player-accent-colour' => $accent_colour,
            'data-ai-narration-player-waveform-colour' => $waveform_colour,
            'data-ai-narration-player-unplayed-waveform-colour' => $unplayed_waveform_colour,
            'data-ai-narration-player-background-colour' => $background_colour,
            'data-ai-narration-player-text-colour' => $text_colour,
            'data-ai-narration-player-height' => $player_height,
            'data-ai-narration-preview-position' => '0.45',
          ],
        ],
      ],
      '#weight' => 20,
    ];
    $player_settings = [
      'playerModeDefault' => 'enhanced',
      'playerAccentColour' => $accent_colour,
      'playerColourMode' => $colour_mode,
      'playerWaveformColour' => $waveform_colour,
      'playerUnplayedWaveformColour' => $unplayed_waveform_colour,
      'playerBackgroundColour' => $background_colour,
      'playerTextColour' => $text_colour,
      'playerHeight' => $player_height,
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
      ->set('player_unplayed_waveform_colour', (string) $form_state->getValue('player_unplayed_waveform_colour'))
      ->set('player_background_colour', (string) $form_state->getValue('player_background_colour'))
      ->set('player_text_colour', (string) $form_state->getValue('player_text_colour'))
      ->set('player_height', (string) $form_state->getValue('player_height'))
      ->set('player_display_style', (string) $form_state->getValue('player_display_style'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
