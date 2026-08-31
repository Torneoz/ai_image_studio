<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Entity;

use Drupal\ai_image_studio\Access\TurnAccessControlHandler;
use Drupal\ai_image_studio\Entity\Views\ImageStudioTurnViewsData;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores one prompt and its generated image.
 */
#[ContentEntityType(
  id: 'ai_image_studio_turn',
  label: new TranslatableMarkup('AI Image Studio turn'),
  label_collection: new TranslatableMarkup('AI Image Studio turns'),
  label_singular: new TranslatableMarkup('image studio turn'),
  label_plural: new TranslatableMarkup('image studio turns'),
  handlers: [
    'access' => TurnAccessControlHandler::class,
    'views_data' => ImageStudioTurnViewsData::class,
  ],
  base_table: 'ai_image_studio_turn',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  admin_permission: 'administer ai image studio',
)]
final class ImageStudioTurn extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['session_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Session'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ai_image_studio_session');

    $fields['parent_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Parent turn'))
      ->setSetting('target_type', 'ai_image_studio_turn');

    $fields['source_file_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Source file'))
      ->setSetting('target_type', 'file');

    $fields['source_file_ids'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Ordered source files'))
      ->setSetting('target_type', 'file')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED);

    $fields['request_group'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Request group'))
      ->setSetting('max_length', 64);

    $fields['sequence'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Session sequence'))
      ->setDescription(new TranslatableMarkup('Creation order of the logical request within its session.'))
      ->setSetting('unsigned', TRUE);

    $fields['replay_of'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Replay of'))
      ->setSetting('target_type', 'ai_image_studio_turn');

    $fields['prompt'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Prompt'))
      ->setRequired(TRUE);

    $fields['image'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Generated image'))
      ->setSetting('file_extensions', 'png jpg jpeg webp gif')
      ->setSetting('file_directory', 'ai-image-studio');

    $fields['video'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Generated video'))
      ->setSetting('file_extensions', 'mp4')
      ->setSetting('file_directory', 'ai-image-studio');

    $fields['last_frame'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('Video last frame'))
      ->setSetting('file_extensions', 'png')
      ->setSetting('file_directory', 'ai-image-studio');

    $fields['provider_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setSetting('max_length', 128);

    $fields['model_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Model'))
      ->setSetting('max_length', 255);

    $fields['operation'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Operation'))
      ->setSettings([
        'allowed_values' => [
          'text_to_image' => new TranslatableMarkup('Text to image'),
          'image_to_image' => new TranslatableMarkup('Image to image'),
          'text_to_video' => new TranslatableMarkup('Text to video'),
          'image_to_video' => new TranslatableMarkup('Image to video'),
          'reference_to_video' => new TranslatableMarkup('Reference to video'),
        ],
      ]);

    $fields['generation_settings'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Effective generation settings'));

    $fields['requested_generation_settings'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Requested generation settings'));

    $fields['duration_ms'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Generation duration'))
      ->setSetting('unsigned', TRUE);

    $fields['estimated_cost'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Estimated cost'))
      ->setSetting('precision', 14)
      ->setSetting('scale', 8);

    $fields['cost_source'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Cost source'))
      ->setSettings([
        'allowed_values' => [
          'reported' => new TranslatableMarkup('Provider reported'),
          'estimated' => new TranslatableMarkup('Estimated'),
          'unavailable' => new TranslatableMarkup('Unavailable'),
        ],
      ])
      ->setDefaultValue('unavailable');

    $fields['token_usage'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Token usage'));

    $fields['provider_metadata'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Provider metadata'));

    $fields['provider_request_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider request ID'))
      ->setSetting('max_length', 255);

    $fields['attempt_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Generation attempts'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'pending' => new TranslatableMarkup('Pending'),
          'queued' => new TranslatableMarkup('Queued'),
          'processing' => new TranslatableMarkup('Processing'),
          'completed' => new TranslatableMarkup('Completed'),
          'failed' => new TranslatableMarkup('Failed'),
          'expired' => new TranslatableMarkup('Expired'),
          'cancelled' => new TranslatableMarkup('Cancelled'),
        ],
      ])
      ->setDefaultValue('pending');

    $fields['error_message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Error message'));

    $fields['media_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Published Media'))
      ->setSetting('target_type', 'media');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    return $fields;
  }

}
