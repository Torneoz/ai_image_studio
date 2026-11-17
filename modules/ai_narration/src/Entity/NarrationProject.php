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
  handlers: [
    'list_builder' => 'Drupal\ai_narration\NarrationEntityListBuilder',
    'views_data' => 'Drupal\ai_narration\Entity\Views\NarrationViewsData',
    'form' => [
      'add' => 'Drupal\ai_narration\Form\NarrationProjectForm',
      'edit' => 'Drupal\ai_narration\Form\NarrationProjectForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => ['html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider'],
  ],
  base_table: 'ai_narration_project',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'owner' => 'uid'],
  admin_permission: 'administer ai narration',
  links: [
    'canonical' => '/admin/content/ai-narration/projects/{ai_narration_project}',
    'add-form' => '/admin/content/ai-narration/projects/add',
    'edit-form' => '/admin/content/ai-narration/projects/{ai_narration_project}/edit',
    'delete-form' => '/admin/content/ai-narration/projects/{ai_narration_project}/delete',
    'collection' => '/admin/content/ai-narration/projects',
  ],
)]
final class NarrationProject extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /** {@inheritdoc} */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setRequired(TRUE)->setSetting('max_length', 255)->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10]);
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Owner'))->setSetting('target_type', 'user')->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');
    $fields['model'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Default text-to-speech model'))->setDescription(new TranslatableMarkup('Drupal AI provider and model, encoded as provider__model.'))->setSetting('max_length', 383)->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1]);
    $fields['voice'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Default voice'))->setSetting('max_length', 255)->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 2]);
    $fields['format'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Default output format'))->setSetting('max_length', 32)->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 3]);
    $fields['speed'] = BaseFieldDefinition::create('decimal')->setLabel(new TranslatableMarkup('Default speech speed'))->setSetting('precision', 4)->setSetting('scale', 2)->setDefaultValue('1.00')->setDisplayOptions('form', ['type' => 'number', 'weight' => 4]);
    $fields['text_normalization'] = BaseFieldDefinition::create('boolean')->setLabel(new TranslatableMarkup('Normalize text before speaking'))->setDefaultValue(FALSE)->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 5]);
    $fields['max_input_characters'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Maximum input characters'))->setDescription(new TranslatableMarkup('Hard maximum sent in one text-to-speech request. Set this below the selected provider limit.'))->setDefaultValue(10000)->setSetting('unsigned', TRUE)->addPropertyConstraints('value', ['Range' => ['min' => 100]])->setDisplayOptions('form', ['type' => 'number', 'weight' => 2]);
    $fields['language'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Language'))->setSetting('max_length', 32)->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 3]);
    $fields['voice_instructions'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Voice instructions'))->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 4]);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}
