<?php

declare(strict_types=1);

namespace Drupal\ai_narration;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;

/**
 * Provides create actions and useful labels for narration entity lists.
 */
final class NarrationEntityListBuilder extends EntityListBuilder {

  /** {@inheritdoc} */
  public function buildHeader(): array {
    return ['title' => $this->t('Title')] + parent::buildHeader();
  }

  /** {@inheritdoc} */
  public function buildRow(EntityInterface $entity): array {
    return ['title' => $entity->toLink('edit-form')] + parent::buildRow($entity);
  }

  /** {@inheritdoc} */
  public function render(): array {
    $build = parent::render();
    $route = 'entity.' . $this->entityTypeId . '.add_form';
    $build['add'] = Link::createFromRoute(
      $this->t('Add @type', ['@type' => $this->entityType->getSingularLabel()]),
      $route,
    )->toRenderable();
    $build['add']['#attributes']['class'] = ['button', 'button--primary'];
    $build['add']['#weight'] = -10;
    return $build;
  }

}
