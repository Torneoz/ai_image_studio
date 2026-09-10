<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Applies the corporate badge requirement to request settings and controls.
 */
final class BadgePolicy {

  /**
   * Applies site defaults and prevents submitted values bypassing a badge lock.
   */
  public static function apply(array $settings, array $config, bool $video = FALSE): array {
    $defaults = [
      'show_ai_badge' => $config['default_show_ai_badge'] ?? TRUE,
      'ai_badge_text' => trim((string) ($config[$video ? 'default_video_ai_badge_text' : 'default_ai_badge_text'] ?? '')) ?: ($video ? 'AI Video' : 'AI Image'),
      'ai_badge_position' => $config['default_ai_badge_position'] ?? 'bottom-right',
      'ai_badge_class' => $config['default_ai_badge_class'] ?? '',
    ];
    if (!empty($config['require_ai_badges'])) {
      $defaults['show_ai_badge'] = TRUE;
      return array_replace($settings, $defaults);
    }
    return $settings + $defaults;
  }

  /**
   * Builds a compact badge fieldset for suite request forms.
   */
  public static function requestControls(array $config): array {
    $settings = self::apply([], $config);
    return self::lockControls([
      '#type' => 'details',
      '#title' => new TranslatableMarkup('Badges'),
      '#tree' => TRUE,
      '#open' => FALSE,
      'show_ai_badge' => [
        '#type' => 'checkbox',
        '#title' => new TranslatableMarkup('Show an AI badge'),
        '#default_value' => (bool) $settings['show_ai_badge'],
      ],
    ], !empty($config['require_ai_badges']));
  }

  /**
   * Keeps badge controls visible while preventing request-level changes.
   */
  public static function lockControls(array $element, bool $locked): array {
    if (!$locked) {
      return $element;
    }
    $element['#description'] = new TranslatableMarkup('Badge removal is not possible');
    foreach ($element as $key => &$child) {
      if (is_string($key) && !str_starts_with($key, '#') && is_array($child)) {
        $child['#disabled'] = TRUE;
        // Disabled values remain visible even after changing the source turn.
        unset($child['#states']);
      }
    }
    return $element;
  }

}
