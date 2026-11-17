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
    $label = $this->entityType->getCollectionLabel();
    $data[$table]['table']['group'] = $this->t('AI Narration — @label', ['@label' => $label]);
    $data[$table]['table']['base']['title'] = $this->t('AI Narration @label', ['@label' => $label]);
    $data[$table]['table']['base']['help'] = $this->entityType->getEntityTypeId() === 'ai_narration_project'
      ? $this->t('Narration projects and their shared text-to-speech defaults.')
      : $this->t('Narration chapters, generated audio, and last-generation metadata.');
    $data[$table]['table']['base']['cache_contexts'] = ['user', 'user.permissions'];

    if ($this->entityType->getEntityTypeId() === 'ai_narration_project') {
      $data[$table]['chapters'] = [
        'title' => $this->t('Project chapters'),
        'relationship' => [
          'id' => 'standard',
          'base' => 'ai_narration_chapter',
          'base field' => 'project_id',
          'relationship field' => 'id',
          'label' => $this->t('Chapters in this project'),
        ],
      ];
    }
    else {
      $data[$table]['project_id']['relationship']['label'] = $this->t('Narration project');
      $data[$table]['last_generated_audio']['field']['help'] = $this->t('The file from the latest successful generation.');
      $data[$table]['generation_metadata']['field']['help'] = $this->t('Stored model, request, file, timing, and runtime metadata from the latest generation.');
    }
    return $data;
  }

}
