<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves AI Image Studio prompt configuration entities to prompt text.
 */
final class PromptResolver {

  public const PROMPT_TYPE = 'ai_image_studio';

  public const DEFAULT_PROMPT = 'ai_image_studio__high_quality_image';

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

}
