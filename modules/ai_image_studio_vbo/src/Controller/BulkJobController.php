<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio_vbo\Controller;

use Drupal\ai_image_studio_vbo\Service\BulkGenerationManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Displays VBO image generation jobs and their node items.
 */
final class BulkJobController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly BulkGenerationManager $bulkManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('ai_image_studio_vbo.batch_manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists bulk jobs visible to the current user.
   */
  public function listing(): array {
    $query = $this->database->select('ai_image_studio_vbo_job', 'j')
      ->fields('j')
      ->orderBy('created', 'DESC')
      ->range(0, 100);
    if (!$this->currentUser()->hasPermission('view any ai image studio vbo job')) {
      $query->condition('uid', (int) $this->currentUser()->id());
    }
    $jobs = $query->execute()->fetchAll();
    $rows = [];
    foreach ($jobs as $job) {
      $counts = $this->itemCounts((int) $job->id);
      $rows[] = [
        Link::fromTextAndUrl(
          $this->t('Job @id', ['@id' => $job->id]),
          Url::fromRoute('ai_image_studio_vbo.job', ['job_id' => $job->id]),
        ),
        $job->status,
        (string) array_sum($counts),
        (string) (($counts['completed'] ?? 0) + ($counts['published'] ?? 0)),
        (string) ($counts['failed'] ?? 0),
        $this->dateFormatter->format((int) $job->created, 'short'),
      ];
    }
    return [
      'description' => [
        '#markup' => '<p>' . $this->t('Jobs are created by the “Generate images with AI Image Studio” Views Bulk Operations action. Active job pages advance the queue while they refresh; Drupal cron remains a fallback.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Job'),
          $this->t('Status'),
          $this->t('Items'),
          $this->t('Succeeded'),
          $this->t('Failed'),
          $this->t('Created'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No bulk image jobs are available.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Displays one job and its item-level results.
   */
  public function view(int $job_id): array {
    $job = $this->bulkManager->loadJob($job_id);
    if ($job === NULL) {
      throw new NotFoundHttpException();
    }
    $items = $this->database->select('ai_image_studio_vbo_item', 'i')
      ->fields('i')
      ->condition('job_id', $job_id)
      ->orderBy('id')
      ->execute()
      ->fetchAll();
    $rows = [];
    foreach ($items as $item) {
      $node_link = Link::fromTextAndUrl(
        $item->label,
        Url::fromRoute('entity.node.canonical', ['node' => $item->node_id]),
      );
      $result = '';
      if ($item->media_id) {
        $result = Link::fromTextAndUrl(
          $this->t('Media @id', ['@id' => $item->media_id]),
          Url::fromRoute('entity.media.canonical', ['media' => $item->media_id]),
        );
      }
      elseif ($item->turn_id && $job->session_id) {
        $result = Link::fromTextAndUrl(
          $this->t('Studio result'),
          Url::fromRoute('entity.ai_image_studio_session.canonical', [
            'ai_image_studio_session' => $job->session_id,
          ]),
        );
      }
      $rows[] = [
        $node_link,
        $item->langcode,
        $item->status,
        (string) $item->attempt_count,
        $result,
        ['data' => ['#plain_text' => (string) ($item->error_message ?? '')]],
      ];
    }
    $active = array_filter(
      $items,
      static fn (object $item): bool => in_array(
        $item->status,
        ['queued', 'processing'],
        TRUE,
      ),
    );
    $build = [
      'summary' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Status: @status', ['@status' => $job->status]),
          $this->t('Created: @created', [
            '@created' => $this->dateFormatter->format((int) $job->created, 'short'),
          ]),
          $this->t('Prompt: @prompt', ['@prompt' => $job->prompt_template]),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Node'),
          $this->t('Language'),
          $this->t('Status'),
          $this->t('Attempts'),
          $this->t('Result'),
          $this->t('Error'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No nodes have been queued for this job.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
    if ($active !== []) {
      $build['processing'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'status' => [
            $this->t('Image generation is in progress. This page refreshes every 5 seconds until the job finishes.'),
          ],
        ],
        '#status_headings' => [
          'status' => $this->t('Status message'),
        ],
        '#weight' => -10,
      ];
      $build['#attached']['html_head'][] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'http-equiv' => 'refresh',
            'content' => '5',
          ],
        ],
        'ai_image_studio_vbo_job_refresh',
      ];
    }
    return $build;
  }

  /**
   * Returns the page title for a job.
   */
  public function title(int $job_id): string {
    return (string) $this->t('Bulk image job @id', ['@id' => $job_id]);
  }

  /**
   * Checks job ownership or broad job-view permission.
   */
  public function access(AccountInterface $account, int $job_id): AccessResult {
    $job = $this->bulkManager->loadJob($job_id);
    if ($job === NULL) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIf(
      $account->hasPermission('view any ai image studio vbo job')
      || ($account->hasPermission('view ai image studio vbo jobs')
        && (int) $job->uid === (int) $account->id()),
    )->addCacheContexts(['user.permissions', 'user']);
  }

  /**
   * Returns item counts grouped by status.
   */
  private function itemCounts(int $job_id): array {
    $query = $this->database->select('ai_image_studio_vbo_item', 'i');
    $query->addField('i', 'status');
    $query->addExpression('COUNT(*)', 'item_count');
    $query->condition('job_id', $job_id)->groupBy('status');
    return $query->execute()->fetchAllKeyed();
  }

}
