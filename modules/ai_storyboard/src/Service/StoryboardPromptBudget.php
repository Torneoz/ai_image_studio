<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

/**
 * Keeps a generated storyboard-frame prompt within its project budget.
 */
final class StoryboardPromptBudget {

  /**
   * Adds prompt parts in priority order, compacting trailing context if needed.
   *
   * @param string[] $parts
   *   Prompt paragraphs ordered from most to least important.
   */
  public static function fit(array $parts, int $limit): string {
    $limit = $limit > 0 ? $limit : 4000;
    $prompt = '';
    foreach ($parts as $part) {
      $part = trim($part);
      if ($part === '') {
        continue;
      }
      $separator = $prompt === '' ? '' : "\n\n";
      if (mb_strlen($prompt . $separator . $part) <= $limit) {
        $prompt .= $separator . $part;
        continue;
      }

      $notice = "\n\n[Additional context omitted to fit the project prompt limit.]";
      $available = $limit - mb_strlen($prompt . $separator . $notice);
      if ($available > 0) {
        $prompt .= $separator . mb_substr($part, 0, $available) . $notice;
      }
      return $prompt;
    }
    return $prompt;
  }

}
