<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Service;

/**
 * Splits narration text into provider-safe requests without losing text.
 */
final class NarrationTextChunker {

  /**
   * Splits at paragraph or sentence boundaries, then words as a last resort.
   *
   * @return string[]
   *   Non-empty chunks, each no longer than $maximumCharacters.
   */
  public function split(string $text, int $maximumCharacters): array {
    if ($maximumCharacters < 100) {
      throw new \InvalidArgumentException('The maximum narration request length must be at least 100 characters.');
    }
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
      return [];
    }
    $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $chunks = [];
    $current = '';
    foreach ($sentences as $sentence) {
      foreach ($this->splitLongSegment(trim($sentence), $maximumCharacters) as $segment) {
        $candidate = $current === '' ? $segment : $current . ' ' . $segment;
        if (mb_strlen($candidate) <= $maximumCharacters) {
          $current = $candidate;
          continue;
        }
        if ($current !== '') {
          $chunks[] = $current;
        }
        $current = $segment;
      }
    }
    if ($current !== '') {
      $chunks[] = $current;
    }
    return $chunks;
  }

  /**
   * Breaks one overlong sentence on words, preserving every character.
   *
   * @return string[]
   *   Safe segments.
   */
  private function splitLongSegment(string $segment, int $maximumCharacters): array {
    if (mb_strlen($segment) <= $maximumCharacters) {
      return [$segment];
    }
    $words = preg_split('/\s+/u', $segment, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $segments = [];
    $current = '';
    foreach ($words as $word) {
      // A single token can exceed a provider limit; split it deterministically.
      while (mb_strlen($word) > $maximumCharacters) {
        if ($current !== '') {
          $segments[] = $current;
          $current = '';
        }
        $segments[] = mb_substr($word, 0, $maximumCharacters);
        $word = mb_substr($word, $maximumCharacters);
      }
      $candidate = $current === '' ? $word : $current . ' ' . $word;
      if (mb_strlen($candidate) > $maximumCharacters && $current !== '') {
        $segments[] = $current;
        $current = $word;
      }
      else {
        $current = $candidate;
      }
    }
    if ($current !== '') {
      $segments[] = $current;
    }
    return $segments;
  }

}
