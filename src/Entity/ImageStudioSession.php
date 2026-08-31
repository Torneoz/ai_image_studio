<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Entity;

use Drupal\ai_image_studio\Entity\Views\ImageStudioSessionViewsData;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Stores an image generation conversation.
 */
#[ContentEntityType(
  id: 'ai_image_studio_session',
  label: new TranslatableMarkup('AI Image Studio session'),
  label_collection: new TranslatableMarkup('AI Image Studio sessions'),
  label_singular: new TranslatableMarkup('image studio session'),
  label_plural: new TranslatableMarkup('image studio sessions'),
  handlers: [
    'access' => 'Drupal\ai_image_studio\Access\SessionAccessControlHandler',
    'views_data' => ImageStudioSessionViewsData::class,
    'form' => [
      'delete' => 'Drupal\ai_image_studio\Form\SessionDeleteForm',
    ],
  ],
  base_table: 'ai_image_studio_session',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
    'owner' => 'uid',
  ],
  admin_permission: 'administer ai image studio',
  links: [
    'canonical' => '/admin/content/ai-image-studio/{ai_image_studio_session}',
    'delete-form' => '/admin/content/ai-image-studio/{ai_image_studio_session}/delete',
    'collection' => '/admin/content/ai-image-studio',
  ],
)]
final class ImageStudioSession extends ContentEntityBase implements EntityOwnerInterface {

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
      ->setSetting('max_length', 255);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'active' => new TranslatableMarkup('Active'),
          'archived' => new TranslatableMarkup('Archived'),
        ],
      ])
      ->setDefaultValue('active');

    $fields['replay_source_session_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Replay source session'))
      ->setSetting('target_type', 'ai_image_studio_session');

    $fields['replay_state'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Replay state'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
