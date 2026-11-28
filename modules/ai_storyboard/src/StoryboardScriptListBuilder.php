<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Lists saved scripts and opens them in the ScriptWriter workspace.
 */
final class StoryboardScriptListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    $query = parent::getEntityListQuery();
    $project_id = (int) \Drupal::request()->query->get('project', 0);
    $project = $project_id ? \Drupal::entityTypeManager()->getStorage('ai_storyboard')->load($project_id) : NULL;
    if ($project_id) {
      $query->condition('storyboard_id', $project && $project->access('view') ? $project_id : 0);
    }
    return $query;
  }

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
      'title' => ['data' => Link::fromTextAndUrl($entity->label(), $url)->toRenderable()],
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
