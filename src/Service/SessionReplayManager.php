<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Replays logical Studio requests into a new session in creation order.
 */
final class SessionReplayManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ImageGenerator $generator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Creates the durable replay plan and starts processing it.
   */
  public function start(object $source, object $target, array $overrides = [], bool $use_defaults = FALSE): void {
    $turns = $this->loadTurns((int) $source->id());
    $plan = [];
    $seen = [];
    foreach ($turns as $turn) {
      $group = $this->group($turn);
      if (!isset($seen[$group])) {
        $seen[$group] = TRUE;
        $plan[] = (int) $turn->id();
      }
    }
    $target->set('replay_source_session_id', $source->id());
    $target->set('replay_state', [
      'status' => 'running',
      'cursor' => 0,
      'plan' => $plan,
      'turn_map' => [],
      'overrides' => $overrides,
      'use_default_models' => $use_defaults,
      'target_session_id' => (int) $target->id(),
    ]);
    $target->save();
    $this->advance($target);
  }

  /**
   * Continues a replay until it completes or reaches an asynchronous request.
   */
  public function advance(object $target): void {
    $state = (array) ($target->get('replay_state')->first()?->getValue() ?? []);
    if (($state['status'] ?? '') !== 'running') {
      return;
    }
    $plan = array_map('intval', (array) ($state['plan'] ?? []));
    $this->refreshTurnMap($state, $plan);

    while ((int) ($state['cursor'] ?? 0) < count($plan)) {
      $cursor = (int) $state['cursor'];
      $original = $this->entityTypeManager->getStorage('ai_image_studio_turn')->load($plan[$cursor]);
      if ($original === NULL) {
        $this->fail($target, $state, 'An original turn is no longer available.');
        return;
      }

      $parent = NULL;
      $original_parent = $original->get('parent_id')->entity;
      if ($original_parent !== NULL) {
        $mapped_id = (int) (($state['turn_map'] ?? [])[(string) $original_parent->id()] ?? 0);
        $parent = $mapped_id > 0
          ? $this->entityTypeManager->getStorage('ai_image_studio_turn')->load($mapped_id)
          : NULL;
        if ($parent === NULL || $parent->get('status')->value !== 'completed') {
          $this->fail($target, $state, 'A replay source result could not be reconstructed.');
          return;
        }
      }

      $requested = (array) ($original->get('requested_generation_settings')->first()?->getValue() ?? []);
      if ($requested === []) {
        $requested = (array) ($original->get('generation_settings')->first()?->getValue() ?? []);
      }
      $settings = array_replace($requested, array_filter(
        (array) ($state['overrides'] ?? []),
        static fn (mixed $value): bool => $value !== NULL && $value !== '',
      ));
      $operation = (string) $original->get('operation')->value;
      $resolution_override = str_contains($operation, 'video')
        ? ($settings['video_resolution'] ?? '')
        : ($settings['image_resolution'] ?? '');
      if ($resolution_override !== '') {
        $settings['resolution'] = $resolution_override;
      }
      unset($settings['image_resolution'], $settings['video_resolution']);
      $sources = array_values(array_filter(
        $original->get('source_file_ids')->referencedEntities(),
        static fn (mixed $file): bool => $file instanceof FileInterface,
      ));
      if ($sources === [] && $original->get('source_file_id')->entity instanceof FileInterface) {
        $sources[] = $original->get('source_file_id')->entity;
      }
      $source = $parent === NULL ? ($sources[0] ?? NULL) : NULL;
      if ($parent !== NULL && $sources !== []) {
        array_shift($sources);
      }
      $settings['reference_file_ids'] = array_map(
        static fn (FileInterface $file): int => (int) $file->id(),
        $sources,
      );

      $model = !empty($state['use_default_models'])
        ? $this->generator->getDefaultModel($operation)
        : $this->modelOption($original);
      $state['cursor'] = $cursor + 1;
      $target->set('replay_state', $state);
      $target->save();

      try {
        $result = $this->generator->generate(
          $target,
          (string) $original->get('prompt')->value,
          $model,
          $parent,
          $source,
          $settings,
          str_contains($operation, 'video') ? 'video' : 'image',
          $original,
          $cursor + 1,
        );
      }
      catch (\Throwable $exception) {
        $this->logger->error('Session replay failed: @message', ['@message' => $exception->getMessage()]);
        $this->fail($target, $state, $exception->getMessage());
        return;
      }
      $this->refreshTurnMap($state, $plan);
      $target->set('replay_state', $state);
      $target->save();
      if (in_array($result->get('status')->value, ['queued', 'processing'], TRUE)) {
        return;
      }
      if ($result->get('status')->value !== 'completed') {
        $this->fail($target, $state, (string) $result->get('error_message')->value);
        return;
      }
    }

    $state['status'] = 'completed';
    $target->set('replay_state', $state);
    $target->save();
  }

  /**
   * Advances or fails a replay after an asynchronous turn finishes.
   */
  public function turnFinished(object $turn): void {
    if ($turn->get('replay_of')->isEmpty()) {
      return;
    }
    $session = $turn->get('session_id')->entity;
    if ($session === NULL || $session->get('replay_state')->isEmpty()) {
      return;
    }
    $state = (array) ($session->get('replay_state')->first()?->getValue() ?? []);
    if ($turn->get('status')->value === 'completed') {
      $this->advance($session);
    }
    elseif (in_array($turn->get('status')->value, ['failed', 'expired', 'cancelled'], TRUE)) {
      $this->fail($session, $state, (string) ($turn->get('error_message')->value ?: 'A replay request did not complete.'));
    }
  }

  /**
   * Maps every original variation to its corresponding replay variation.
   */
  private function refreshTurnMap(array &$state, array $plan): void {
    $map = (array) ($state['turn_map'] ?? []);
    $storage = $this->entityTypeManager->getStorage('ai_image_studio_turn');
    foreach (array_slice($plan, 0, (int) ($state['cursor'] ?? 0)) as $original_id) {
      $original = $storage->load($original_id);
      if ($original === NULL) {
        continue;
      }
      $new_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('replay_of', $original_id)
        ->condition('session_id', (int) ($state['target_session_id'] ?? 0))
        ->sort('id', 'ASC')
        ->execute();
      if ($new_ids === []) {
        continue;
      }
      $old_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('session_id', $original->get('session_id')->target_id)
        ->condition('request_group', $this->group($original))
        ->sort('id', 'ASC')
        ->execute();
      $old_ids = array_values($old_ids);
      $new_ids = array_values($new_ids);
      foreach ($old_ids as $index => $old_id) {
        if (isset($new_ids[$index])) {
          $map[(string) $old_id] = (int) $new_ids[$index];
        }
      }
    }
    $state['turn_map'] = $map;
  }

  /**
   * Loads every result in deterministic creation order.
   */
  private function loadTurns(int $session_id): array {
    $storage = $this->entityTypeManager->getStorage('ai_image_studio_turn');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('session_id', $session_id)
      ->sort('sequence', 'ASC')
      ->sort('created', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    return $storage->loadMultiple($ids);
  }

  /**
   * Returns the stable logical request group for a result.
   */
  private function group(object $turn): string {
    return (string) ($turn->get('request_group')->value ?: 'turn:' . $turn->id());
  }

  /**
   * Reconstructs a Drupal AI provider/model option.
   */
  private function modelOption(object $turn): string {
    return (string) $turn->get('provider_id')->value . '__' . (string) $turn->get('model_id')->value;
  }

  /**
   * Marks a replay as failed with a durable diagnostic.
   */
  private function fail(object $target, array $state, string $message): void {
    $state['status'] = 'failed';
    $state['error'] = $message;
    $target->set('replay_state', $state);
    $target->save();
  }

}
