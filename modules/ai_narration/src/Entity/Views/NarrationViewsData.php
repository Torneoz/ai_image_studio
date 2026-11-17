<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Entity\Views;

use Drupal\views\EntityViewsData;

/**
 * Provides first-class Views integration for Narration entities.
 */
final class NarrationViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData(): array {
    $data = parent::getViewsData();
    $table = $this->entityType->getBaseTable();
    $id = $this->entityType->id();
    $is_project = $id === 'ai_narration_project';
    $data[$table]['table']['group'] = $this->t('AI Narration');
    $data[$table]['table']['base']['title'] = $is_project
      ? $this->t('AI Narration Project')
      : $this->t('AI Narration Chapter');
    $data[$table]['table']['base']['help'] = $is_project
      ? $this->t('Narration projects and their shared text-to-speech defaults.')
      : $this->t('Narration content and metadata.');
    $data[$table]['table']['base']['cache_contexts'] = ['user', 'user.permissions'];

    if ($is_project) {
      $data[$table]['chapters'] = [
        'title' => $this->t('AI Narration Chapters'),
        'relationship' => [
          'id' => 'standard',
          'base' => 'ai_narration_chapter',
          'base field' => 'project_id',
          'relationship field' => 'id',
          'label' => $this->t('AI Narration Chapters'),
        ],
      ];
    }
    elseif ($id === 'ai_narration_chapter') {
      $data[$table]['project_id']['relationship']['label'] = $this->t('AI Narration Project');
      $data[$table]['project_id']['relationship']['title'] = $this->t('AI Narration Project');
      $data[$table]['last_generated_audio']['field']['help'] = $this->t('The file from the latest successful generation.');
      $data[$table]['generation_metadata']['field']['help'] = $this->t('Stored model, request, file, timing, and runtime metadata from the latest generation.');
    }
    return $data;
  }

}
