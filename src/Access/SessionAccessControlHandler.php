<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Applies owner-aware access to Studio sessions.
 */
final class SessionAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission('administer ai image studio')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    $owned = (int) $entity->getOwnerId() === (int) $account->id();

    if ($operation === 'view') {
      return AccessResult::allowedIf(
        $account->hasPermission('view any ai image studio session')
        || ($owned && $account->hasPermission('access ai image studio'))
      )->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);
    }

    if ($operation === 'delete') {
      return AccessResult::allowedIf(
        $account->hasPermission('delete any ai image studio session')
        || ($owned && $account->hasPermission('delete own ai image studio session'))
      )->cachePerPermissions()->cachePerUser()->addCacheableDependency($entity);
    }

    return AccessResult::neutral()->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'access ai image studio');
  }

}
