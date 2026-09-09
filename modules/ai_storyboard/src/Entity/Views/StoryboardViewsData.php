<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity\Views;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for AI Storyboard projects.
 */
final class StoryboardViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();
    $table = $this->entityType->getBaseTable();

    $data[$table]['table']['group'] = $this->t('AI Storyboard — Projects');
    $data[$table]['table']['base']['title'] = $this->t('AI Storyboards');
    $data[$table]['table']['base']['help'] = $this->t('Storyboard projects created from scripts.');
    $data[$table]['table']['base']['cache_contexts'] = [
      'user',
      'user.permissions',
    ];

    // Base-field entity references do not get automatic reverse
    // relationships, so explicitly relate projects to their ordered shots.
    $data[$table]['shots'] = [
      'title' => $this->t('Storyboard shots'),
      'help' => $this->t('Relate a storyboard project to each shot it contains.'),
      'relationship' => [
        'id' => 'standard',
        'base' => 'ai_storyboard_shot',
        'base field' => 'storyboard_id',
        'relationship field' => 'id',
        'label' => $this->t('Storyboard shots'),
      ],
    ];

    foreach (['scene' => $this->t('Scenes'), 'cast' => $this->t('Project characters')] as $suffix => $label) {
      $data[$table][$suffix] = [
        'title' => $label,
        'relationship' => [
          'id' => 'standard',
          'base' => 'ai_storyboard_' . $suffix,
          'base field' => 'storyboard_id',
          'relationship field' => 'id',
          'label' => $label,
        ],
      ];
    }
    return $data;
  }

}
