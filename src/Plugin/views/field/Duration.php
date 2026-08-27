<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Displays a millisecond duration in a compact, readable form.
 */
#[ViewsField('ai_image_studio_duration')]
final class Duration extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): string {
    $value = $this->getValue($values);
    if ($value === NULL || $value === '') {
      return '';
    }

    $milliseconds = (int) $value;
    if ($milliseconds < 1000) {
      return $this->t('@duration ms', ['@duration' => $milliseconds])->render();
    }

    if ($milliseconds < 60000) {
      return $this->t('@duration s', [
        '@duration' => number_format($milliseconds / 1000, 2),
      ])->render();
    }

    $total_seconds = (int) round($milliseconds / 1000);
    $minutes = intdiv($total_seconds, 60);
    $seconds = $total_seconds % 60;
    return $this->t('@minutes min @seconds s', [
      '@minutes' => $minutes,
      '@seconds' => $seconds,
    ])->render();
  }

}
