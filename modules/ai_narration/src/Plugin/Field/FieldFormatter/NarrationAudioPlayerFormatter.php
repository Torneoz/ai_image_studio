<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a generated narration file as an HTML5 audio player.
 */
#[FieldFormatter(
  id: 'ai_narration_audio_player',
  label: new TranslatableMarkup('AI Narration audio player'),
  field_types: ['entity_reference'],
)]
final class NarrationAudioPlayerFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  public function __construct(string $plugin_id, mixed $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, string $label, string $view_mode, array $third_party_settings, private readonly FileUrlGeneratorInterface $fileUrlGenerator) {
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      $file = $item->entity;
      if (!$file) {
        continue;
      }
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'audio',
        '#attributes' => [
          'controls' => TRUE,
          'preload' => 'metadata',
          'src' => $this->fileUrlGenerator->generateString($file->getFileUri()),
        ],
        '#cache' => ['tags' => $file->getCacheTags()],
      ];
    }
    return $elements;
  }

}
