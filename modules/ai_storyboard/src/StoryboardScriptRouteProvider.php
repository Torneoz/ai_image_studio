<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;

/**
 * Adds access for ordinary Storyboard users to their saved script list.
 */
final class StoryboardScriptRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCollectionRoute(EntityTypeInterface $entity_type) {
    $route = parent::getCollectionRoute($entity_type);
    if ($route) {
      $route->setRequirement('_permission', 'access ai storyboard+administer ai storyboard');
    }
    return $route;
  }

}
