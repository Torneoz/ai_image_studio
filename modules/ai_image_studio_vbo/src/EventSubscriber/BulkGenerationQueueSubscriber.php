<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio_vbo\EventSubscriber;

use Drupal\ai_image_studio\Service\QueueRunner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Advances bulk image generation after a web response has been sent.
 */
final class BulkGenerationQueueSubscriber implements EventSubscriberInterface {

  private const QUEUE_ID = 'ai_image_studio_node_generation';

  /**
   * Constructs the bulk generation queue subscriber.
   */
  public function __construct(
    private readonly QueueRunner $queueRunner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => ['onTerminate', -110]];
  }

  /**
   * Processes one bulk item without delaying the browser response.
   */
  public function onTerminate(TerminateEvent $event): void {
    if ($event->isMainRequest()) {
      $this->queueRunner->runOne(self::QUEUE_ID);
    }
  }

}
