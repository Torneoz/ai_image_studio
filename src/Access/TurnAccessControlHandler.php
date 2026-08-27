<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Applies the parent session's access policy to Studio turns.
 */
final class TurnAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission('administer ai image studio')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($operation !== 'view') {
      return AccessResult::neutral()->cachePerPermissions();
    }

    $session = $entity->get('session_id')->entity;
    if ($session === NULL) {
      return AccessResult::forbidden()->addCacheableDependency($entity);
    }

    return $session->access('view', $account, TRUE)
      ->addCacheableDependency($entity);
  }

}
