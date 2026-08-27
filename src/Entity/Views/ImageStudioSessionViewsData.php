<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Entity\Views;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for AI Image Studio sessions.
 */
final class ImageStudioSessionViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();
    $table = $this->entityType->getBaseTable();

    $data[$table]['table']['group'] = $this->t('AI Image Studio — Sessions');
    $data[$table]['table']['base']['title'] = $this->t('AI Image Studio sessions');
    $data[$table]['table']['base']['help'] = $this->t('Studio conversations owned by users.');
    $data[$table]['table']['base']['cache_contexts'] = [
      'user',
      'user.permissions',
    ];

    // Base-field entity references do not receive the reverse relationship
    // that Views creates automatically for configurable reference fields.
    $data[$table]['turns'] = [
      'title' => $this->t('Session turns'),
      'help' => $this->t('Relate a session to each prompt and generation turn it contains.'),
      'relationship' => [
        'id' => 'standard',
        'base' => 'ai_image_studio_turn',
        'base field' => 'session_id',
        'relationship field' => 'id',
        'label' => $this->t('Session turns'),
      ],
    ];

    return $data;
  }

}
