<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Edits shared library definitions and project-specific pinned uses.
 */
final class NarrativeForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    if ($this->entity->isNew() && $this->entity->getEntityTypeId() === 'ai_storyboard_scene' && !$form_state->get('scene_copied')) {
      $copy_id = (int) $this->getRequest()->query->get('copy', 0);
      $source = $copy_id ? $this->entityTypeManager->getStorage('ai_storyboard_scene')->load($copy_id) : NULL;
      if ($source && $source->access('view')) {
        foreach ([
          'title', 'source_id', 'source_revision', 'source_label',
          'bible_snapshot', 'reference_snapshot', 'overrides',
          'script_excerpt', 'conditions', 'action', 'character_states',
        ] as $field) {
          $this->entity->set($field, $source->get($field)->getValue());
        }
        $form_state->set('scene_copied', TRUE);
      }
    }
    if ($this->entity->isNew() && $this->entity->hasField('storyboard_id')) {
      $id = (int) $this->getRequest()->query->get('project', 0);
      $project = $id ? $this->entityTypeManager->getStorage('ai_storyboard')->load($id) : NULL;
      if ($project && $project->access('update')) {
        $this->entity->set('storyboard_id', $id);
      }
    }
    $form = parent::form($form, $form_state);
    if ($this->entity->hasField('bible')) {
      $form['guidance'] = [
        '#type' => 'item',
        '#weight' => -20,
        '#markup' => $this->t('Each save creates a library version. Existing projects keep their pinned bible, voice and reference images until explicitly refreshed. Reference images are supplied to frame generation for models supporting multi-image input; other models use the bible text.'),
      ];
    }
    if ($this->entity->hasField('source_id')) {
      $form['library_version'] = [
        '#type' => 'item',
        '#title' => $this->t('Pinned library version'),
        '#plain_text' => (string) ($this->entity->get('source_revision')->value ?: 'Not selected'),
      ];
      $form['refresh_library'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Refresh from the latest library version when saving'),
        '#description' => $this->t('Updates the pinned bible, voice and reference images. Your project or scene overrides remain unchanged.'),
      ];
      $source = $this->entity->get('source_id')->entity;
      if ($source) {
        $storage = $this->entityTypeManager->getStorage($source->getEntityTypeId());
        $versions = $storage->getQuery()->allRevisions()->accessCheck(TRUE)->condition('id', $source->id())->sort('revision_id', 'DESC')->execute();
        $options = [];
        foreach (array_keys($versions) as $revision_id) {
          $options[$revision_id] = $this->t('Version @id', ['@id' => $revision_id]);
        }
        $form['selected_version'] = [
          '#type' => 'select',
          '#title' => $this->t('Library version'),
          '#options' => $options,
          '#default_value' => $this->entity->get('source_revision')->value,
          '#description' => $this->t('Select a saved version, or check refresh to adopt the latest. Changing the library selection pins its latest version.'),
        ];
      }
      $form['pinned_bible'] = [
        '#type' => 'details',
        '#title' => $this->t('Pinned bible'),
        'text' => ['#plain_text' => (string) $this->entity->get('bible_snapshot')->value],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $entity = parent::validateForm($form, $form_state);
    if ($entity->hasField('storyboard_id')) {
      $project = $entity->get('storyboard_id')->entity;
      if (!$project || !$project->access('update')) {
        $form_state->setErrorByName('storyboard_id', $this->t('Choose a project you can edit.'));
      }
      $original = $entity->isNew() ? NULL : $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
      if ($original && $original->get('storyboard_id')->target_id != $entity->get('storyboard_id')->target_id) {
        $form_state->setErrorByName('storyboard_id', $this->t('Existing records cannot be moved between projects.'));
      }
      if ($entity->hasField('cast_ids')) {
        $duplicates = $this->entityTypeManager->getStorage('ai_storyboard_scene')->getQuery()->accessCheck(FALSE)->condition('storyboard_id', $entity->get('storyboard_id')->target_id)->condition('scene_number', $entity->get('scene_number')->value);
        if (!$entity->isNew()) {
          $duplicates->condition('id', $entity->id(), '<>');
        }
        if ($duplicates->count()->execute()) {
          $form_state->setErrorByName('scene_number', $this->t('This project already has a scene with that number. Choose another number.'));
        }
        foreach ($entity->get('cast_ids') as $item) {
          if ($item->entity && $item->entity->get('storyboard_id')->target_id != $entity->get('storyboard_id')->target_id) {
            $form_state->setErrorByName('cast_ids', $this->t('Scene characters must belong to this project.'));
          }
        }
      }
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    if ($this->entity->hasField('source_id') && $form_state->getValue('refresh_library')) {
      $this->entity->refreshLibrary();
    }
    elseif (!$this->entity->isNew() && $this->entity->hasField('source_id') && $form_state->getValue('selected_version')) {
      $original = $this->entityTypeManager->getStorage($this->entity->getEntityTypeId())->loadUnchanged($this->entity->id());
      if ($original && $original->get('source_id')->target_id == $this->entity->get('source_id')->target_id) {
        $this->entity->refreshLibrary((int) $form_state->getValue('selected_version'));
      }
    }
    $status = $this->entity->save();
    $this->messenger()->addStatus($this->t('Saved @name.', ['@name' => $this->entity->label()]));
    $form_state->setRedirect('entity.' . $this->entity->getEntityTypeId() . '.edit_form', [$this->entity->getEntityTypeId() => $this->entity->id()]);
    return $status;
  }

}
