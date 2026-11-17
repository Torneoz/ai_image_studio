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
  handlers: [
    'list_builder' => 'Drupal\ai_narration\NarrationEntityListBuilder',
    'views_data' => 'Drupal\ai_narration\Entity\Views\NarrationViewsData',
    'form' => [
      'add' => 'Drupal\ai_narration\Form\NarrationChapterForm',
      'edit' => 'Drupal\ai_narration\Form\NarrationChapterForm',
      'delete' => 'Drupal\Core\Entity\ContentEntityDeleteForm',
    ],
    'route_provider' => ['html' => 'Drupal\Core\Entity\Routing\AdminHtmlRouteProvider'],
  ],
  base_table: 'ai_narration_chapter',
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
  admin_permission: 'administer ai narration',
  links: [
    'canonical' => '/admin/content/ai-narration/chapters/{ai_narration_chapter}',
    'add-form' => '/admin/content/ai-narration/chapters/add',
    'edit-form' => '/admin/content/ai-narration/chapters/{ai_narration_chapter}/edit',
    'delete-form' => '/admin/content/ai-narration/chapters/{ai_narration_chapter}/delete',
    'collection' => '/admin/content/ai-narration/chapters',
  ],
)]
final class NarrationChapter extends ContentEntityBase {

  use EntityChangedTrait;

  /** {@inheritdoc} */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['project_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Project'))->setRequired(TRUE)->setSetting('target_type', 'ai_narration_project')->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => -10])->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_label', 'weight' => 1])->setDisplayConfigurable('view', TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setRequired(TRUE)->setSetting('max_length', 255)->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -9])->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'string', 'weight' => -10])->setDisplayConfigurable('view', TRUE);
    $fields['position'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Position'))->setRequired(TRUE)->setDefaultValue(1)->setSetting('unsigned', TRUE)->setDisplayOptions('form', ['type' => 'number', 'weight' => -8])->setDisplayOptions('view', ['label' => 'above', 'type' => 'number_integer', 'weight' => 2])->setDisplayConfigurable('view', TRUE);
    $fields['text'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Narration text'))->setRequired(TRUE)->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => -7])->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 3])->setDisplayConfigurable('view', TRUE);
    $fields['model_override'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Voice override'))->setSetting('max_length', 383)->setDisplayOptions('view', ['label' => 'above', 'type' => 'string', 'weight' => 4])->setDisplayConfigurable('view', TRUE);
    $fields['audio_files'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Generated audio chunks'))->setSetting('target_type', 'file')->setCardinality(-1)->setDisplayOptions('view', ['label' => 'above', 'type' => 'entity_reference_entity_view', 'weight' => 10])->setDisplayConfigurable('view', TRUE);
    $fields['last_generated_audio'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Last generated audio'))->setDescription(new TranslatableMarkup('The file created by the most recent successful generation.'))->setSetting('target_type', 'file')->setCardinality(1)->setDisplayOptions('view', ['label' => 'above', 'type' => 'ai_narration_audio_player', 'weight' => 10])->setDisplayConfigurable('view', TRUE);
    $fields['generation_metadata'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Last generation metadata'))->setDescription(new TranslatableMarkup('Structured metadata from the most recent successful generation.'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 11]);
    $fields['status'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Status'))->setSettings(['allowed_values' => ['draft' => 'Draft', 'queued' => 'Queued', 'generating' => 'Generating', 'complete' => 'Complete', 'failed' => 'Failed']])->setDefaultValue('draft')->setDisplayOptions('view', ['label' => 'above', 'type' => 'list_default', 'weight' => 5])->setDisplayConfigurable('view', TRUE);
    $fields['error_message'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Last generation error'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'basic_string', 'weight' => 20])->setDisplayConfigurable('view', TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 90])->setDisplayConfigurable('view', TRUE);
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'))->setDisplayOptions('view', ['label' => 'above', 'type' => 'timestamp', 'weight' => 91])->setDisplayConfigurable('view', TRUE);
    return $fields;
  }

}
