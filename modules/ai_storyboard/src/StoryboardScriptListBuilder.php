<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Lists saved scripts and opens them in the ScriptWriter workspace.
 */
final class StoryboardScriptListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return ['title' => $this->t('Title'), 'changed' => $this->t('Last saved')] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $url = Url::fromRoute('ai_storyboard.script_writer', [], ['query' => ['script' => $entity->id()]]);
    return [
      'title' => Link::fromTextAndUrl($entity->label(), $url)->toRenderable(),
      'changed' => \Drupal::service('date.formatter')->format($entity->getChangedTime(), 'short'),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $build['#cache']['contexts'][] = 'user';
    $build['#cache']['contexts'][] = 'user.permissions';
    return $build;
  }

}
