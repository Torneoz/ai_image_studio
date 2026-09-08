<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Storyboard listing pages. */
final class StoryboardController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('date.formatter'));
  }

  /**
   * Lists storyboards visible to the current account. */
  public function collection(): array {
    $query = $this->entityTypeManager->getStorage('ai_storyboard')->getQuery()->accessCheck(TRUE)->sort('changed', 'DESC');
    if (!$this->currentUser()->hasPermission('administer ai storyboard')) {
      $query->condition('uid', $this->currentUser()->id());
    }
    $boards = $this->entityTypeManager->getStorage('ai_storyboard')->loadMultiple($query->execute());
    $rows = [];
    foreach ($boards as $board) {
      $shot_count = $this->entityTypeManager->getStorage('ai_storyboard_shot')->getQuery()->accessCheck(FALSE)->condition('storyboard_id', $board->id())->count()->execute();
      $rows[] = [
        ['data' => Link::fromTextAndUrl($board->label(), $board->toUrl())->toRenderable()],
        (string) $shot_count,
        ucfirst(str_replace('_', ' ', (string) $board->get('status')->value)),
        $this->dateFormatter->format((int) $board->getChangedTime(), 'short'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => $board->toUrl(),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => $board->toUrl('delete-form'),
              ],
            ],
          ],
        ],
      ];
    }
    $add = Link::fromTextAndUrl(
      $this->t('Add storyboard'),
      Url::fromRoute('ai_storyboard.new'),
    )->toRenderable();
    $add['#attributes']['class'] = ['button', 'button--primary'];
    $jobs = Link::fromTextAndUrl(
      $this->t('Bulk jobs'),
      Url::fromRoute('ai_storyboard.bulk_jobs'),
    )->toRenderable();
    $jobs['#attributes']['class'] = ['button'];
    return [
      'intro' => ['#markup' => '<p>' . $this->t('Turn a script into editable, production-aware shots, then generate continuity-guided frames through AI Image Studio.') . '</p>'],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Storyboard'),
          $this->t('Shots'),
          $this->t('Status'),
          $this->t('Updated'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No storyboards yet.'),
      ],
      'actions' => [
        '#type' => 'actions',
        'add' => $add,
        'jobs' => $jobs,
      ],
    ];
  }

}
