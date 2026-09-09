<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Controller;

use Drupal\Core\Url;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Link;

/**
 * Displays narrative records without requiring library edit permission.
 */
final class NarrativeController extends ControllerBase {

  /**
   * Displays the readable fields and an access-checked edit link.
   */
  public function view(RouteMatchInterface $route_match): array {
    $type = $route_match->getRouteObject()->getDefault('narrative_type');
    $entity = $route_match->getParameter($type);
    $build = [
      '#title' => $entity->label(),
      '#cache' => [
        'tags' => $entity->getCacheTags(),
        'contexts' => ['user', 'user.permissions'],
      ],
    ];
    if ($entity->getEntityType()->isRevisionable()) {
      $build['version'] = [
        '#type' => 'item',
        '#title' => $this->t('Library version'),
        '#plain_text' => (string) $entity->getRevisionId(),
      ];
    }
    foreach ($entity->getFields() as $name => $field) {
      if (in_array($name, ['id', 'uuid', 'revision_id', 'revision_default', 'created', 'changed', 'title']) || $field->isEmpty() || $field->getFieldDefinition()->isComputed()) {
        continue;
      }
      $build[$name] = ['#type' => 'details', '#title' => $field->getFieldDefinition()->getLabel(), '#open' => TRUE];
      if (in_array($field->getFieldDefinition()->getType(), ['entity_reference', 'file'], TRUE)) {
        foreach ($field->referencedEntities() as $delta => $referenced) {
          if ($referenced->access('view')) {
            if ($referenced->getEntityTypeId() === 'file') {
              $build[$name][$delta] = [
                '#theme' => 'image',
                '#uri' => $referenced->getFileUri(),
                '#alt' => $referenced->label(),
                '#attributes' => ['style' => 'max-width: 320px; height: auto;'],
              ];
            }
            else {
              $build[$name][$delta] = $referenced->toLink()->toRenderable();
            }
          }
        }
      }
      else {
        $build[$name]['text'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['style' => 'white-space: pre-wrap'],
          'value' => ['#plain_text' => (string) $field->value],
        ];
      }
    }
    $build['edit'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit'),
      '#url' => $entity->toUrl('edit-form'),
      '#attributes' => ['class' => ['button']],
      '#access' => $entity->access('update'),
    ];
    if ($type === 'ai_storyboard_scene') {
      $build['copy'] = [
        '#type' => 'link',
        '#title' => $this->t('Copy scene to a project'),
        '#url' => Url::fromRoute('entity.ai_storyboard_scene.add_form', [], ['query' => ['copy' => $entity->id()]]),
        '#attributes' => ['class' => ['button']],
      ];
      $storage = $this->entityTypeManager()->getStorage('ai_storyboard_shot');
      $ids = $storage->getQuery()->accessCheck(TRUE)->condition('scene_id', $entity->id())->sort('position')->execute();
      $items = [];
      foreach ($storage->loadMultiple($ids) as $shot) {
        $items[] = Link::createFromRoute($shot->label(), 'entity.ai_storyboard_shot.edit_form', [
          'ai_storyboard' => $entity->get('storyboard_id')->target_id,
          'ai_storyboard_shot' => $shot->id(),
        ])->toRenderable();
      }
      $build['shots'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Scene shots'),
        '#items' => $items,
        '#cache' => ['tags' => $storage->getEntityType()->getListCacheTags()],
      ];
    }
    if ($entity->hasField('storyboard_id') && $entity->get('storyboard_id')->entity?->access('view')) {
      $build['project'] = $entity->get('storyboard_id')->entity->toLink($this->t('Back to storyboard'))->toRenderable();
    }
    return $build;
  }

}
