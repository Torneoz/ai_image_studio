<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/** Edits reusable narration promos. */
final class NarrationPromoForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $options = [];
    foreach ($this->entityTypeManager->getStorage('ai_narration_project')->loadMultiple() as $project) {
      $options[$project->id()] = (string) $project->label();
    }
    $project = &$form['project_id']['widget'][0]['target_id'];
    $project['#type'] = 'select';
    $project['#options'] = $options;
    $project['#empty_option'] = $this->t('- Global promo (no project) -');
    $project['#multiple'] = FALSE;
    $project['#size'] = 1;
    $project['#description'] = $this->t('Optional. Choose a Project for organisation, or leave this Promo globally reusable.');
    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Narration promo %title has been saved.', ['%title' => $this->entity->label()]));
    $form_state->setRedirect('entity.ai_narration_promo.collection');
    return $result;
  }

}
