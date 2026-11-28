<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Stores an editable screenplay and its ScriptWriter working context.
 */
#[ContentEntityType(
  id: 'ai_storyboard_script',
  label: new TranslatableMarkup('Script'),
  label_collection: new TranslatableMarkup('Saved scripts'),
  handlers: [
    'access' => 'Drupal\\ai_storyboard\\Access\\StoryboardScriptAccessControlHandler',
    'list_builder' => 'Drupal\\ai_storyboard\\StoryboardScriptListBuilder',
    'form' => [
      'add' => 'Drupal\\Core\\Entity\\ContentEntityForm',
      'edit' => 'Drupal\\Core\\Entity\\ContentEntityForm',
      'delete' => 'Drupal\\Core\\Entity\\ContentEntityDeleteForm',
    ],
    'route_provider' => ['html' => 'Drupal\\ai_storyboard\\StoryboardScriptRouteProvider'],
  ],
  base_table: 'ai_storyboard_script',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'owner' => 'uid'],
  admin_permission: 'administer ai storyboard',
  links: [
    'collection' => '/admin/content/ai-storyboard/scripts',
    'add-form' => '/admin/content/ai-storyboard/scripts/add',
    'canonical' => '/admin/content/ai-storyboard/scripts/{ai_storyboard_script}',
    'edit-form' => '/admin/content/ai-storyboard/scripts/{ai_storyboard_script}/edit',
    'delete-form' => '/admin/content/ai-storyboard/scripts/{ai_storyboard_script}/delete',
  ],
)]
final class StoryboardScript extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0]);
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');
    foreach ([
      'brief' => 'Creative brief',
      'source_text' => 'Source text',
      'instruction' => 'Writing direction',
      'script_text' => 'Screenplay',
      'narration_text' => 'Narration text',
      'narration_instruction' => 'Narration direction',
    ] as $name => $label) {
      $fields[$name] = BaseFieldDefinition::create('string_long')
        ->setLabel(new TranslatableMarkup($label))
        ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 10]);
    }
    $fields['writing_mode'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Writing step'))
      ->setSetting('max_length', 32)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 2]);
    $fields['model'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Writing model'))
      ->setSetting('max_length', 383)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 3]);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}
