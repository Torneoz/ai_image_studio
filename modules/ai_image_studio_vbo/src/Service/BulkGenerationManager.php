<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio_vbo\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Utility\Token;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Creates durable bulk jobs and queues selected nodes for generation.
 */
final class BulkGenerationManager {

  /**
   * Constructs the bulk generation manager.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
    private readonly Token $token,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Adds a selected node to a bulk job.
   */
  public function enqueue(NodeInterface $node, array $configuration): int {
    $job_id = $this->ensureJob($configuration);
    $langcode = $node->language()->getId();
    $existing = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i', ['id'])
      ->condition('job_id', $job_id)
      ->condition('node_id', (int) $node->id())
      ->condition('langcode', $langcode)
      ->execute()
      ->fetchField();
    if ($existing !== FALSE) {
      return (int) $existing;
    }

    $prompt = trim($this->token->replace(
      (string) $configuration['prompt_template'],
      ['node' => $node],
      ['clear' => TRUE],
    ));
    if ($prompt === '') {
      throw new \InvalidArgumentException('The resolved prompt is empty.');
    }

    $source = $this->sourceFile($node, (string) ($configuration['source_field'] ?? ''));
    $now = $this->time->getRequestTime();
    $item_id = (int) $this->database->insert('ai_image_studio_vbo_item')
      ->fields([
        'job_id' => $job_id,
        'node_id' => (int) $node->id(),
        'revision_id' => $node->getRevisionId() ? (int) $node->getRevisionId() : NULL,
        'langcode' => $langcode,
        'label' => mb_substr((string) $node->label(), 0, 255),
        'resolved_prompt' => $prompt,
        'source_file_id' => $source?->id(),
        'status' => 'queued',
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
    $this->queueFactory->get('ai_image_studio_node_generation')
      ->createItem(['item_id' => $item_id]);
    return $item_id;
  }

  /**
   * Loads a job record and decodes its configuration.
   */
  public function loadJob(int $job_id): ?object {
    $job = $this->database->select('ai_image_studio_vbo_job', 'j')
      ->fields('j')
      ->condition('id', $job_id)
      ->execute()
      ->fetchObject();
    if ($job === FALSE) {
      return NULL;
    }
    $job->configuration = json_decode((string) $job->configuration, TRUE) ?: [];
    return $job;
  }

  /**
   * Loads one bulk job item.
   */
  public function loadItem(int $item_id): ?object {
    $item = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i')
      ->condition('id', $item_id)
      ->execute()
      ->fetchObject();
    return $item === FALSE ? NULL : $item;
  }

  /**
   * Loads all original items belonging to a bulk job.
   *
   * @return object[]
   *   The job items in creation order.
   */
  public function itemsForJob(int $job_id): array {
    return $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i')
      ->condition('job_id', $job_id)
      ->orderBy('id')
      ->execute()
      ->fetchAll();
  }

  /**
   * Queues a fresh generation turn for a completed or failed item.
   */
  public function regenerateItem(int $job_id, int $item_id): void {
    $item = $this->loadItem($item_id);
    if ($item === NULL || (int) $item->job_id !== $job_id) {
      throw new \InvalidArgumentException('The bulk job item does not exist.');
    }
    if (in_array($item->status, ['queued', 'processing'], TRUE)) {
      throw new \LogicException('The bulk job item is already being processed.');
    }

    $now = $this->time->getRequestTime();
    $this->database->update('ai_image_studio_vbo_item')
      ->fields([
        'turn_id' => NULL,
        'media_id' => NULL,
        'status' => 'queued',
        'attempt_count' => 0,
        'error_message' => NULL,
        'changed' => $now,
      ])
      ->condition('id', $item_id)
      ->condition('job_id', $job_id)
      ->execute();
    $this->database->update('ai_image_studio_vbo_job')
      ->fields([
        'status' => 'active',
        'changed' => $now,
      ])
      ->condition('id', $job_id)
      ->execute();
    $this->queueFactory->get('ai_image_studio_node_generation')
      ->createItem(['item_id' => $item_id]);
  }

  /**
   * Requeues every original item in a job with replacement settings.
   */
  public function regenerateJob(int $job_id, array $configuration): int {
    $job = $this->loadJob($job_id);
    if ($job === NULL) {
      throw new \InvalidArgumentException('The bulk job does not exist.');
    }
    $items = $this->itemsForJob($job_id);
    foreach ($items as $item) {
      if (in_array($item->status, ['queued', 'processing'], TRUE)) {
        throw new \LogicException('The bulk job is already being processed.');
      }
    }
    $stored_configuration = $configuration;
    unset($stored_configuration['prompt_template']);
    $now = $this->time->getRequestTime();

    $transaction = $this->database->startTransaction();
    try {
      $this->database->update('ai_image_studio_vbo_job')
        ->fields([
          'uid' => (int) $configuration['initiating_uid'],
          'prompt_template' => (string) $configuration['prompt_template'],
          'configuration' => json_encode($stored_configuration, JSON_THROW_ON_ERROR),
          'status' => 'active',
          'changed' => $now,
        ])
        ->condition('id', $job_id)
        ->execute();

      $queued_item_ids = [];
      foreach ($items as $item) {
        $node = $this->entityTypeManager->getStorage('node')->load((int) $item->node_id);
        if (!$node instanceof NodeInterface) {
          continue;
        }
        if ($node->hasTranslation((string) $item->langcode)) {
          $node = $node->getTranslation((string) $item->langcode);
        }
        $prompt = trim($this->token->replace(
          (string) $configuration['prompt_template'],
          ['node' => $node],
          ['clear' => TRUE],
        ));
        if ($prompt === '') {
          throw new \InvalidArgumentException('The resolved prompt is empty.');
        }
        $source = $this->sourceFile($node, (string) ($configuration['source_field'] ?? ''));
        $this->database->update('ai_image_studio_vbo_item')
          ->fields([
            'revision_id' => $node->getRevisionId() ? (int) $node->getRevisionId() : NULL,
            'label' => mb_substr((string) $node->label(), 0, 255),
            'resolved_prompt' => $prompt,
            'source_file_id' => $source?->id(),
            'turn_id' => NULL,
            'media_id' => NULL,
            'status' => 'queued',
            'attempt_count' => 0,
            'error_message' => NULL,
            'changed' => $now,
          ])
          ->condition('id', (int) $item->id)
          ->execute();
        $queued_item_ids[] = (int) $item->id;
      }
      foreach ($queued_item_ids as $item_id) {
        $this->queueFactory->get('ai_image_studio_node_generation')
          ->createItem(['item_id' => $item_id]);
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    return count($queued_item_ids);
  }

  /**
   * Returns the parent job ID for a queued item.
   */
  public function jobIdForItem(int $item_id): int {
    return (int) $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i', ['job_id'])
      ->condition('id', $item_id)
      ->execute()
      ->fetchField();
  }

  /**
   * Returns the job ID, creating the job and its Studio session as needed.
   */
  private function ensureJob(array $configuration): int {
    $uuid = (string) $configuration['run_uuid'];
    $job_id = $this->database->select('ai_image_studio_vbo_job', 'j')
      ->fields('j', ['id'])
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchField();
    if ($job_id !== FALSE) {
      return (int) $job_id;
    }

    $owner = (int) $configuration['initiating_uid'];
    $session = $this->entityTypeManager
      ->getStorage('ai_image_studio_session')
      ->create([
        'title' => 'Bulk image job ' . date('Y-m-d H:i', $this->time->getRequestTime()),
        'uid' => $owner,
        'status' => 'active',
      ]);
    $session->save();

    $stored_configuration = $configuration;
    unset($stored_configuration['prompt_template']);
    $now = $this->time->getRequestTime();
    return (int) $this->database->insert('ai_image_studio_vbo_job')
      ->fields([
        'uuid' => $uuid,
        'uid' => $owner,
        'session_id' => (int) $session->id(),
        'prompt_template' => (string) $configuration['prompt_template'],
        'configuration' => json_encode($stored_configuration, JSON_THROW_ON_ERROR),
        'status' => 'active',
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
  }

  /**
   * Resolves the first file from an image, file, or Media reference field.
   */
  private function sourceFile(NodeInterface $node, string $field_name): ?FileInterface {
    if ($field_name === '' || !$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    $entity = $node->get($field_name)->first()?->entity;
    if ($entity instanceof FileInterface) {
      return $entity;
    }
    if (!$entity instanceof MediaInterface) {
      return NULL;
    }
    $source_field = $entity->getSource()->getConfiguration()['source_field'] ?? '';
    if ($source_field !== '' && $entity->hasField($source_field)) {
      $file = $entity->get($source_field)->first()?->entity;
      return $file instanceof FileInterface ? $file : NULL;
    }
    return NULL;
  }

}
