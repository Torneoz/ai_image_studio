<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Entity\Views;

use Drupal\views\EntityViewsData;

/**
 * Provides Views data for AI Storyboard shots.
 */
final class StoryboardShotViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();
    $table = $this->entityType->getBaseTable();

    $data[$table]['table']['group'] = $this->t('AI Storyboard — Shots');
    $data[$table]['table']['base']['title'] = $this->t('AI Storyboard shots');
    $data[$table]['table']['base']['help'] = $this->t('Ordered production shots belonging to storyboard projects.');
    $data[$table]['table']['base']['cache_contexts'] = [
      'user',
      'user.permissions',
    ];

    $data[$table]['duration']['field']['help'] = $this->t('Displays the planned shot duration in seconds.');
    $data[$table]['position']['field']['help'] = $this->t('The shot order within its storyboard project.');
    $data[$table]['studio_turn_id']['relationship']['label'] = $this->t('Generated Image Studio turn');
    $data[$table]['storyboard_id']['relationship']['label'] = $this->t('Storyboard project');

    return $data;
  }

}
