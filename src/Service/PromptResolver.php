<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves AI Image Studio prompt configuration entities to prompt text.
 */
final class PromptResolver {

  public const PROMPT_TYPE = 'ai_image_studio';

  /**
   * Constructs the prompt resolver.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves a Studio prompt ID, returning an empty string when invalid.
   */
  public function resolve(mixed $prompt_id): string {
    $prompt_id = trim((string) $prompt_id);
    if ($prompt_id === '') {
      return '';
    }
    $prompt = $this->configFactory->get('ai.ai_prompt.' . $prompt_id);
    if ($prompt->isNew()
      || (string) $prompt->get('type') !== self::PROMPT_TYPE) {
      return '';
    }
    return trim((string) $prompt->get('prompt'));
  }

  /**
   * Reports whether the Studio prompt type exists in active configuration.
   */
  public function promptTypeExists(): bool {
    return !$this->configFactory
      ->get('ai.ai_prompt_type.' . self::PROMPT_TYPE)
      ->isNew();
  }

  /**
   * Combines editor-written instructions with an optional managed prompt.
   */
  public function compose(mixed $start, mixed $prompt_id): string {
    return implode("\n\n", array_filter([
      trim((string) $start),
      $this->resolve($prompt_id),
    ], static fn (string $part): bool => $part !== ''));
  }

}
