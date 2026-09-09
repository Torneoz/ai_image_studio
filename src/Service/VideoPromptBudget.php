<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

/**
 * Fits assembled storyboard context without cutting essential direction.
 */
final class VideoPromptBudget {

  /**
   * Keeps core instructions verbatim and budgets complete continuity sentences.
   */
  public static function fit(string $prompt, int $limit = 4096, ?int $byte_limit = NULL): string {
    $fits = static fn (string $text): bool => mb_strlen($text) <= $limit
      && ($byte_limit === NULL || strlen($text) <= $byte_limit);
    if ($fits($prompt)) {
      return $prompt;
    }
    // Only these descriptive sections may be shortened. Dialogue, audio,
    // Camera, overrides, voices and free-form instructions survive.
    $optional = [
      'PROJECT CONTINUITY', 'PROJECT CHARACTERS', 'PINNED LOCATION BIBLE',
      'PINNED CHARACTER BIBLE', 'SCENE BEATS',
    ];
    $parts = preg_split('/\n\n(?=[A-Z][A-Z \/_-]{1,60}:)/u', $prompt);
    $result = $parts;
    $queues = [];
    $labels = [];
    foreach ($parts as $index => $part) {
      if (preg_match('/^([A-Z][A-Z \/_-]{1,60}):\s*(.*)$/su', $part, $match)
        && in_array($match[1], $optional, TRUE)) {
        $labels[$index] = $match[1];
        $queues[$index] = preg_split('/(?<=[.!?。！？])\s+|\n+/u', trim($match[2]), -1, PREG_SPLIT_NO_EMPTY);
        $result[$index] = '';
      }
    }
    $join = static fn (array $sections): string => implode("\n\n", array_filter($sections, static fn (string $section): bool => $section !== ''));
    if (!$fits($join($result))) {
      $description = $limit . ' characters' . ($byte_limit !== NULL ? ' / ' . $byte_limit . ' UTF-8 bytes' : '');
      throw new \LengthException(sprintf('Video prompt exceeds the limit of %s even without descriptive continuity context. Shorten the shot action, dialogue, audio, camera or custom video instructions. No API request was sent; the original prompt is preserved.', $description));
    }
    // Fairly share the remaining space across context sections. Never cut a
    // sentence mid-instruction; an oversized sentence is omitted in full.
    do {
      $remaining = FALSE;
      foreach ($queues as $index => &$sentences) {
        if (!$sentences) {
          continue;
        }
        $remaining = TRUE;
        $sentence = array_shift($sentences);
        $candidate = $result;
        $candidate[$index] = $result[$index] === ''
          ? $labels[$index] . ': ' . $sentence
          : $result[$index] . ' ' . $sentence;
        if ($fits($join($candidate))) {
          $result = $candidate;
        }
      }
      unset($sentences);
    } while ($remaining);
    return $join($result);
  }

}
