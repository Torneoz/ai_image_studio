<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Plugin\QueueWorker;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes media generation outside the browser request.
 */
#[QueueWorker(
  id: 'ai_image_studio_generation',
  title: new TranslatableMarkup('AI Image Studio generation'),
  cron: ['time' => 60],
)]
final class GenerationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the queue worker.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ImageGenerator $generator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('ai_image_studio.generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $turn_id = (int) ($data['turn_id'] ?? 0);
    $turn = $this->entityTypeManager
      ->getStorage('ai_image_studio_turn')
      ->load($turn_id);
    if ($turn === NULL
      || in_array($turn->get('status')->value, ['completed', 'cancelled'], TRUE)) {
      return;
    }

    $turn = $this->generator->processTurn($turn);
    if ($turn->get('status')->value !== 'failed') {
      return;
    }

    $limit = max(1, (int) ($this->configFactory
      ->get('ai_image_studio.settings')
      ->get('generation_retry_limit') ?: 3));
    if ((int) $turn->get('attempt_count')->value < $limit) {
      $turn->set('status', 'queued');
      $turn->save();
      throw new RequeueException((string) $turn->get('error_message')->value);
    }
  }

}
