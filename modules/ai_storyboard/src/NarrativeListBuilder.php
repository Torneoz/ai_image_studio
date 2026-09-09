<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Lists accessible narrative records with an add action.
 */
final class NarrativeListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return ['title' => $this->t('Name')] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    return ['title' => $entity->toLink()] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    if ($this->getStorage()->getEntityType()->getHandlerClass('access')
      && \Drupal::entityTypeManager()->getAccessControlHandler($this->entityTypeId)->createAccess()) {
      $build['add'] = Link::createFromRoute($this->t('Add @type', ['@type' => $this->entityType->getLabel()]), 'entity.' . $this->entityTypeId . '.add_form')->toRenderable();
      $build['add']['#attributes']['class'] = ['button', 'button--primary'];
      $build['add']['#weight'] = -10;
    }
    $build['#cache']['contexts'][] = 'user';
    $build['#cache']['contexts'][] = 'user.permissions';
    return $build;
  }

}
