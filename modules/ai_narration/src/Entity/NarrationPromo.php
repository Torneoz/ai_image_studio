<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/** Stores reusable standalone narration audio. */
#[ContentEntityType(id: 'ai_narration_promo', label: new TranslatableMarkup('AI narration promo'), label_collection: new TranslatableMarkup('AI narration promos'), handlers: ['list_builder' => 'Drupal\ai_narration\NarrationEntityListBuilder', 'views_data' => 'Drupal\ai_narration\Entity\Views\NarrationViewsData', 'form' => ['add' => 'Drupal\ai_narration\Form\NarrationPromoForm', 'edit' => 'Drupal\ai_narration\Form\NarrationPromoForm', 'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm'], 'route_provider' => ['html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider']], base_table: 'ai_narration_promo', entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'], admin_permission: 'administer ai narration', links: ['canonical' => '/admin/content/ai-narration/promos/{ai_narration_promo}', 'add-form' => '/admin/content/ai-narration/promos/add', 'edit-form' => '/admin/content/ai-narration/promos/{ai_narration_promo}/edit', 'delete-form' => '/admin/content/ai-narration/promos/{ai_narration_promo}/delete', 'collection' => '/admin/content/ai-narration/promos'])]
final class NarrationPromo extends ContentEntityBase {
  use EntityChangedTrait;
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['project_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Project'))->setDescription(new TranslatableMarkup('Optional. Promos without a Project can be reused globally.'))->setSetting('target_type', 'ai_narration_project')->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -10])->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 1])->setDisplayConfigurable('view', TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setRequired(TRUE)->setSetting('max_length', 255)->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'string', 'weight' => -10])->setDisplayConfigurable('view', TRUE);
    $fields['text'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Narration text'))->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => -8])->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 2])->setDisplayConfigurable('view', TRUE);
    $fields['audio_files'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Generated audio'))->setSetting('target_type', 'file')->setCardinality(-1)->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_entity_view', 'weight' => 10])->setDisplayConfigurable('view', TRUE);
    $fields['last_generated_audio'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Last generated audio'))->setSetting('target_type', 'file')->setCardinality(1)->setDisplayOptions('view', ['label' => 'above', 'type' => 'ai_narration_audio_player', 'weight' => 11])->setDisplayConfigurable('view', TRUE);
    $fields['generation_metadata'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Last generation metadata'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 12])->setDisplayConfigurable('view', TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 90])->setDisplayConfigurable('view', TRUE);
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 91])->setDisplayConfigurable('view', TRUE);
    return $fields;
  }
}
