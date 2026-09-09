<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores a scene.
 */
#[ContentEntityType(
  id: 'ai_storyboard_scene',
  label: new TranslatableMarkup('Scene'),
  label_collection: new TranslatableMarkup('Scenes'),
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
  base_table: 'ai_storyboard_scene',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
  admin_permission: 'administer ai storyboard',
  links: [
    'collection' => '/admin/content/ai-storyboard-library/scene',
    'add-form' => '/admin/content/ai-storyboard-library/scene/add',
    'canonical' => '/admin/content/ai-storyboard-library/scene/{ai_storyboard_scene}',
    'edit-form' => '/admin/content/ai-storyboard-library/scene/{ai_storyboard_scene}/edit',
  ],
)]
final class StoryboardScene extends NarrativeEntity {}
