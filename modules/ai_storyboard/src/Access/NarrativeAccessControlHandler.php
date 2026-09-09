<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Shares libraries while retaining project ownership for scenes and cast.
 */
final class NarrativeAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($operation === 'delete') {
      return AccessResult::forbidden();
    }
    if ($entity->hasField('storyboard_id')) {
      $project = $entity->get('storyboard_id')->entity;
      return $project
        ? $project->access($operation === 'view' ? 'view' : 'update', $account, TRUE)->addCacheableDependency($entity)
        : AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermissions($account, $operation === 'view'
      ? ['access ai storyboard', 'administer ai storyboard', 'manage ai storyboard library']
      : ['manage ai storyboard library', 'administer ai storyboard'], 'OR');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    $project_record = in_array($this->entityTypeId, ['ai_storyboard_scene', 'ai_storyboard_cast'], TRUE);
    return AccessResult::allowedIfHasPermissions($account, $project_record
      ? ['access ai storyboard', 'administer ai storyboard']
      : ['manage ai storyboard library', 'administer ai storyboard'], 'OR');
  }

}
