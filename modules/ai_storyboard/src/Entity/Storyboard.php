<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity;

use Drupal\ai_storyboard\Entity\Views\StoryboardViewsData;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Stores a storyboard project and its creative continuity bible.
 */
#[ContentEntityType(
  id: 'ai_storyboard',
  label: new TranslatableMarkup('AI storyboard'),
  label_collection: new TranslatableMarkup('AI storyboards'),
  handlers: [
    'access' => 'Drupal\ai_storyboard\Access\StoryboardAccessControlHandler',
    'views_data' => StoryboardViewsData::class,
    'form' => ['delete' => 'Drupal\ai_storyboard\Form\StoryboardDeleteForm'],
  ],
  base_table: 'ai_storyboard',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
    'owner' => 'uid',
  ],
  admin_permission: 'administer ai storyboard',
  links: [
    'canonical' => '/admin/content/ai-storyboard/{ai_storyboard}',
    'delete-form' => '/admin/content/ai-storyboard/{ai_storyboard}/delete',
    'collection' => '/admin/content/ai-storyboard',
  ],
)]
final class Storyboard extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc} */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setRequired(TRUE)->setSetting('max_length', 255);
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Owner'))->setSetting('target_type', 'user')->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');
    $fields['script'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Script'))->setRequired(TRUE);
    $fields['creative_brief'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Creative brief'));
    $fields['after_prompt'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('After prompt'))
      ->setDescription(new TranslatableMarkup('Instructions appended to every generated shot prompt.'));
    $fields['continuity_bible'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Continuity bible'));
    $fields['character_bible'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Character bible'));
    $fields['visual_style'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Visual style'))->setSetting('max_length', 255)->setDefaultValue('cinematic storyboard sketch');
    $fields['aspect_ratio'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Aspect ratio'))->setSettings(['allowed_values' => ['16:9' => '16:9 landscape', '9:16' => '9:16 portrait', '1:1' => '1:1 square', '4:3' => '4:3 classic', '2.39:1' => '2.39:1 anamorphic']])->setDefaultValue('16:9');
    $fields['chat_model'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Breakdown model'))->setSetting('max_length', 383);
    $fields['image_model'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Image model'))->setSetting('max_length', 383);
    $fields['video_model'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Video model'))->setSetting('max_length', 383);
    $fields['video_sequence_mode'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Video sequence mode'))->setSettings(['allowed_values' => ['animate' => 'Animate each keyframe', 'bridge' => 'Bridge consecutive keyframes']])->setDefaultValue('animate');
    $fields['video_duration'] = BaseFieldDefinition::create('integer')->setLabel(new TranslatableMarkup('Video duration'))->setSetting('unsigned', TRUE)->setDefaultValue(5);
    $fields['video_resolution'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Video resolution'))->setSettings(['allowed_values' => ['480p' => '480p', '720p' => '720p', '1080p' => '1080p']])->setDefaultValue('720p');
    $fields['video_prompt'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Video sequence prompt'));
    $fields['audio_prompt'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Audio prompt'))
      ->setDescription(new TranslatableMarkup('Project-wide audio instructions included in every video generation prompt.'));
    $fields['studio_session_id'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Image Studio session'))->setSetting('target_type', 'ai_image_studio_session');
    $fields['status'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Status'))->setSettings(['allowed_values' => ['draft' => 'Draft', 'in_review' => 'In review', 'approved' => 'Approved']])->setDefaultValue('draft');
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));
    return $fields;
  }

}
