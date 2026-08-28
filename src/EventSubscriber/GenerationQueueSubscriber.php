<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\EventSubscriber;

use Drupal\ai_image_studio\Service\QueueRunner;
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
    private readonly QueueRunner $queueRunner,
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

    $this->queueRunner->runOne(self::QUEUE_ID);
  }

}
