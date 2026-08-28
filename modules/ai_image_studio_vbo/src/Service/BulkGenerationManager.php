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
