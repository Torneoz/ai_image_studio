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
  cron: ['time' => 600],
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
      $merger = \Drupal::service('ai_storyboard.breakdown_merger');
      $existing = $merger->context($id);
      $result = \Drupal::service('ai_storyboard.breakdown')->breakdown(
        (string) $board->get('script')->value,
        (string) $board->get('chat_model')->value,
        (string) $board->get('creative_brief')->value,
        (string) $board->get('continuity_bible')->value,
        (string) $board->get('character_bible')->value,
        $existing,
      );
      $storage = \Drupal::entityTypeManager()->getStorage('ai_storyboard');
      $storage->resetCache([$id]);
      $board = $storage->load($id);
      if (!$board || $board->toArray() !== $snapshot || $merger->context($id) !== $existing) {
        throw new \RuntimeException('The project changed during generation. Retry using the latest project settings.');
      }
      $transaction = \Drupal::database()->startTransaction();
      try {
        $stats = \Drupal::service('ai_storyboard.manager')->mergeBreakdown($board, $result);
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }
      unset($transaction);
      $state->set($id, [
        'status' => 'completed',
        'message' => sprintf('Created %d shots; updated %d; unchanged %d; retained %d omitted shots. Existing media and nonblank content were preserved. Changed shots are marked draft for review.', $stats['created'], $stats['updated'], $stats['unchanged'], $stats['retained']),
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
