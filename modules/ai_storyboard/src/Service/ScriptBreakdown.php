<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Converts a script into structured, production-aware shots. */
final class ScriptBreakdown {

  public function __construct(
    private readonly AiProviderPluginManager $providerManager,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Leaves headroom within the worker's execution limit and queue lease.
   */
  public function getRequestTimeout(): int {
    return max(30, min(480, (int) ($this->configFactory->get('ai_storyboard.settings')->get('breakdown_timeout') ?? 300)));
  }

  /**
   * Returns configured chat provider/model options. */
  public function getModelOptions(): array {
    return $this->providerManager->getSimpleProviderModelOptions('chat', TRUE);
  }

  /**
   * Breaks a script into a continuity bible and ordered shots. */
  public function breakdown(
    string $script,
    string $modelOption,
    string $brief = '',
    string $continuityBible = '',
    string $characterBible = '',
  ): array {
    $parts = explode('__', $modelOption);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw new \InvalidArgumentException('The selected chat provider/model is unavailable.');
    }
    // Supply transport options at construction, not as model parameters.
    // Keep the AI provider proxy so normal provider events still run.
    $provider = $this->providerManager->createInstance($parts[0], [
      'http_client_options' => [
        'timeout' => $this->getRequestTimeout(),
        'connect_timeout' => 30,
      ],
    ]);
    $model = $this->providerManager->getModelNameFromSimpleOption($modelOption);
    if (!$provider->isUsable() || $model === '') {
      throw new \InvalidArgumentException('The selected chat provider/model is unavailable.');
    }

    $input = new ChatInput([new ChatMessage('user', implode("\n\n", [
      "CREATIVE BRIEF:\n{$brief}",
      "EXISTING CONTINUITY BIBLE — preserve and improve these directions:\n{$continuityBible}",
      "EXISTING CHARACTER BIBLE — preserve these canonical identities:\n{$characterBible}",
      "SCRIPT:\n{$script}",
    ]))]);
    $input->setSystemPrompt('You are a meticulous film director and storyboard artist. Break the supplied script into visually distinct shots, preserving every story beat. Prefer purposeful coverage over arbitrary cuts. Return only the requested structured data. Image prompts must describe a single frozen frame and must not contain dialogue text, captions, or camera motion as visible action.');
    $input->setSystemPrompt($input->getSystemPrompt() . ' Always create substantive continuity_bible and character_bible strings, even when the supplied bibles are empty. Define consistent environments, props, lighting and visual character identities from the script. If no characters exist, explicitly state that and describe any recurring subjects. Never return empty bible fields.');
    $input->setChatStructuredJsonSchema([
      'name' => 'storyboard_breakdown',
      'strict' => TRUE,
      'schema' => [
        'type' => 'object',
        'additionalProperties' => FALSE,
        'properties' => [
          'continuity_bible' => ['type' => 'string', 'description' => 'Canonical prop, environment, geography, palette, lighting, and time-of-day facts to preserve across shots.'],
          'character_bible' => ['type' => 'string', 'description' => 'Canonical visual description of every character, including age, appearance, wardrobe, distinguishing features, and relationships.'],
          'scenes' => [
            'type' => 'array',
            'description' => 'One entry per scene. A change of location or dramatic time starts a new scene. Location bibles describe stable geography, architecture, materials and landmarks; conditions hold temporary time, weather and lighting. Do not invent shared library identifiers.',
            'items' => [
              'type' => 'object',
              'additionalProperties' => FALSE,
              'properties' => [
                'scene_number' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'script_excerpt' => ['type' => 'string'],
                'location_bible' => ['type' => 'string'],
                'conditions' => ['type' => 'string'],
                'action' => ['type' => 'string'],
              ],
              'required' => ['scene_number', 'title', 'script_excerpt', 'location_bible', 'conditions', 'action'],
            ],
          ],
          'shots' => [
            'type' => 'array',
            'items' => [
              'type' => 'object',
              'additionalProperties' => FALSE,
              'properties' => [
                'scene_number' => ['type' => 'integer'],
                'shot_number' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'action' => ['type' => 'string'],
                'dialogue' => ['type' => 'string'],
                'audio' => ['type' => 'string'],
                'shot_size' => ['type' => 'string'],
                'camera_angle' => ['type' => 'string'],
                'camera_move' => ['type' => 'string'],
                'lens' => ['type' => 'string'],
                'lighting' => ['type' => 'string'],
                'duration' => ['type' => 'number'],
                'image_prompt' => ['type' => 'string'],
                'continuity_notes' => ['type' => 'string'],
              ],
              'required' => ['scene_number', 'shot_number', 'title', 'action', 'dialogue', 'audio', 'shot_size', 'camera_angle', 'camera_move', 'lens', 'lighting', 'duration', 'image_prompt', 'continuity_notes'],
            ],
          ],
        ],
        'required' => ['continuity_bible', 'character_bible', 'scenes', 'shots'],
      ],
    ]);

    try {
      $text = $provider->chat($input, $model, ['ai_storyboard'])->getNormalized()->getText();
      $data = json_decode($this->stripCodeFence($text), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $e) {
      $this->logger->error('Storyboard breakdown failed: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException('The AI provider could not break down this script. ' . $e->getMessage(), 0, $e);
    }
    if (!is_array($data) || !is_array($data['shots'] ?? NULL) || $data['shots'] === []) {
      throw new \UnexpectedValueException('The AI provider returned no usable shots.');
    }
    foreach (['continuity_bible', 'character_bible'] as $field) {
      if (!is_string($data[$field] ?? NULL) || trim($data[$field]) === '') {
        throw new \UnexpectedValueException('The AI provider returned an empty ' . $field . '. Existing shots and bibles have been preserved. Retry the breakdown.');
      }
    }
    return $data;
  }

  /**
   *
   */
  private function stripCodeFence(string $text): string {
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $matches)) {
      return $matches[1];
    }
    return $text;
  }

}
