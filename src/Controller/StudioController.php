<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists accessible Studio sessions.
 */
final class StudioController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    private readonly AccountProxyInterface $currentUserProxy,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the session collection.
   */
  public function collection(): array {
    $storage = $this->entityTypeManager->getStorage('ai_image_studio_session');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC')
      ->pager(25);
    if (!$this->currentUserProxy->hasPermission('view any ai image studio session')
      && !$this->currentUserProxy->hasPermission('administer ai image studio')) {
      $query->condition('uid', $this->currentUserProxy->id());
    }
    $sessions = $storage->loadMultiple($query->execute());

    $rows = [];
    foreach ($sessions as $session) {
      $operations = [
        'open' => [
          'title' => $this->t('Open'),
          'url' => Url::fromRoute('entity.ai_image_studio_session.canonical', [
            'ai_image_studio_session' => $session->id(),
          ]),
          'weight' => 0,
        ],
      ];
      if ($session->access('delete')) {
        $operations['delete'] = [
          'title' => $this->t('Delete'),
          'url' => Url::fromRoute('entity.ai_image_studio_session.delete_form', [
            'ai_image_studio_session' => $session->id(),
          ]),
          'weight' => 10,
        ];
      }

      $rows[] = [
        Link::fromTextAndUrl(
          $session->label(),
          Url::fromRoute('entity.ai_image_studio_session.canonical', [
            'ai_image_studio_session' => $session->id(),
          ]),
        ),
        $session->getOwner()?->getDisplayName() ?? $this->t('Unknown'),
        match ((string) $session->get('status')->value) {
          'active' => $this->t('Active'),
          'archived' => $this->t('Archived'),
          default => $this->t('Unknown'),
        },
        $this->dateFormatter->format((int) $session->getChangedTime(), 'short'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attached' => [
        'library' => ['ai_image_studio/collection'],
      ],
      'intro' => [
        '#markup' => '<p>' . $this->t('Create an image, then refine it through sequential prompts. Each result is retained as a turn in the session.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Session'),
          $this->t('Owner'),
          $this->t('Status'),
          $this->t('Updated'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No image sessions have been created yet.'),
      ],
      'pager' => [
        '#type' => 'pager',
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions'],
        'tags' => ['ai_image_studio_session_list'],
      ],
    ];
  }

}
