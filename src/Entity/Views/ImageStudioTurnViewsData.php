<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Entity\Views;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for AI Image Studio turns.
 */
final class ImageStudioTurnViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();
    $table = $this->entityType->getBaseTable();

    $data[$table]['table']['group'] = $this->t('AI Image Studio — Turns');
    $data[$table]['table']['base']['title'] = $this->t('AI Image Studio turns');
    $data[$table]['table']['base']['help'] = $this->t('Prompts and generated assets belonging to Studio sessions.');
    $data[$table]['table']['base']['cache_contexts'] = [
      'user',
      'user.permissions',
    ];

    $data[$table]['duration_ms']['field']['id'] = 'ai_image_studio_duration';
    $data[$table]['duration_ms']['field']['help'] = $this->t('Displays generation time using a readable unit.');
    $data[$table]['estimated_cost']['field']['id'] = 'ai_image_studio_cost';
    $data[$table]['estimated_cost']['field']['help'] = $this->t('Displays the estimated generation cost as a dollar amount.');

    foreach ([
      'generation_settings' => $this->t('Generation settings'),
      'token_usage' => $this->t('Token usage'),
      'provider_metadata' => $this->t('Provider metadata'),
    ] as $field_name => $title) {
      // Map fields have no statically declared properties, so generic entity
      // Views data does not expose them.
      $data[$table][$field_name] = [
        'title' => $title,
        'help' => $this->t('Displays structured data as safe, formatted JSON.'),
        'field' => [
          'id' => 'ai_image_studio_map',
          'field' => $field_name,
          'click sortable' => FALSE,
        ],
      ];
    }

    return $data;
  }

}
