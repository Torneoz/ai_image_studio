<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard;

use Symfony\Component\Routing\Route;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;

/**
 * Uses the edit workspace as the canonical narrative page.
 */
final class NarrativeRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCanonicalRoute(EntityTypeInterface $entity_type) {
    $id = $entity_type->id();
    $route = new Route($entity_type->getLinkTemplate('canonical'));
    $route->setDefault('_controller', '\\Drupal\\ai_storyboard\\Controller\\NarrativeController::view');
    $route->setDefault('narrative_type', $id);
    $route->setRequirement('_entity_access', $id . '.view');
    $route->setOption('parameters', [$id => ['type' => 'entity:' . $id]]);
    $route->setOption('_admin_route', TRUE);
    return $route;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCollectionRoute(EntityTypeInterface $entity_type) {
    $route = parent::getCollectionRoute($entity_type);
    if ($route) {
      $route->setRequirement('_permission', 'access ai storyboard+administer ai storyboard+manage ai storyboard library');
    }
    return $route;
  }

}
