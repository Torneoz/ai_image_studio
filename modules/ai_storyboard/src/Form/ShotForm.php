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
    $form['status'] = ['#type' => 'select', '#title' => $this->t('Status'), '#options' => ['draft' => $this->t('Draft'), 'generated' => $this->t('Generated'), 'approved' => $this->t('Approved')], '#default_value' => $ai_storyboard_shot->get('status')->value];
    $form['actions'] = ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => $this->t('Save shot'), '#button_type' => 'primary']];
    return $form;
  }

  /**
   * {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $shot = $this->entityTypeManager->getStorage('ai_storyboard_shot')->load($form_state->get('shot_id'));
    foreach (['title', 'position', 'scene_number', 'shot_number', 'duration', 'shot_size', 'camera_angle', 'camera_move', 'lens', 'lighting', 'action', 'dialogue', 'audio', 'image_prompt', 'continuity_notes', 'status'] as $field) {
      $shot->set($field, $form_state->getValue($field));
    }
    $shot->save();
    $this->messenger()->addStatus($this->t('The shot has been saved.'));
    $form_state->setRedirect('entity.ai_storyboard.canonical', ['ai_storyboard' => $form_state->get('storyboard_id')]);
  }

}
