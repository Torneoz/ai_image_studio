<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores one ordered, independently retryable narration chapter.
 */
#[ContentEntityType(
  id: 'ai_narration_chapter',
  label: new TranslatableMarkup('AI narration chapter'),
  label_collection: new TranslatableMarkup('AI narration chapters'),
  base_table: 'ai_narration_chapter',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
  admin_permission: 'administer ai narration',
)]
final class NarrationChapter extends ContentEntityBase {

  use EntityChangedTrait;

  /** {@inheritdoc} */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['project_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Project'))->setRequired(TRUE)->setSetting('target_type', 'ai_narration_project');
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setRequired(TRUE)->setSetting('max_length', 255);
    $fields['position'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Position'))->setRequired(TRUE)->setDefaultValue(1)->setSetting('unsigned', TRUE);
    $fields['text'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Narration text'))->setRequired(TRUE);
    $fields['model_override'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Voice override'))->setSetting('max_length', 383);
    $fields['audio_files'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Generated audio chunks'))->setSetting('target_type', 'file')->setCardinality(-1);
    $fields['status'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Status'))->setSettings(['allowed_values' => ['draft' => 'Draft', 'queued' => 'Queued', 'generating' => 'Generating', 'complete' => 'Complete', 'failed' => 'Failed']])->setDefaultValue('draft');
    $fields['error_message'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Last generation error'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}
