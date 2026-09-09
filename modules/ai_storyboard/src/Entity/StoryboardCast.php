<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores a project character.
 */
#[ContentEntityType(
  id: 'ai_storyboard_cast',
  label: new TranslatableMarkup('Project character'),
  label_collection: new TranslatableMarkup('Project characters'),
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
  base_table: 'ai_storyboard_cast',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
  admin_permission: 'administer ai storyboard',
  links: [
    'collection' => '/admin/content/ai-storyboard-library/cast',
    'add-form' => '/admin/content/ai-storyboard-library/cast/add',
    'canonical' => '/admin/content/ai-storyboard-library/cast/{ai_storyboard_cast}',
    'edit-form' => '/admin/content/ai-storyboard-library/cast/{ai_storyboard_cast}/edit',
  ],
)]
final class StoryboardCast extends NarrativeEntity {}
