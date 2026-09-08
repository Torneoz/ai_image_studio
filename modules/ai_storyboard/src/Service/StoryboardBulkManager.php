<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Adds storyboard shots to the shared AI Image Studio bulk-job facility. */
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
    $configuration = json_encode([
      'source_type' => 'storyboard',
      'storyboard_id' => (int) $storyboard->id(),
    ], JSON_THROW_ON_ERROR);
    $session_id = $storyboard->get('studio_session_id')->target_id;
    $job_id = (int) $this->database->insert('ai_image_studio_vbo_job')
      ->fields([
        'uuid' => (new UuidGenerator())->generate(),
        'uid' => $uid,
        'session_id' => $session_id ? (int) $session_id : NULL,
        'prompt_template' => 'Storyboard frames: ' . $storyboard->label(),
        'configuration' => $configuration,
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
      $item_id = (int) $this->database->insert('ai_image_studio_vbo_item')
        ->fields([
          'job_id' => $job_id,
          'node_id' => (int) $shot->id(),
          'revision_id' => NULL,
          'langcode' => 'und',
          'label' => mb_substr((string) $shot->label(), 0, 255),
          'resolved_prompt' => (string) $shot->get('image_prompt')->value,
          'source_file_id' => NULL,
          'status' => 'queued',
          'created' => $now,
          'changed' => $now,
        ])->execute();
      $this->queueFactory->get('ai_storyboard_frame_generation')
        ->createItem(['item_id' => $item_id]);
    }
    if ($ids === []) {
      $this->updateJobStatus($job_id);
    }
    return $job_id;
  }

  /**
   * Loads a bulk job. */
  public function loadJob(int $job_id): ?object {
    $job = $this->database->select('ai_image_studio_vbo_job', 'j')
      ->fields('j')->condition('id', $job_id)->execute()->fetchObject();
    return $job === FALSE ? NULL : $job;
  }

  /**
   * Returns a job's items in shot order. */
  public function itemsForJob(int $job_id): array {
    return $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i')->condition('job_id', $job_id)->orderBy('id')->execute()->fetchAll();
  }

  /**
   * Updates a job after an item reaches a terminal state. */
  public function updateJobStatus(int $job_id): void {
    $active = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->condition('job_id', $job_id)
      ->condition('status', ['queued', 'processing'], 'IN')
      ->countQuery()->execute()->fetchField();
    if ((int) $active === 0) {
      $failed = $this->database->select('ai_image_studio_vbo_item', 'i')
        ->condition('job_id', $job_id)->condition('status', 'failed')
        ->countQuery()->execute()->fetchField();
      $this->database->update('ai_image_studio_vbo_job')
        ->fields([
          'status' => (int) $failed > 0 ? 'completed_with_errors' : 'completed',
          'changed' => $this->time->getRequestTime(),
        ])->condition('id', $job_id)->execute();
    }
  }

}
