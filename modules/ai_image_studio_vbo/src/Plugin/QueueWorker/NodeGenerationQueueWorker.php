<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio_vbo\Plugin\QueueWorker;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_image_studio_vbo\Service\BulkGenerationManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Token;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates an image for one node selected through VBO.
 */
#[QueueWorker(
  id: 'ai_image_studio_node_generation',
  title: new TranslatableMarkup('AI Image Studio node generation'),
  cron: ['time' => 60],
)]
final class NodeGenerationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ImageGenerator $generator,
    private readonly BulkGenerationManager $bulkManager,
    private readonly Token $token,
    private readonly TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('ai_image_studio.generator'),
      $container->get('ai_image_studio_vbo.batch_manager'),
      $container->get('token'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $item_id = (int) ($data['item_id'] ?? 0);
    $item = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i')
      ->condition('id', $item_id)
      ->execute()
      ->fetchObject();
    if ($item === FALSE || in_array($item->status, ['completed', 'published', 'cancelled'], TRUE)) {
      return;
    }
    $job = $this->bulkManager->loadJob((int) $item->job_id);
    if ($job === NULL) {
      $this->fail($item_id, 'The bulk job no longer exists.');
      return;
    }

    $attempt = (int) $item->attempt_count + 1;
    $this->database->update('ai_image_studio_vbo_item')
      ->fields([
        'status' => 'processing',
        'attempt_count' => $attempt,
        'error_message' => NULL,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $item_id)
      ->execute();

    try {
      $node = $this->loadNode($item);
      $session = $this->entityTypeManager
        ->getStorage('ai_image_studio_session')
        ->load((int) $job->session_id);
      if ($session === NULL) {
        throw new \RuntimeException('The bulk job Studio session is unavailable.');
      }
      $source = $item->source_file_id
        ? $this->entityTypeManager->getStorage('file')->load((int) $item->source_file_id)
        : NULL;
      $source = $source instanceof FileInterface ? $source : NULL;
      $configuration = $job->configuration;
      $turn = $item->turn_id
        ? $this->entityTypeManager->getStorage('ai_image_studio_turn')->load((int) $item->turn_id)
        : NULL;
      if ($turn === NULL) {
        $use_source = $source !== NULL && !empty($configuration['image_model']);
        $turn = $this->generator->generate(
          $session,
          (string) $item->resolved_prompt,
          (string) ($use_source ? $configuration['image_model'] : $configuration['text_model']),
          NULL,
          $use_source ? $source : NULL,
          [
            'aspect_ratio' => $configuration['aspect_ratio'] ?? 'auto',
            'resolution' => $configuration['resolution'] ?? 'auto',
            'quality' => $configuration['quality'] ?? 'medium',
            'file_type' => $configuration['file_type'] ?? 'png',
            'show_ai_badge' => !empty($configuration['show_ai_badge']),
            'ai_badge_text' => $configuration['ai_badge_text'] ?? 'AI Image',
            'variations' => 1,
          ],
        );
        $this->database->update('ai_image_studio_vbo_item')
          ->fields(['turn_id' => (int) $turn->id()])
          ->condition('id', $item_id)
          ->execute();
      }
      elseif ($turn->get('status')->value !== 'completed') {
        $turn = $this->generator->processTurn($turn);
      }

      if ($turn->get('status')->value !== 'completed') {
        $message = (string) ($turn->get('error_message')->value ?: 'Image generation failed.');
        $this->retryOrFail($item_id, $attempt, $message);
        return;
      }

      $media_id = NULL;
      $status = 'completed';
      $destination_field = (string) ($configuration['destination_field'] ?? '');
      $destination_bundle = (string) ($configuration['destination_bundle'] ?? '');
      $destination_is_media = $destination_field !== ''
        && $node->hasField($destination_field)
        && $node->get($destination_field)->getFieldDefinition()->getType() === 'entity_reference';
      if (!empty($configuration['publish_media']) || $destination_is_media) {
        $account = $this->entityTypeManager->getStorage('user')->load((int) $job->uid);
        if ($account === NULL || !$account->hasPermission('publish ai image studio image')) {
          throw new \RuntimeException('The initiating user may no longer publish generated images.');
        }
        $name = trim($this->token->replace(
          (string) ($configuration['media_name_template'] ?? '[node:title] AI image'),
          ['node' => $node],
          ['clear' => TRUE],
        ));
        $alt = trim($this->token->replace(
          (string) ($configuration['alt_template'] ?? ''),
          ['node' => $node],
          ['clear' => TRUE],
        ));
        $media = $this->generator->publish(
          $turn,
          $name ?: (string) $node->label(),
          $alt,
          !empty($configuration['show_ai_badge']),
        );
        $media_id = (int) $media->id();
        $status = 'published';
      }
      if ($destination_field !== '') {
        $this->attachResult(
          $node,
          $turn,
          $destination_bundle,
          $destination_field,
          $media_id,
          (int) $job->uid,
          (string) ($configuration['alt_template'] ?? ''),
        );
      }
      $this->database->update('ai_image_studio_vbo_item')
        ->fields([
          'status' => $status,
          'media_id' => $media_id,
          'error_message' => NULL,
          'changed' => $this->time->getRequestTime(),
        ])
        ->condition('id', $item_id)
        ->execute();
      $this->updateJobStatus((int) $item->job_id);
    }
    catch (RequeueException $exception) {
      throw $exception;
    }
    catch (\Throwable $exception) {
      $this->retryOrFail($item_id, $attempt, $exception->getMessage());
    }
  }

  /**
   * Replaces the configured node field with the generated result.
   */
  private function attachResult(
    NodeInterface $node,
    object $turn,
    string $bundle,
    string $field_name,
    ?int $media_id,
    int $uid,
    string $alt_template,
  ): void {
    if ($node->bundle() !== $bundle || !$node->hasField($field_name)) {
      throw new \RuntimeException('The selected node does not have the configured destination field.');
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($account === NULL || !$node->access('update', $account)) {
      throw new \RuntimeException('The initiating user may no longer update the selected content.');
    }
    $definition = $node->get($field_name)->getFieldDefinition();
    if ($definition->getType() === 'image') {
      $file = $turn->get('image')->entity;
      if (!$file instanceof FileInterface) {
        throw new \RuntimeException('The generated image file is unavailable.');
      }
      $alt = trim($this->token->replace(
        $alt_template,
        ['node' => $node],
        ['clear' => TRUE],
      ));
      $node->set($field_name, [
        'target_id' => $file->id(),
        'alt' => $alt,
      ]);
    }
    elseif ($definition->getType() === 'entity_reference'
      && $definition->getSetting('target_type') === 'media'
      && $media_id !== NULL) {
      $node->set($field_name, ['target_id' => $media_id]);
    }
    else {
      throw new \RuntimeException('The configured destination field is not an image or Media reference field.');
    }
    $node->save();
  }

  /**
   * Loads the snapshotted node translation when it remains available.
   */
  private function loadNode(object $item): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load((int) $item->node_id);
    if (!$node instanceof NodeInterface) {
      throw new \RuntimeException('The selected node no longer exists.');
    }
    if ($node->hasTranslation((string) $item->langcode)) {
      $node = $node->getTranslation((string) $item->langcode);
    }
    return $node;
  }

  /**
   * Requeues a transient failure up to three attempts, then marks it failed.
   */
  private function retryOrFail(int $item_id, int $attempt, string $message): void {
    if ($attempt < 3) {
      $this->database->update('ai_image_studio_vbo_item')
        ->fields([
          'status' => 'queued',
          'error_message' => $message,
          'changed' => $this->time->getRequestTime(),
        ])
        ->condition('id', $item_id)
        ->execute();
      throw new RequeueException($message);
    }
    $this->fail($item_id, $message);
  }

  /**
   * Marks an item failed and refreshes its parent job status.
   */
  private function fail(int $item_id, string $message): void {
    $job_id = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i', ['job_id'])
      ->condition('id', $item_id)
      ->execute()
      ->fetchField();
    $this->database->update('ai_image_studio_vbo_item')
      ->fields([
        'status' => 'failed',
        'error_message' => mb_substr($message, 0, 10000),
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $item_id)
      ->execute();
    if ($job_id !== FALSE) {
      $this->updateJobStatus((int) $job_id);
    }
  }

  /**
   * Marks a job complete after its final queue item finishes.
   */
  private function updateJobStatus(int $job_id): void {
    $active = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->condition('job_id', $job_id)
      ->condition('status', ['queued', 'processing'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
    if ((int) $active > 0) {
      return;
    }
    $failed = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->condition('job_id', $job_id)
      ->condition('status', 'failed')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->database->update('ai_image_studio_vbo_job')
      ->fields([
        'status' => (int) $failed > 0 ? 'completed_with_errors' : 'completed',
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('id', $job_id)
      ->execute();
  }

}
