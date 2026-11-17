<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/** Edits optional Project editions. */
final class NarrationEditionForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $options = [];
    foreach ($this->entityTypeManager->getStorage('ai_narration_project')->loadMultiple() as $project) {
      $options[$project->id()] = (string) $project->label();
    }
    $project = &$form['project_id']['widget'][0]['target_id'];
    $project['#type'] = 'select';
    $project['#options'] = $options;
    $project['#empty_option'] = $this->t('- Select a project -');
    $project['#multiple'] = FALSE;
    $project['#size'] = 1;
    $project['#default_value'] = $this->entity->get('project_id')->target_id ?: '';
    $project['#description'] = $this->t('Choose the Project this optional Edition groups. Chapters may also belong directly to a Project without an Edition.');
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Narration edition %title has been saved.', ['%title' => $this->entity->label()]));
    $form_state->setRedirect('entity.ai_narration_edition.collection');
    return $result;
  }

}
