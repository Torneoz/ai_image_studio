<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Owner-aware access for storyboards. */
final class StoryboardAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc} */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer ai storyboard')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if (!$account->hasPermission('access ai storyboard')) {
      return AccessResult::forbidden()->cachePerPermissions();
    }
    return AccessResult::allowedIf((int) $entity->getOwnerId() === (int) $account->id())
      ->cachePerUser()->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc} */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'access ai storyboard');
  }

}
