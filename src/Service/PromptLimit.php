<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

/**
 * Resolves character limits by site policy, provider, model and operation.
 */
final class PromptLimit {

  /**
   * Returns the configured ceiling, reduced only by a known endpoint limit.
   */
  public static function resolve(int $configured, bool $xai, string $model, string $operation): int {
    $limit = $configured > 0 ? $configured : 4000;
    // Confirmed by this model's video API validation response. Do not infer
    // limits for other models from chat context windows or model-name prefixes.
    if ($xai && $model === 'grok-imagine-video' && self::isVideo($operation)) {
      $limit = min($limit, 4096);
    }
    return $limit;
  }

  /**
   * Applies the effective ceiling immediately before a provider submission.
   */
  public static function prepare(string $prompt, int $limit, string $operation): string {
    if (self::isVideo($operation)) {
      return VideoPromptBudget::fit($prompt, $limit);
    }
    if (mb_strlen($prompt) > $limit) {
      throw new \LengthException(sprintf('The assembled prompt exceeds the configured maximum of %d characters. Shorten the prompt or increase Maximum prompt length in Image Studio settings. No API request was sent; the original prompt is preserved.', $limit));
    }
    return $prompt;
  }

  /**
   * Identifies video generation operations, not chat or still image requests.
   */
  private static function isVideo(string $operation): bool {
    return in_array($operation, ['text_to_video', 'image_to_video', 'reference_to_video'], TRUE);
  }

}
