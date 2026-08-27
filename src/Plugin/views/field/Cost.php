<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Displays an estimated generation cost without losing small values.
 */
#[ViewsField('ai_image_studio_cost')]
final class Cost extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): string {
    $value = $this->getValue($values);
    if ($value === NULL || $value === '') {
      return '';
    }

    $cost = (float) $value;
    $precision = $cost !== 0.0 && abs($cost) < 0.01 ? 6 : 2;
    return '$' . number_format($cost, $precision, '.', '');
  }

}
