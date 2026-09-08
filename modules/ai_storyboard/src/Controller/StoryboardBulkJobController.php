<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Controller;

use Drupal\ai_storyboard\Service\StoryboardBulkManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Displays storyboard bulk jobs and their frame-generation items. */
final class StoryboardBulkJobController extends ControllerBase {

  public function __construct(
    private readonly StoryboardBulkManager $bulkManager,
    private readonly EntityTypeManagerInterface $storyboardEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_storyboard.bulk_manager'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('database'),
    );
  }

  /**
   * Lists visible storyboard bulk jobs. */
  public function listing(): array {
    $query = $this->database->select('ai_storyboard_bulk_job', 'j')
      ->fields('j')->orderBy('created', 'DESC')->range(0, 100);
    if (!$this->currentUser()->hasPermission('administer ai storyboard')) {
      $query->condition('uid', (int) $this->currentUser()->id());
    }
    $rows = [];
    foreach ($query->execute()->fetchAll() as $job) {
      $storyboard = $this->storyboardEntityTypeManager->getStorage('ai_storyboard')->load((int) $job->storyboard_id);
      $items = $this->bulkManager->itemsForJob((int) $job->id);
      $completed = count(array_filter($items, static fn (object $item): bool => $item->status === 'completed'));
      $job_link = Link::fromTextAndUrl(
        $this->t('Job @id', ['@id' => $job->id]),
        Url::fromRoute('ai_storyboard.bulk_job', ['job_id' => $job->id]),
      )->toRenderable();
      $job_link['#attributes']['class'] = ['button', 'button--small'];
      $rows[] = [
        ['data' => $job_link],
        $storyboard?->label() ?? $this->t('Deleted storyboard'),
        ucfirst(str_replace('_', ' ', (string) $job->status)),
        $this->t('@done of @total', ['@done' => $completed, '@total' => count($items)]),
        $this->dateFormatter->format((int) $job->created, 'short'),
      ];
    }
    return [
      'intro' => ['#markup' => '<p>' . $this->t('Generate All creates one durable queue item per storyboard shot. Keep an active job page open to advance processing; Drupal cron is also supported.') . '</p>'],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Job'),
          $this->t('Storyboard'),
          $this->t('Status'),
          $this->t('Progress'),
          $this->t('Created'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No storyboard bulk jobs are available.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Displays progress and results for one bulk job. */
  public function view(int $job_id): array {
    $job = $this->bulkManager->loadJob($job_id);
    if (!$job) {
      throw new NotFoundHttpException();
    }
    $storyboard = $this->storyboardEntityTypeManager->getStorage('ai_storyboard')->load((int) $job->storyboard_id);
    $items = $this->bulkManager->itemsForJob($job_id);
    $active = FALSE;
    $rows = [];
    foreach ($items as $item) {
      $active = $active || in_array($item->status, ['queued', 'processing'], TRUE);
      $shot = $this->storyboardEntityTypeManager->getStorage('ai_storyboard_shot')->load((int) $item->shot_id);
      $turn = $item->turn_id
        ? $this->storyboardEntityTypeManager->getStorage('ai_image_studio_turn')->load((int) $item->turn_id)
        : NULL;
      $image = $turn?->get('image')->entity;
      $preview = $image ? [
        '#theme' => 'image',
        '#uri' => $image->getFileUri(),
        '#alt' => (string) $item->label,
        '#width' => 180,
        '#attributes' => ['loading' => 'lazy'],
      ] : [];
      $result = $storyboard && $shot ? Link::fromTextAndUrl(
        $this->t('View storyboard'),
        $storyboard->toUrl(),
      )->toRenderable() : [];
      if ($result) {
        $result['#attributes']['class'] = ['button', 'button--small'];
      }
      $rows[] = [
        $item->label,
        ucfirst((string) $item->status),
        (string) $item->attempt_count,
        ['data' => $preview],
        ['data' => $result],
        ['data' => ['#plain_text' => (string) ($item->error_message ?? '')]],
      ];
    }
    $build = [
      'summary' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Storyboard: @title', ['@title' => $storyboard?->label() ?? $this->t('Deleted')]),
          $this->t('Status: @status', ['@status' => ucfirst(str_replace('_', ' ', (string) $job->status))]),
          $this->t('Created: @date', ['@date' => $this->dateFormatter->format((int) $job->created, 'short')]),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Shot'),
          $this->t('Status'),
          $this->t('Attempts'),
          $this->t('Frame'),
          $this->t('Result'),
          $this->t('Error'),
        ],
        '#rows' => $rows,
      ],
      '#cache' => ['max-age' => 0],
    ];
    if ($active) {
      $build['#attached']['html_head'][] = [[
        '#tag' => 'meta',
        '#attributes' => ['http-equiv' => 'refresh', 'content' => '5'],
      ], 'ai_storyboard_bulk_job_refresh',
      ];
    }
    return $build;
  }

  /**
   * Returns a bulk job page title. */
  public function title(int $job_id): string {
    return (string) $this->t('Storyboard bulk job @id', ['@id' => $job_id]);
  }

  /**
   * Checks job ownership. */
  public function access(AccountInterface $account, int $job_id): AccessResult {
    $job = $this->bulkManager->loadJob($job_id);
    return AccessResult::allowedIf($job !== NULL && (
      $account->hasPermission('administer ai storyboard')
      || ($account->hasPermission('access ai storyboard') && (int) $job->uid === (int) $account->id())
    ))->addCacheContexts(['user', 'user.permissions']);
  }

}
