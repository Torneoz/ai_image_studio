<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves AI Image Studio prompt configuration entities to prompt text.
 */
final class PromptResolver {

  public const PROMPT_TYPE = 'ai_image_studio';

  public const STYLE_PROMPT_TYPE = 'ai_image_studio_style';

  /**
   * Constructs the prompt resolver.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns enabled prompts of one type for a compact selection control.
   *
   * @return array<string, array{label: string, prompt: string}>
   *   Prompt definitions keyed by prompt ID.
   */
  public function choices(string $prompt_type): array {
    $prompts = $this->entityTypeManager
      ->getStorage('ai_prompt')
      ->loadByProperties([
        'type' => $prompt_type,
        'status' => TRUE,
      ]);
    $choices = [];
    foreach ($prompts as $prompt) {
      $choices[(string) $prompt->id()] = [
        'label' => (string) $prompt->label(),
        'prompt' => trim((string) $prompt->getPrompt()),
      ];
    }
    uasort(
      $choices,
      static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']),
    );
    return $choices;
  }

  /**
   * Resolves a Studio prompt ID, returning an empty string when invalid.
   */
  public function resolve(
    mixed $prompt_id,
    string $prompt_type = self::PROMPT_TYPE,
  ): string {
    $prompt_id = trim((string) $prompt_id);
    if ($prompt_id === '') {
      return '';
    }
    $prompt = $this->configFactory->get('ai.ai_prompt.' . $prompt_id);
    if ($prompt->isNew()
      || (string) $prompt->get('type') !== $prompt_type) {
      return '';
    }
    return trim((string) $prompt->get('prompt'));
  }

  /**
   * Reports whether the Studio prompt type exists in active configuration.
   */
  public function promptTypeExists(string $prompt_type = self::PROMPT_TYPE): bool {
    return !$this->configFactory
      ->get('ai.ai_prompt_type.' . $prompt_type)
      ->isNew();
  }

  /**
   * Combines editor instructions with optional style and finishing prompts.
   */
  public function compose(
    mixed $start,
    mixed $prompt_id,
    mixed $style_prompt_id = '',
  ): string {
    return implode("\n\n", array_filter([
      trim((string) $start),
      $this->resolve($style_prompt_id, self::STYLE_PROMPT_TYPE),
      $this->resolve($prompt_id),
    ], static fn (string $part): bool => $part !== ''));
  }

}
