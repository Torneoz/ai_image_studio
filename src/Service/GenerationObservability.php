<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Reports application lifecycle records alongside native AI provider events.
 */
final class GenerationObservability {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Reports meaningful turn changes, including custom requests and async polls.
   */
  public function turnSaved(EntityInterface $turn): void {
    if ($turn->getEntityTypeId() !== 'ai_image_studio_turn') {
      return;
    }
    $fields = [
      'status', 'attempt_count', 'provider_request_id', 'error_message',
      'provider_metadata', 'generation_settings',
    ];
    $original = method_exists($turn, 'getOriginal') ? $turn->getOriginal() : ($turn->original ?? NULL);
    if ($original) {
      $changed = FALSE;
      foreach ($fields as $field) {
        $changed = $changed || $turn->get($field)->getValue() !== $original->get($field)->getValue();
      }
      if (!$changed) {
        return;
      }
    }
    $metadata = ['turn_id' => (int) $turn->id()];
    foreach ([
      'session_id', 'parent_id', 'request_group', 'sequence', 'replay_of',
      'status', 'attempt_count', 'provider_id', 'model_id', 'operation',
      'provider_request_id', 'duration_ms', 'estimated_cost', 'cost_source',
    ] as $field) {
      $item = $turn->get($field)->first()?->getValue() ?? [];
      $metadata[$field] = $item['target_id'] ?? $item['value'] ?? NULL;
    }
    // Explicit allowlists: settings and provider metadata can contain prompts,
    // signed URLs, credentials, or base64 media supplied by third parties.
    $settings = $turn->get('generation_settings')->first()?->getValue() ?? [];
    $metadata['configuration'] = array_intersect_key($settings, array_flip([
      'duration', 'resolution', 'aspect_ratio', 'variations', 'quality',
      'original_prompt_characters', 'effective_prompt_characters',
      'prompt_context_compacted',
    ]));
    $provider = $turn->get('provider_metadata')->first()?->getValue() ?? [];
    $metadata['provider_metadata'] = array_intersect_key($provider, array_flip([
      'request_id', 'provider_status', 'progress', 'input_count', 'output_count',
      'requested_output_count', 'http_status', 'provider_code', 'provider_type',
      'provider_param', 'exception_class',
    ]));
    $metadata['token_usage'] = $turn->get('token_usage')->first()?->getValue() ?? [];
    $metadata['prompt_characters'] = mb_strlen((string) $turn->get('prompt')->value);
    $metadata['provider'] = $metadata['provider_id'];
    $metadata['model'] = $metadata['model_id'];
    $metadata['operation_type'] = $metadata['operation'];
    $config = $this->configFactory->get('ai_observability.settings');
    if ($config->get('log_input')) {
      $metadata['input'] = GenerationDiagnostics::sanitize((string) $turn->get('prompt')->value);
    }
    if ($config->get('log_output')) {
      foreach (['image', 'video'] as $field) {
        $metadata[$field . '_file_id'] = $turn->get($field)->target_id;
      }
    }
    $error = (string) $turn->get('error_message')->value;
    // Error prose can echo private prompts: only include it with input logging.
    if ($error !== '' && $config->get('log_input')) {
      $metadata['error_message'] = GenerationDiagnostics::sanitize($error);
    }
    $this->record('media.' . $metadata['status'], $metadata);
  }

  /**
   * Writes an optional, best-effort AI Observability lifecycle log.
   */
  public function record(string $event, array $metadata, array $tags = ['ai_image_studio']): void {
    try {
      $config = $this->configFactory->get('ai_observability.settings');
      if (!$this->moduleHandler->moduleExists('ai_observability') || !$config->get('logging_enabled')) {
        return;
      }
      $filter = (array) $config->get('log_tags');
      if ($filter && !array_intersect($filter, $tags)) {
        return;
      }
      $metadata['event_name'] = 'ai_image_studio.' . $event;
      $metadata['tags'] = $tags;
      array_walk_recursive($metadata, static function (&$value): void {
        if (is_string($value)) {
          $value = GenerationDiagnostics::sanitize($value);
        }
      });
      $this->loggerFactory->get('ai_observability')->log(
        in_array($metadata['status'] ?? '', ['failed', 'expired'], TRUE) ? 'error' : 'info',
        'AIIS @event (session @session, turn @turn, storyboard @storyboard).',
        [
          '@event' => $event,
          '@session' => $metadata['session_id'] ?? '-',
          '@turn' => $metadata['turn_id'] ?? '-',
          '@storyboard' => $metadata['storyboard_id'] ?? '-',
          'metadata' => $metadata,
        ],
      );
    }
    catch (\Throwable) {
      // Observability must never turn a successful paid request into a failure.
    }
  }

}
