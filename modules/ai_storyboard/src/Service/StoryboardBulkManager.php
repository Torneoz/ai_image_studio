<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

use Drupal\ai_image_studio\Service\ImageGenerator;
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
    private readonly ImageGenerator $imageGenerator,
    private readonly StoryboardManager $storyboardManager,
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
   * Creates video turns from the generated storyboard keyframes.
   */
  public function enqueueVideoSequences(object $storyboard, int $uid, array $settings, ?int $shot_id = NULL): int {
    $session = $storyboard->get('studio_session_id')->entity;
    if (!$session) {
      throw new \LogicException('Generate storyboard frames before creating video sequences.');
    }
    $session = $this->storyboardManager->prepareSession($storyboard);
    $storage = $this->entityTypeManager->getStorage('ai_storyboard_shot');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('storyboard_id', $storyboard->id())
      ->sort('position')
      ->execute();
    $keyframes = [];
    foreach ($storage->loadMultiple($ids) as $shot) {
      $file = $shot->get('studio_turn_id')->entity?->get('image')->entity;
      if ($file) {
        $keyframes[] = ['shot' => $shot, 'file' => $file];
      }
    }
    $mode = (string) ($settings['mode'] ?? 'animate');
    if ($keyframes === [] || ($mode === 'bridge' && count($keyframes) < 2)) {
      throw new \LogicException($mode === 'bridge'
        ? 'Generate at least two storyboard frames before bridging keyframes.'
        : 'Generate at least one storyboard frame before creating video sequences.');
    }

    if ($shot_id !== NULL) {
      $selected = NULL;
      foreach ($keyframes as $index => $keyframe) {
        if ((int) $keyframe['shot']->id() === $shot_id) {
          $selected = $index;
          break;
        }
      }
      if ($selected === NULL || ($mode === 'bridge' && !isset($keyframes[$selected + 1]))) {
        throw new \LogicException('This shot requires a generated keyframe, and bridge mode also requires a following generated keyframe.');
      }
      $keyframes = array_slice($keyframes, $selected, $mode === 'bridge' ? 2 : 1);
    }

    $now = $this->time->getRequestTime();
    $job_id = (int) $this->database->insert('ai_image_studio_vbo_job')
      ->fields([
        'uuid' => (new UuidGenerator())->generate(),
        'uid' => $uid,
        'session_id' => (int) $session->id(),
        'prompt_template' => 'Storyboard video sequences: ' . $storyboard->label(),
        'configuration' => json_encode([
          'source_type' => 'storyboard_video',
          'storyboard_id' => (int) $storyboard->id(),
          'mode' => $mode,
        ], JSON_THROW_ON_ERROR),
        'status' => 'active',
        'created' => $now,
        'changed' => $now,
      ])->execute();

    $limit = $mode === 'bridge' ? count($keyframes) - 1 : count($keyframes);
    $audio_prompt = trim((string) $storyboard->get('audio_prompt')->value);
    for ($index = 0; $index < $limit; $index++) {
      $shot = $keyframes[$index]['shot'];
      $prompt = implode("\n\n", array_filter([
        (string) ($settings['prompt'] ?? ''),
        $audio_prompt !== '' ? 'AUDIO DIRECTION: ' . $audio_prompt : '',
        'SHOT ACTION: ' . $shot->get('action')->value,
        'CAMERA MOVEMENT: ' . $shot->get('camera_move')->value,
        $mode === 'bridge' ? 'Create a continuous transition from the first supplied keyframe to the second. Preserve character identity, wardrobe, environment, and screen direction.' : 'Animate this keyframe as a continuous cinematic shot. Preserve character identity, composition, wardrobe, and environment.',
      ]));
      $item_id = (int) $this->database->insert('ai_image_studio_vbo_item')
        ->fields([
          'job_id' => $job_id,
          'node_id' => (int) $shot->id(),
          'revision_id' => NULL,
          'langcode' => 'und',
          'label' => mb_substr((string) $shot->label(), 0, 255),
          'resolved_prompt' => $prompt,
          'source_file_id' => (int) $keyframes[$index]['file']->id(),
          'status' => 'queued',
          'created' => $now,
          'changed' => $now,
        ])->execute();
      try {
        $aspect_ratio = (string) $storyboard->get('aspect_ratio')->value;
        $generation_settings = [
          'duration' => (int) $settings['duration'],
          'resolution' => (string) $settings['resolution'],
          'aspect_ratio' => in_array($aspect_ratio, ['1:1', '16:9', '9:16', '4:3'], TRUE)
            ? $aspect_ratio
            : 'auto',
        ];
        if ($mode === 'bridge') {
          $generation_settings['video_mode'] = 'reference';
          $generation_settings['reference_file_ids'] = [
            (int) $keyframes[$index]['file']->id(),
            (int) $keyframes[$index + 1]['file']->id(),
          ];
        }
        $turn = $this->imageGenerator->generate(
          $session,
          $prompt,
          (string) $settings['model'],
          NULL,
          $keyframes[$index]['file'],
          $generation_settings,
          'video',
        );
        $shot->set('video_turn_id', $turn->id())->save();
        $this->database->update('ai_image_studio_vbo_item')->fields([
          'turn_id' => (int) $turn->id(),
          'status' => (string) $turn->get('status')->value,
          'changed' => $this->time->getRequestTime(),
        ])->condition('id', $item_id)->execute();
      }
      catch (\Throwable $exception) {
        $this->database->update('ai_image_studio_vbo_item')->fields([
          'status' => 'failed',
          'error_message' => $exception->getMessage(),
          'changed' => $this->time->getRequestTime(),
        ])->condition('id', $item_id)->execute();
      }
    }
    $this->updateJobStatus($job_id);
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
