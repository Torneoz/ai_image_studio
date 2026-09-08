<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Creates and tracks durable bulk generation jobs for storyboard shots. */
final class StoryboardBulkManager {

  public function __construct(
    private readonly Connection $database,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Queues all current shots in a storyboard and returns the new job ID. */
  public function enqueueProject(object $storyboard, int $uid): int {
    $now = $this->time->getRequestTime();
    $job_id = (int) $this->database->insert('ai_storyboard_bulk_job')
      ->fields([
        'uuid' => (new UuidGenerator())->generate(),
        'storyboard_id' => (int) $storyboard->id(),
        'uid' => $uid,
        'status' => 'active',
        'created' => $now,
        'changed' => $now,
      ])->execute();
    $storage = $this->entityTypeManager->getStorage('ai_storyboard_shot');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('storyboard_id', $storyboard->id())
      ->sort('position')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $shot) {
      $item_id = (int) $this->database->insert('ai_storyboard_bulk_item')
        ->fields([
          'job_id' => $job_id,
          'shot_id' => (int) $shot->id(),
          'label' => mb_substr((string) $shot->label(), 0, 255),
          'status' => 'queued',
          'created' => $now,
          'changed' => $now,
        ])->execute();
      $this->queueFactory->get('ai_storyboard_frame_generation')
        ->createItem(['item_id' => $item_id]);
    }
    return $job_id;
  }

  /**
   * Loads a bulk job. */
  public function loadJob(int $job_id): ?object {
    $job = $this->database->select('ai_storyboard_bulk_job', 'j')
      ->fields('j')->condition('id', $job_id)->execute()->fetchObject();
    return $job === FALSE ? NULL : $job;
  }

  /**
   * Returns a job's items in shot order. */
  public function itemsForJob(int $job_id): array {
    return $this->database->select('ai_storyboard_bulk_item', 'i')
      ->fields('i')->condition('job_id', $job_id)->orderBy('id')->execute()->fetchAll();
  }

  /**
   * Updates a job after an item reaches a terminal state. */
  public function updateJobStatus(int $job_id): void {
    $active = $this->database->select('ai_storyboard_bulk_item', 'i')
      ->condition('job_id', $job_id)
      ->condition('status', ['queued', 'processing'], 'IN')
      ->countQuery()->execute()->fetchField();
    if ((int) $active === 0) {
      $failed = $this->database->select('ai_storyboard_bulk_item', 'i')
        ->condition('job_id', $job_id)->condition('status', 'failed')
        ->countQuery()->execute()->fetchField();
      $this->database->update('ai_storyboard_bulk_job')
        ->fields([
          'status' => (int) $failed > 0 ? 'completed_with_errors' : 'completed',
          'changed' => $this->time->getRequestTime(),
        ])->condition('id', $job_id)->execute();
    }
  }

}
