<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Plugin\Field\FieldFormatter;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders narration files with the enhanced waveform player.
 */
#[FieldFormatter(
  id: 'ai_narration_enhanced_audio_player',
  label: new TranslatableMarkup('AI Narration enhanced audio player'),
  field_types: ['entity_reference'],
)]
final class NarrationEnhancedAudioPlayerFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  public function __construct(string $plugin_id, mixed $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, string $label, string $view_mode, array $third_party_settings, private readonly FileUrlGeneratorInterface $fileUrlGenerator, private readonly ConfigFactoryInterface $configFactory) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('file_url_generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [
      '#attached' => [
        'library' => ['ai_narration/enhanced_player'],
        'drupalSettings' => ['aiNarration' => [
          'playerModeDefault' => 'enhanced',
          'playerAccentColour' => $this->configFactory->get('ai_narration.settings')->get('player_accent_colour') ?: '#24c7b9',
          'playerColourMode' => $this->configFactory->get('ai_narration.settings')->get('player_colour_mode') ?: 'default',
          'playerWaveformColour' => $this->configFactory->get('ai_narration.settings')->get('player_waveform_colour') ?: '#74fff0',
          'playerUnplayedWaveformColour' => $this->configFactory->get('ai_narration.settings')->get('player_unplayed_waveform_colour') ?: '#4c898a',
          'playerSpeedControlColour' => $this->configFactory->get('ai_narration.settings')->get('player_speed_control_colour') ?: '#e9fffc',
          'playerControlBackgroundColour' => $this->configFactory->get('ai_narration.settings')->get('player_control_background_colour') ?: '#0c2527',
          'playerBackgroundColour' => $this->configFactory->get('ai_narration.settings')->get('player_background_colour') ?: '#102b2d',
          'playerTextColour' => $this->configFactory->get('ai_narration.settings')->get('player_text_colour') ?: '#e9fffc',
          'playerHeight' => $this->configFactory->get('ai_narration.settings')->get('player_height') ?: '4rem',
          'playerDisplayStyle' => $this->configFactory->get('ai_narration.settings')->get('player_display_style') ?: 'waveform',
        ]],
      ],
    ];
    foreach ($items as $delta => $item) {
      $file = $item->entity;
      if (!$file) {
        continue;
      }
      $elements[$delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-narration-result', 'ai-narration-view-player']],
        'audio' => [
          '#type' => 'html_tag',
          '#tag' => 'audio',
          '#attributes' => [
            'controls' => TRUE,
            'preload' => 'metadata',
            'src' => $this->fileUrlGenerator->generateString($file->getFileUri()),
          ],
        ],
        '#cache' => ['tags' => $file->getCacheTags()],
      ];
    }
    return $elements;
  }

}
