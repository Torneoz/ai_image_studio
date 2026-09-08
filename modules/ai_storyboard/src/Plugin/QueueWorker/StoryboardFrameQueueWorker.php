<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Plugin\QueueWorker;

use Drupal\ai_storyboard\Service\StoryboardBulkManager;
use Drupal\ai_storyboard\Service\StoryboardManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates a frame for one shot in a storyboard bulk job. */
#[QueueWorker(
  id: 'ai_storyboard_frame_generation',
  title: new TranslatableMarkup('AI Storyboard frame generation'),
  cron: ['time' => 60],
)]
final class StoryboardFrameQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StoryboardManager $storyboardManager,
    private readonly StoryboardBulkManager $bulkManager,
    private readonly TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('ai_storyboard.manager'),
      $container->get('ai_storyboard.bulk_manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc} */
  public function processItem($data): void {
    $item_id = (int) ($data['item_id'] ?? 0);
    $item = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i')->condition('id', $item_id)->execute()->fetchObject();
    if ($item === FALSE || in_array($item->status, ['completed', 'failed'], TRUE)) {
      return;
    }
    $now = $this->time->getRequestTime();
    $this->database->update('ai_image_studio_vbo_item')->fields([
      'status' => 'processing',
      'attempt_count' => (int) $item->attempt_count + 1,
      'error_message' => NULL,
      'changed' => $now,
    ])->condition('id', $item_id)->execute();
    try {
      $shot = $this->entityTypeManager->getStorage('ai_storyboard_shot')->load((int) $item->node_id);
      $storyboard = $shot?->get('storyboard_id')->entity;
      if (!$shot || !$storyboard) {
        throw new \RuntimeException('The storyboard shot is no longer available.');
      }
      $turn = $this->storyboardManager->generateShot($storyboard, $shot);
      if ($turn->get('status')->value !== 'completed') {
        throw new \RuntimeException((string) ($turn->get('error_message')->value ?: 'Frame generation did not complete.'));
      }
      $this->database->update('ai_image_studio_vbo_item')->fields([
        'turn_id' => (int) $turn->id(),
        'status' => 'completed',
        'changed' => $this->time->getRequestTime(),
      ])->condition('id', $item_id)->execute();
      $this->database->update('ai_image_studio_vbo_job')->fields([
        'session_id' => (int) $storyboard->get('studio_session_id')->target_id,
        'changed' => $this->time->getRequestTime(),
      ])->condition('id', (int) $item->job_id)->execute();
    }
    catch (\Throwable $exception) {
      $this->database->update('ai_image_studio_vbo_item')->fields([
        'status' => 'failed',
        'error_message' => $exception->getMessage(),
        'changed' => $this->time->getRequestTime(),
      ])->condition('id', $item_id)->execute();
    }
    $this->bulkManager->updateJobStatus((int) $item->job_id);
  }

}
