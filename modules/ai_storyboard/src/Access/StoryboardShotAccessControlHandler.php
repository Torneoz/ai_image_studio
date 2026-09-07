<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Inherits shot access from its storyboard. */
final class StoryboardShotAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc} */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    $storyboard = $entity->get('storyboard_id')->entity;
    return $storyboard
      ? $storyboard->access($operation === 'delete' ? 'update' : $operation, $account, TRUE)
      : AccessResult::forbidden();
  }

  /**
   * {@inheritdoc} */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'access ai storyboard');
  }

}
