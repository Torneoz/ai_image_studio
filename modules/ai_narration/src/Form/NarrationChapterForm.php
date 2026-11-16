<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Uses a project selector for narration chapters.
 */
final class NarrationChapterForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $projects = $this->entityTypeManager->getStorage('ai_narration_project')->loadMultiple();
    $options = [];
    foreach ($projects as $project) {
      $options[$project->id()] = (string) $project->label();
    }
    $element =& $form['project_id']['widget'][0]['target_id'];
    $element['#type'] = 'select';
    $element['#options'] = $options;
    $element['#default_value'] = $this->entity->get('project_id')->target_id;
    $element['#empty_option'] = $this->t('- Select a project -');
    $element['#multiple'] = FALSE;
    $element['#size'] = 1;
    $element['#description'] = $this->t('Choose the narration project whose settings this chapter will inherit.');
    return $form;
  }

}
