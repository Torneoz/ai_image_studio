<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity;

use Drupal\ai_storyboard\Entity\Views\StoryboardShotViewsData;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores one editable storyboard shot. */
#[ContentEntityType(
  id: 'ai_storyboard_shot',
  label: new TranslatableMarkup('Storyboard shot'),
  handlers: [
    'access' => 'Drupal\ai_storyboard\Access\StoryboardShotAccessControlHandler',
    'views_data' => StoryboardShotViewsData::class,
  ],
  base_table: 'ai_storyboard_shot',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
  admin_permission: 'administer ai storyboard',
)]
final class StoryboardShot extends ContentEntityBase {

  /**
   * {@inheritdoc} */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['storyboard_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Storyboard'))->setRequired(TRUE)->setSetting('target_type', 'ai_storyboard');
    $fields['position'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Position'))->setRequired(TRUE)->setSetting('unsigned', TRUE);
    $fields['scene_number'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Scene'))->setSetting('unsigned', TRUE);
    $fields['shot_number'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Shot'))->setSetting('unsigned', TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setRequired(TRUE)->setSetting('max_length', 255);
    $fields['action'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Action'));
    $fields['dialogue'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Dialogue / voice-over'));
    $fields['audio'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Sound / music'));
    $fields['shot_size'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Shot size'))->setSetting('max_length', 80);
    $fields['camera_angle'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Camera angle'))->setSetting('max_length', 80);
    $fields['camera_move'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Camera movement'))->setSetting('max_length', 120);
    $fields['lens'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Lens'))->setSetting('max_length', 80);
    $fields['lighting'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Lighting'))->setSetting('max_length', 160);
    $fields['duration'] = BaseFieldDefinition::create('decimal')->setLabel(new TranslatableMarkup('Duration'))->setSetting('precision', 8)->setSetting('scale', 2)->setDefaultValue('3.00');
    $fields['image_prompt'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Image prompt'));
    $fields['continuity_notes'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Continuity notes'));
    $fields['studio_turn_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Generated frame'))->setSetting('target_type', 'ai_image_studio_turn');
    $fields['video_turn_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Generated video sequence'))->setSetting('target_type', 'ai_image_studio_turn');
    $fields['status'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Status'))->setSettings(['allowed_values' => ['draft' => 'Draft', 'generated' => 'Generated', 'approved' => 'Approved']])->setDefaultValue('draft');
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}
