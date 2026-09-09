<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores a character.
 */
#[ContentEntityType(
  id: 'ai_storyboard_character',
  label: new TranslatableMarkup('Character'),
  label_collection: new TranslatableMarkup('Characters'),
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
  base_table: 'ai_storyboard_character',
  revision_table: 'ai_storyboard_character_revision',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
  admin_permission: 'administer ai storyboard',
  links: [
    'collection' => '/admin/content/ai-storyboard-library/character',
    'add-form' => '/admin/content/ai-storyboard-library/character/add',
    'canonical' => '/admin/content/ai-storyboard-library/character/{ai_storyboard_character}',
    'edit-form' => '/admin/content/ai-storyboard-library/character/{ai_storyboard_character}/edit',
  ],
)]
final class StoryboardCharacter extends NarrativeEntity {}
