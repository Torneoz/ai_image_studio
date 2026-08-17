<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\EventSubscriber;

use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Advances Studio generation after a web response has been sent.
 *
 * Drupal cron remains able to process the same queue. Claiming an item is
 * atomic, so the built-in runner and cron cannot process it concurrently.
 */
final class GenerationQueueSubscriber implements EventSubscriberInterface {

  private const QUEUE_ID = 'ai_image_studio_generation';

  /**
   * Constructs the generation queue subscriber.
   */
  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly QueueWorkerManagerInterface $queueWorkerManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => ['onTerminate', -100]];
  }

  /**
   * Processes one generation item without delaying the browser response.
   */
  public function onTerminate(TerminateEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $queue = $this->queueFactory->get(self::QUEUE_ID);
    $item = $queue->claimItem(900);
    if ($item === FALSE) {
      return;
    }

    $worker = $this->queueWorkerManager->createInstance(self::QUEUE_ID);
    try {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
    catch (DelayedRequeueException $exception) {
      if ($queue instanceof DelayableQueueInterface) {
        $queue->delayItem($item, $exception->getDelay());
      }
    }
    catch (RequeueException) {
      $queue->releaseItem($item);
    }
    catch (SuspendQueueException $exception) {
      $queue->releaseItem($item);
      $this->logger->warning('Studio generation queue processing was suspended: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
    catch (\Throwable $exception) {
      $queue->releaseItem($item);
      $this->logger->error('Studio generation queue processing failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
