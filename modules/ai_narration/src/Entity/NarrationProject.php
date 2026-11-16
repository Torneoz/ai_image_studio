<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Stores shared voice, limit, and delivery settings for a narration.
 */
#[ContentEntityType(
  id: 'ai_narration_project',
  label: new TranslatableMarkup('AI narration project'),
  label_collection: new TranslatableMarkup('AI narration projects'),
  base_table: 'ai_narration_project',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'owner' => 'uid'],
  admin_permission: 'administer ai narration',
)]
final class NarrationProject extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /** {@inheritdoc} */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setRequired(TRUE)->setSetting('max_length', 255);
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Owner'))->setSetting('target_type', 'user')->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');
    $fields['model'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Default voice'))->setDescription(new TranslatableMarkup('Drupal AI provider and voice, encoded as provider__model.'))->setSetting('max_length', 383);
    $fields['max_input_characters'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Maximum input characters'))->setDescription(new TranslatableMarkup('Hard maximum sent in one text-to-speech request. Set this below the selected provider limit.'))->setDefaultValue(10000)->setSetting('unsigned', TRUE)->addPropertyConstraints('value', ['Range' => ['min' => 100]]);
    $fields['language'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Language'))->setSetting('max_length', 32);
    $fields['voice_instructions'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Voice instructions'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}
