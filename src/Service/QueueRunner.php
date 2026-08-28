<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Psr\Log\LoggerInterface;

/**
 * Advances one item from a named Drupal queue.
 */
final class QueueRunner {

  /**
   * Constructs the queue runner.
   */
  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly QueueWorkerManagerInterface $queueWorkerManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Claims and processes one queue item.
   */
  public function runOne(string $queue_id): void {
    $queue = $this->queueFactory->get($queue_id);
    $item = $queue->claimItem(900);
    if ($item === FALSE) {
      return;
    }

    $worker = $this->queueWorkerManager->createInstance($queue_id);
    try {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
    catch (DelayedRequeueException $exception) {
      if ($queue instanceof DelayableQueueInterface) {
        $queue->delayItem($item, $exception->getDelay());
      }
      else {
        $queue->releaseItem($item);
      }
    }
    catch (RequeueException) {
      $queue->releaseItem($item);
    }
    catch (SuspendQueueException $exception) {
      $queue->releaseItem($item);
      $this->logger->warning('@queue queue processing was suspended: @message', [
        '@queue' => $queue_id,
        '@message' => $exception->getMessage(),
      ]);
    }
    catch (\Throwable $exception) {
      $queue->releaseItem($item);
      $this->logger->error('@queue queue processing failed: @message', [
        '@queue' => $queue_id,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
