<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Psr\Log\LoggerInterface;

/**
 * Generates and revises screenplay text. */
final class ScriptWriter {

  public function __construct(
    private readonly AiProviderPluginManager $providerManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns available chat provider/model options. */
  public function getModelOptions(): array {
    return $this->providerManager->getSimpleProviderModelOptions('chat', TRUE);
  }

  /**
   * Generates script material without persisting it. */
  public function write(string $modelOption, string $mode, string $brief, string $source = '', string $instruction = ''): string {
    $parts = explode('__', $modelOption);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw new \InvalidArgumentException('The selected chat provider/model is unavailable.');
    }
    $provider = $this->providerManager->createInstance($parts[0]);
    $model = $this->providerManager->getModelNameFromSimpleOption($modelOption);
    if (!$provider->isUsable() || $model === '') {
      throw new \InvalidArgumentException('The selected chat provider/model is unavailable.');
    }

    $task = match ($mode) {
      'outline' => 'Create a compelling screen story outline with a logline, protagonist, goal, stakes, turning points, and ending. Make the beats specific and cinematic.',
      'draft' => 'Write the screenplay from the supplied outline or notes. Use standard screenplay format: INT./EXT. scene headings, present-tense visual action, character cues, concise speakable dialogue, parentheticals only when useful, and transitions only when motivated. Start with FADE IN: and end with FADE OUT. Give scenes dramatic turns and playable subtext. Do not add commentary around the screenplay.',
      'revise' => 'Revise the supplied screenplay text according to the instruction. Preserve its characters, story facts, and formatting unless the instruction asks otherwise. Return only the revised screenplay text.',
      default => throw new \InvalidArgumentException('Choose outline, draft or revise.'),
    };
    $input = new ChatInput([new ChatMessage('user', implode("\n\n", array_filter([
      "CREATIVE BRIEF / INTENT:\n{$brief}",
      $source !== '' ? "OUTLINE, NOTES OR SCRIPT:\n{$source}" : '',
      $instruction !== '' ? "REVISION INSTRUCTION:\n{$instruction}" : '',
    ]))),
    ]);
    $input->setSystemPrompt('You are an experienced screenwriter, story editor and script doctor. Write original, emotionally credible material for screen. Be specific, visual, concise and attentive to character voice. Avoid generic filler and explanatory prose. Follow the requested task exactly. ' . $task);
    try {
      return trim((string) $provider->chat($input, $model, ['ai_storyboard'])->getNormalized()->getText());
    }
    catch (\Throwable $e) {
      $this->logger->error('Script writing failed: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException('The AI provider could not generate script text. ' . $e->getMessage(), 0, $e);
    }
  }

  /** Adapts a screenplay into clean, speakable narration while preserving story events. */
  public function createNarrationText(string $modelOption, string $script, string $instruction = ''): string {
    $parts = explode('__', $modelOption);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw new \InvalidArgumentException('The selected chat provider/model is unavailable.');
    }
    $provider = $this->providerManager->createInstance($parts[0]);
    $model = $this->providerManager->getModelNameFromSimpleOption($modelOption);
    if (!$provider->isUsable() || $model === '') {
      throw new \InvalidArgumentException('The selected chat provider/model is unavailable.');
    }
    $input = new ChatInput([new ChatMessage('user', implode("\n\n", array_filter([
      "SCREENPLAY:\n{$script}",
      $instruction !== '' ? "NARRATION DIRECTION:\n{$instruction}" : '',
    ])))]);
    $input->setSystemPrompt('Adapt the screenplay into narration text intended to be read aloud. Preserve the complete plot, important character actions, emotional turns, and key spoken lines. Remove screenplay formatting, scene headings, camera directions, and production notes. Write fluent, natural spoken prose with clear transitions. Do not summarize, invent events, add an introduction or commentary, or include sound cues. Return only the narration text.');
    try {
      return trim((string) $provider->chat($input, $model, ['ai_storyboard'])->getNormalized()->getText());
    }
    catch (\Throwable $e) {
      $this->logger->error('Narration adaptation failed: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException('The AI provider could not adapt the screenplay for narration. ' . $e->getMessage(), 0, $e);
    }
  }

}
