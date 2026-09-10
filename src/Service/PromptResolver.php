<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves AI Image Studio prompt configuration entities to prompt text.
 */
final class PromptResolver {

  public const PROMPT_TYPE = 'ai_image_studio';

  public const START_PROMPT_TYPE = 'ai_image_studio_start';

  public const STYLE_PROMPT_TYPE = 'ai_image_studio_style';

  /**
   * Constructs the prompt resolver.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves a Studio prompt ID, returning an empty string when invalid.
   */
  public function resolve(
    mixed $prompt_id,
    string $prompt_type = self::PROMPT_TYPE,
  ): string {
    if (is_array($prompt_id)) {
      $prompt_id = $prompt_id['table'] ?? '';
    }
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
    mixed $start_prompt_id = '',
  ): string {
    return $this->join($this->parts($start, $prompt_id, $style_prompt_id, $start_prompt_id));
  }

  /**
   * Resolves prompt parts for storage and later style replacement.
   */
  public function parts(
    mixed $start,
    mixed $prompt_id,
    mixed $style_prompt_id = '',
    mixed $start_prompt_id = '',
  ): array {
    return [
      'start' => $this->join([
        $this->resolve($start_prompt_id, self::START_PROMPT_TYPE),
        trim((string) $start),
      ]),
      'style' => $this->resolve($style_prompt_id, self::STYLE_PROMPT_TYPE),
      'after' => $this->resolve($prompt_id),
    ];
  }

  /**
   * Reuses saved instructions, replacing the style when one is selected.
   */
  public function regenerateParts(string $previous, array $parts, mixed $style_id): array {
    // Older turns only stored a combined prompt; preserve that text intact.
    if ($parts === [] || $this->join($parts) !== trim($previous)) {
      $parts = ['start' => trim($previous), 'style' => '', 'after' => ''];
    }
    $style = $this->resolve($style_id, self::STYLE_PROMPT_TYPE);
    if ($style !== '') {
      $parts['style'] = $style;
    }
    return $parts;
  }

  /**
   * Removes finishing instructions from a video request.
   */
  public function withoutAfterPrompt(array $parts, bool $legacy = FALSE): array {
    if ($legacy) {
      // Old turns lack separate parts. Remove only an exact library suffix,
      // never guess where an editor's subject or style instructions end.
      $suffixes = [];
      foreach ($this->configFactory->listAll('ai.ai_prompt.') as $name) {
        $text = $this->resolve(substr($name, strlen('ai.ai_prompt.')));
        if ($text !== '') {
          $suffixes[] = $text;
        }
      }
      usort($suffixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
      foreach ($suffixes as $suffix) {
        $start = (string) ($parts['start'] ?? '');
        if ($start === $suffix || str_ends_with($start, "\n\n" . $suffix)) {
          $parts['start'] = rtrim(substr($start, 0, -strlen($suffix)));
          break;
        }
      }
    }
    $parts['after'] = '';
    return $parts;
  }

  /**
   * Joins resolved prompt parts in their original order.
   */
  public function join(array $parts): string {
    return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
  }

}
