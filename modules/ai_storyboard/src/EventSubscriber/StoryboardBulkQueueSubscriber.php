<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\EventSubscriber;

use Drupal\ai_image_studio\Service\QueueRunner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Advances one storyboard bulk item after each main web response. */
final class StoryboardBulkQueueSubscriber implements EventSubscriberInterface {

  private const QUEUE_ID = 'ai_storyboard_frame_generation';

  public function __construct(private readonly QueueRunner $queueRunner) {}

  /**
   * {@inheritdoc} */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => ['onTerminate', -120]];
  }

  /**
   * Processes one queued frame after the response is sent. */
  public function onTerminate(TerminateEvent $event): void {
    if ($event->isMainRequest()) {
      $this->queueRunner->runOne('ai_storyboard_breakdown');
      $this->queueRunner->runOne(self::QUEUE_ID);
    }
  }

}
