<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity\Views;

use Drupal\views\EntityViewsData;

/**
 * Exposes narrative fields and relationships with user-aware caching.
 */
final class NarrativeViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();
    $table = $this->entityType->getBaseTable();
    $data[$table]['table']['group'] = $this->t('AI Storyboard — @label', ['@label' => $this->entityType->getCollectionLabel()]);
    $data[$table]['table']['base']['title'] = $this->t('AI Storyboard @label', ['@label' => $this->entityType->getCollectionLabel()]);
    $data[$table]['table']['base']['cache_contexts'] = ['user', 'user.permissions'];
    if ($table === 'ai_storyboard_scene') {
      $data[$table]['shots'] = [
        'title' => $this->t('Scene shots'),
        'relationship' => [
          'id' => 'standard',
          'base' => 'ai_storyboard_shot',
          'base field' => 'scene_id',
          'relationship field' => 'id',
          'label' => $this->t('Shots in this scene'),
        ],
      ];
    }
    return $data;
  }

}
