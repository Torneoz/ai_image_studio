<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

/**
 * Applies project/Studio policy without automatic provider ceilings.
 */
final class PromptLimit {

  /**
   * Returns the configured ceiling without reducing it for a provider/model.
   */
  public static function resolve(int $configured, bool $xai, string $model, string $operation): int {
    return $configured > 0 ? $configured : 4000;
  }

  /**
   * Applies the effective ceiling immediately before a provider submission.
   */
  public static function prepare(string $prompt, int $limit, string $operation, ?int $byte_limit = NULL): string {
    if (self::isVideo($operation)) {
      return VideoPromptBudget::fit($prompt, $limit, $byte_limit);
    }
    if (mb_strlen($prompt) > $limit) {
      throw new \LengthException(sprintf('The assembled prompt exceeds the configured maximum of %d characters. Shorten the prompt or increase Maximum prompt length in Image Studio settings. No API request was sent; the original prompt is preserved.', $limit));
    }
    return $prompt;
  }

  /**
   * Provider byte ceilings are deliberately disabled; APIs validate themselves.
   */
  public static function byteLimit(bool $xai, string $model, string $operation): ?int {
    return NULL;
  }

  /**
   * Identifies video generation operations, not chat or still image requests.
   */
  private static function isVideo(string $operation): bool {
    return in_array($operation, ['text_to_video', 'image_to_video', 'reference_to_video'], TRUE);
  }

}
