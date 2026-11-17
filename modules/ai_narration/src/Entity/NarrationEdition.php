<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/** Stores an optional dated grouping of Chapters within a Project. */
#[ContentEntityType(id: 'ai_narration_edition', label: new TranslatableMarkup('AI narration edition'), label_collection: new TranslatableMarkup('AI narration editions'), handlers: ['list_builder' => 'Drupal\ai_narration\NarrationEntityListBuilder', 'views_data' => 'Drupal\ai_narration\Entity\Views\NarrationViewsData', 'form' => ['add' => 'Drupal\Core\Entity\ContentEntityForm', 'edit' => 'Drupal\Core\Entity\ContentEntityForm', 'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm'], 'route_provider' => ['html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider']], base_table: 'ai_narration_edition', entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'], admin_permission: 'administer ai narration', links: ['canonical' => '/admin/content/ai-narration/editions/{ai_narration_edition}', 'add-form' => '/admin/content/ai-narration/editions/add', 'edit-form' => '/admin/content/ai-narration/editions/{ai_narration_edition}/edit', 'delete-form' => '/admin/content/ai-narration/editions/{ai_narration_edition}/delete', 'collection' => '/admin/content/ai-narration/editions'])]
final class NarrationEdition extends ContentEntityBase {
  use EntityChangedTrait;
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['project_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Project'))->setRequired(TRUE)->setSetting('target_type', 'ai_narration_project')->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -10])->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 1])->setDisplayConfigurable('view', TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setRequired(TRUE)->setSetting('max_length', 255)->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'string', 'weight' => -10])->setDisplayConfigurable('view', TRUE);
    $fields['edition_date'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Edition date'))->setDescription(new TranslatableMarkup('Optional publication or edition date.'))->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => -8])->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 2])->setDisplayConfigurable('view', TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 90])->setDisplayConfigurable('view', TRUE);
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 91])->setDisplayConfigurable('view', TRUE);
    return $fields;
  }
}
