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
    $header = ['title' => $this->t('Title')];
    if ($this->entityTypeId === 'ai_narration_project') {
      $header += ['model' => $this->t('Model'), 'voice' => $this->t('Voice')];
    }
    if ($this->entityTypeId === 'ai_narration_chapter') {
      $header += ['project' => $this->t('Project'), 'position' => $this->t('Position'), 'status' => $this->t('Status')];
    }
    return $header + parent::buildHeader();
  }

  /** {@inheritdoc} */
  public function buildRow(EntityInterface $entity): array {
    $row = ['title' => $entity->toLink(NULL, 'edit-form')];
    if ($this->entityTypeId === 'ai_narration_project') {
      $row += ['model' => $entity->get('model')->value ?: '—', 'voice' => $entity->get('voice')->value ?: $this->t('Provider default')];
    }
    if ($this->entityTypeId === 'ai_narration_chapter') {
      $row += ['project' => $entity->get('project_id')->entity?->label() ?: '—', 'position' => $entity->get('position')->value, 'status' => $entity->get('status')->value];
    }
    return $row + parent::buildRow($entity);
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
