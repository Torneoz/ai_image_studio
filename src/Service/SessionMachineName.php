<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Generates stable, unique machine names for Studio sessions.
 */
final class SessionMachineName {

  /**
   * Constructs the session machine-name service.
   */
  public function __construct(
    private readonly TransliterationInterface $transliteration,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Normalizes a proposed machine name.
   */
  public function normalize(string $value): string {
    $value = strtolower($this->transliteration->transliterate(trim($value), 'en'));
    $value = trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    return mb_substr($value, 0, 100);
  }

  /**
   * Returns a unique machine name based on a proposed name or title.
   */
  public function generate(string $value, ?int $session_id = NULL): string {
    $base = $this->normalize($value);
    if ($base === '') {
      $base = $session_id === NULL ? 'session' : 'session-' . $session_id;
    }

    $candidate = $base;
    $suffix = 2;
    while ($this->exists($candidate, $session_id)) {
      $ending = '-' . $suffix++;
      $candidate = mb_substr($base, 0, 100 - strlen($ending)) . $ending;
    }
    return $candidate;
  }

  /**
   * Checks whether a machine name belongs to another session.
   */
  public function exists(string $machine_name, ?int $session_id = NULL): bool {
    $query = $this->entityTypeManager
      ->getStorage('ai_image_studio_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $machine_name)
      ->range(0, 1);
    if ($session_id !== NULL) {
      $query->condition('id', $session_id, '<>');
    }
    return $query->count()->execute() > 0;
  }

}
