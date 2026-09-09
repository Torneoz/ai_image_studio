<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits the production and prompt details of one shot. */
final class ShotForm extends FormBase {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'ai_storyboard_shot_form';
  }

  /**
   *
   */
  public function title(object $ai_storyboard_shot): string {
    return (string) $this->t('Edit shot: @title', ['@title' => $ai_storyboard_shot->label()]);
  }

  /**
   * {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state, ?object $ai_storyboard = NULL, ?object $ai_storyboard_shot = NULL): array {
    if (!$ai_storyboard_shot || (int) $ai_storyboard_shot->get('storyboard_id')->target_id !== (int) $ai_storyboard?->id()) {
      throw new NotFoundHttpException();
    }
    $form_state->set('shot_id', $ai_storyboard_shot->id());
    $form_state->set('storyboard_id', $ai_storyboard->id());
    foreach (['scene_id' => ['ai_storyboard_scene', 'Scene'], 'speaker_id' => ['ai_storyboard_cast', 'Dialogue speaker']] as $field => [$type, $label]) {
      $storage = $this->entityTypeManager->getStorage($type);
      $ids = $storage->getQuery()->accessCheck(TRUE)->condition('storyboard_id', $ai_storyboard->id())->sort('title')->execute();
      $options = [];
      foreach ($storage->loadMultiple($ids) as $entity) {
        $options[$entity->id()] = $entity->label();
      }
      $form[$field] = ['#type' => 'select', '#title' => $this->t($label), '#options' => $options, '#empty_option' => $this->t('- None -'), '#default_value' => $ai_storyboard_shot->get($field)->target_id];
    }
    $fields = [
      'title' => ['textfield', $this->t('Title'), TRUE],
      'position' => ['number', $this->t('Position'), TRUE],
      'scene_number' => ['number', $this->t('Scene number'), TRUE],
      'shot_number' => ['number', $this->t('Shot number'), TRUE],
      'duration' => ['number', $this->t('Duration (seconds)'), TRUE],
      'shot_size' => ['textfield', $this->t('Shot size'), FALSE],
      'camera_angle' => ['textfield', $this->t('Camera angle'), FALSE],
      'camera_move' => ['textfield', $this->t('Camera movement'), FALSE],
      'lens' => ['textfield', $this->t('Lens'), FALSE],
      'lighting' => ['textfield', $this->t('Lighting'), FALSE],
      'action' => ['textarea', $this->t('Action'), FALSE],
      'dialogue' => ['textarea', $this->t('Dialogue / voice-over'), FALSE],
      'audio' => ['textarea', $this->t('Sound / music'), FALSE],
      'image_prompt' => ['textarea', $this->t('Image prompt'), TRUE],
      'continuity_notes' => ['textarea', $this->t('Continuity notes'), FALSE],
    ];
    foreach ($fields as $name => [$type, $title, $required]) {
      $form[$name] = ['#type' => $type, '#title' => $title, '#required' => $required, '#default_value' => $ai_storyboard_shot->get($name)->value];
      if ($type === 'number') {
        $form[$name]['#min'] = $name === 'duration' ? 0.1 : 1;
        $form[$name]['#step'] = $name === 'duration' ? 0.1 : 1;
      }
      if ($type === 'textarea') {
        $form[$name]['#rows'] = 3;
      }
    }
    $form['dialogue']['#description'] = $this->t('Spoken lines for video generation. Include speaker names, language, and delivery, e.g. Lina (quietly): "Where am I?" Keep lines short enough for the clip duration. Spoken audio depends on model support.');
    $form['scene_number']['#description'] = $this->t('When a Scene is selected, its scene number is used automatically.');
    $form['audio']['#description'] = $this->t('Shot-specific sound effects, ambience, and music, included alongside the project audio prompt.');
    $form['status'] = ['#type' => 'select', '#title' => $this->t('Status'), '#options' => ['draft' => $this->t('Draft'), 'generated' => $this->t('Generated'), 'approved' => $this->t('Approved')], '#default_value' => $ai_storyboard_shot->get('status')->value];
    $form['actions'] = ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => $this->t('Save shot'), '#button_type' => 'primary']];
    return $form;
  }

  /**
   * {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $shot = $this->entityTypeManager->getStorage('ai_storyboard_shot')->load($form_state->get('shot_id'));
    foreach (['title', 'position', 'scene_number', 'shot_number', 'duration', 'shot_size', 'camera_angle', 'camera_move', 'lens', 'lighting', 'action', 'dialogue', 'audio', 'image_prompt', 'continuity_notes', 'status', 'scene_id', 'speaker_id'] as $field) {
      $shot->set($field, $form_state->getValue($field));
    }
    $shot->save();
    $this->messenger()->addStatus($this->t('The shot has been saved.'));
    $form_state->setRedirect('entity.ai_storyboard.canonical', ['ai_storyboard' => $form_state->get('storyboard_id')]);
  }

}
