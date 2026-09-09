<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Plugin\QueueWorker;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Generates shots and bibles outside the project creation request.
 */
#[QueueWorker(
  id: 'ai_storyboard_breakdown',
  title: new TranslatableMarkup('AI Storyboard script breakdown'),
  cron: ['time' => 300],
)]
final class StoryboardBreakdownQueueWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $id = (int) ($data['storyboard_id'] ?? 0);
    $state = \Drupal::keyValue('ai_storyboard.breakdown');
    $board = \Drupal::entityTypeManager()->getStorage('ai_storyboard')->load($id);
    if (!$board || !in_array($state->get($id, [])['status'] ?? '', ['queued', 'processing'], TRUE)) {
      return;
    }
    // The browser response has already been sent. Allow a slow model time to
    // finish; the queue lease allows recovery if the process is interrupted.
    if (function_exists('set_time_limit')) {
      @set_time_limit(600);
    }
    $state->set($id, [
      'status' => 'processing',
      'message' => 'Generating shots and continuity bibles. This page refreshes automatically.',
    ]);
    $snapshot = $board->toArray();
    try {
      $result = \Drupal::service('ai_storyboard.breakdown')->breakdown(
        (string) $board->get('script')->value,
        (string) $board->get('chat_model')->value,
        (string) $board->get('creative_brief')->value,
        (string) $board->get('continuity_bible')->value,
        (string) $board->get('character_bible')->value,
      );
      $storage = \Drupal::entityTypeManager()->getStorage('ai_storyboard');
      $storage->resetCache([$id]);
      $board = $storage->load($id);
      if (!$board || $board->toArray() !== $snapshot) {
        throw new \RuntimeException('The project changed during generation. Retry using the latest project settings.');
      }
      $transaction = \Drupal::database()->startTransaction();
      try {
        $count = \Drupal::service('ai_storyboard.manager')->replaceShots($board, $result);
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }
      unset($transaction);
      $state->set($id, [
        'status' => 'completed',
        'message' => sprintf('Created %d shots and saved both continuity bibles.', $count),
      ]);
    }
    catch (\Throwable $exception) {
      $state->set($id, [
        'status' => 'failed',
        'message' => 'Breakdown failed: ' . $exception->getMessage() . ' Use Rebuild shots from script to retry.',
      ]);
      \Drupal::logger('ai_storyboard')->error('Breakdown for storyboard @id failed: @message', [
        '@id' => $id,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
