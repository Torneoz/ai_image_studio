<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Shared fields and pinned library snapshots for narrative records.
 */
abstract class NarrativeEntity extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['title'] = self::text('Name', FALSE)->setRequired(TRUE);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    $id = $entity_type->id();
    if (in_array($id, ['ai_storyboard_location', 'ai_storyboard_character'], TRUE)) {
      $fields['bible'] = self::text($id === 'ai_storyboard_location' ? 'Location bible' : 'Character bible')->setRequired(TRUE);
      $fields['voice'] = self::text('Voice description');
      $fields['references'] = BaseFieldDefinition::create('file')
        ->setLabel(new TranslatableMarkup('Reference images'))
        ->setCardinality(-1)
        ->setSettings([
          'file_extensions' => 'png jpg jpeg webp',
          'uri_scheme' => 'private',
          'file_directory' => 'ai-storyboard-references',
        ])
        ->setDisplayOptions('form', ['type' => 'file_generic', 'weight' => 10]);
      if ($id === 'ai_storyboard_location') {
        unset($fields['voice']);
      }
      foreach ($fields as $name => $field) {
        if (!in_array($name, ['id', 'uuid', 'revision_id'], TRUE)) {
          $field->setRevisionable(TRUE);
        }
      }
    }
    else {
      $fields['storyboard_id'] = self::reference('Project', 'ai_storyboard')->setRequired(TRUE);
      $is_scene = $id === 'ai_storyboard_scene';
      $fields['source_id'] = self::reference($is_scene ? 'Shared location' : 'Shared character', $is_scene ? 'ai_storyboard_location' : 'ai_storyboard_character');
      $fields['source_revision'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Pinned library version'));
      $fields['source_label'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Pinned library name'))->setSetting('max_length', 255);
      $fields['bible_snapshot'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Pinned bible'));
      $fields['voice_snapshot'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Pinned voice'));
      $fields['reference_snapshot'] = BaseFieldDefinition::create('entity_reference')
        ->setLabel(new TranslatableMarkup('Pinned reference images'))->setSetting('target_type', 'file')->setCardinality(-1);
      $fields['overrides'] = self::text($is_scene ? 'Scene location overrides' : 'Project appearance and voice overrides');
      if ($is_scene) {
        $fields['scene_number'] = BaseFieldDefinition::create('integer')
          ->setLabel(new TranslatableMarkup('Scene number'))->setDefaultValue(1)->setRequired(TRUE)
          ->setSetting('unsigned', TRUE)->setDisplayOptions('form', ['type' => 'number', 'weight' => 1]);
        foreach ([
          'script_excerpt' => 'Script excerpt',
          'conditions' => 'Interior/exterior, time of day and weather',
          'action' => 'Scene action and beats',
          'character_states' => 'Scene character appearances and temporary states',
        ] as $name => $label) {
          $fields[$name] = self::text($label);
        }
        $fields['cast_ids'] = self::reference('Characters present', 'ai_storyboard_cast')->setCardinality(-1);
      }
    }
    return $fields;
  }

  /**
   * Defines an editable text field.
   */
  private static function text(string $label, bool $long = TRUE): BaseFieldDefinition {
    $field = BaseFieldDefinition::create($long ? 'string_long' : 'string')
      ->setLabel(new TranslatableMarkup($label))
      ->setDisplayOptions('form', ['type' => $long ? 'string_textarea' : 'string_textfield', 'weight' => 5]);
    if (!$long) {
      $field->setSetting('max_length', 255);
    }
    return $field;
  }

  /**
   * Defines an editable entity reference.
   */
  private static function reference(string $label, string $type): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup($label))->setSetting('target_type', $type)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 2]);
  }

  /**
   * Explicitly adopts the currently selected library version.
   */
  public function refreshLibrary(?int $revision_id = NULL): void {
    $source_id = $this->get('source_id')->target_id;
    $source_type = $this->get('source_id')->getFieldDefinition()->getSetting('target_type');
    $source = $source_id ? \Drupal::entityTypeManager()->getStorage($source_type)->loadUnchanged($source_id) : NULL;
    if ($revision_id && $source) {
      $revision = \Drupal::entityTypeManager()->getStorage($source->getEntityTypeId())->loadRevision($revision_id);
      if (!$revision || $revision->id() != $source->id()) {
        throw new \InvalidArgumentException('The selected version does not belong to this library record.');
      }
      $source = $revision;
    }
    $this->set('source_revision', $source?->getRevisionId());
    $this->set('source_label', $source?->label() ?? '');
    $this->set('bible_snapshot', $source?->get('bible')->value ?? '');
    $this->set('voice_snapshot', $source && $source->hasField('voice') ? $source->get('voice')->value : '');
    $this->set('reference_snapshot', array_map(static fn(array $item): array => ['target_id' => $item['target_id']], $source?->get('references')->getValue() ?? []));
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->getEntityType()->isRevisionable()) {
      $this->setNewRevision(TRUE);
    }
    else {
      $original = $this->isNew() ? NULL : $storage->loadUnchanged($this->id());
      if ($original && $original->get('storyboard_id')->target_id != $this->get('storyboard_id')->target_id) {
        throw new \InvalidArgumentException('Narrative records cannot be moved between projects.');
      }
      if ($this->hasField('cast_ids')) {
        foreach ($this->get('cast_ids')->referencedEntities() as $cast) {
          if ($cast->get('storyboard_id')->target_id != $this->get('storyboard_id')->target_id) {
            throw new \InvalidArgumentException('Scene characters must belong to the same project.');
          }
        }
      }
      $revision_id = $this->get('source_revision')->value;
      $source_type = $this->get('source_id')->getFieldDefinition()->getSetting('target_type');
      $pinned = $revision_id ? \Drupal::entityTypeManager()->getStorage($source_type)->loadRevision($revision_id) : NULL;
      if (!$pinned || $pinned->id() != $this->get('source_id')->target_id || ($original && $this->get('source_id')->target_id != $original->get('source_id')->target_id)) {
        $this->refreshLibrary();
      }
    }
  }

  /**
   * Keeps the legacy scene-number field synchronized for Views and exports.
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    if ($update && $this->getEntityTypeId() === 'ai_storyboard_scene') {
      $shots = \Drupal::entityTypeManager()->getStorage('ai_storyboard_shot');
      $ids = $shots->getQuery()->accessCheck(FALSE)->condition('scene_id', $this->id())->condition('scene_number', $this->get('scene_number')->value, '<>')->execute();
      foreach ($shots->loadMultiple($ids) as $shot) {
        $shot->set('scene_number', $this->get('scene_number')->value)->save();
      }
    }
  }

}
