<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores a location.
 */
#[ContentEntityType(
  id: 'ai_storyboard_location',
  label: new TranslatableMarkup('Location'),
  label_collection: new TranslatableMarkup('Locations'),
  handlers: [
    'access' => 'Drupal\ai_storyboard\Access\NarrativeAccessControlHandler',
    'views_data' => 'Drupal\ai_storyboard\Entity\Views\NarrativeViewsData',
    'list_builder' => 'Drupal\ai_storyboard\NarrativeListBuilder',
    'form' => [
      'add' => 'Drupal\ai_storyboard\Form\NarrativeForm',
      'edit' => 'Drupal\ai_storyboard\Form\NarrativeForm',
    ],
    'route_provider' => ['html' => 'Drupal\ai_storyboard\NarrativeRouteProvider'],
  ],
  base_table: 'ai_storyboard_location',
  revision_table: 'ai_storyboard_location_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
  admin_permission: 'administer ai storyboard',
  links: [
    'collection' => '/admin/content/ai-storyboard-library/location',
    'add-form' => '/admin/content/ai-storyboard-library/location/add',
    'canonical' => '/admin/content/ai-storyboard-library/location/{ai_storyboard_location}',
    'edit-form' => '/admin/content/ai-storyboard-library/location/{ai_storyboard_location}/edit',
  ],
)]
final class StoryboardLocation extends NarrativeEntity {}
