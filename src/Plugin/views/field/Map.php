<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Plugin\views\field;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Safely displays a serialized map as formatted JSON.
 */
#[ViewsField('ai_image_studio_map')]
final class Map extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): array {
    $value = $this->getValue($values);
    if ($value === NULL || $value === '') {
      return [];
    }

    if (is_string($value)) {
      $decoded = @unserialize($value, ['allowed_classes' => FALSE]);
      $value = $decoded === FALSE && $value !== serialize(FALSE) ? $value : $decoded;
    }

    $json = Json::encode($value);
    if (is_array($value) || is_object($value)) {
      $json = json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
      ) ?: $json;
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => Html::escape($json),
      '#attributes' => ['class' => ['ai-image-studio-views-map']],
    ];
  }

}
