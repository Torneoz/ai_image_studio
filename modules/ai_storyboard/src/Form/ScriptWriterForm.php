<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\ai_storyboard\Service\ScriptWriter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an AI assisted screenplay writing workspace. */
final class ScriptWriterForm extends FormBase {

  public function __construct(
    private readonly ScriptWriter $writer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ai_storyboard.script_writer'), $container->get('entity_type.manager'));
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'ai_storyboard_script_writer_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = $this->writer->getModelOptions();
    $form['#attached']['library'][] = 'ai_storyboard/workspace';
    $form['intro'] = ['#markup' => '<p>' . $this->t('Develop an idea into a story outline, draft it as a screenplay, then revise passages with focused notes. Generated text stays in this workspace until you choose what to save.') . '</p>'];
    $form['setup'] = ['#type' => 'details', '#title' => $this->t('Story setup'), '#open' => TRUE];
    $form['setup']['brief'] = ['#type' => 'textarea', '#title' => $this->t('What is the story?'), '#description' => $this->t('Describe the premise, genre, tone, audience, format, characters, themes, or constraints. Start with whatever you know.'), '#rows' => 5, '#required' => TRUE, '#default_value' => $form_state->getValue('brief', '')];
    $form['setup']['model'] = ['#type' => 'select', '#title' => $this->t('Writing model'), '#options' => $options, '#required' => TRUE, '#default_value' => $form_state->getValue('model') ?: (string) array_key_first($options)];
    $form['setup']['mode'] = ['#type' => 'radios', '#title' => $this->t('Writing step'), '#options' => ['outline' => $this->t('Develop an outline'), 'draft' => $this->t('Write a screenplay draft'), 'revise' => $this->t('Revise the source text')], '#default_value' => $form_state->getValue('mode', 'outline')];
    $form['source'] = ['#type' => 'textarea', '#title' => $this->t('Outline, notes, or screenplay text'), '#rows' => 14, '#default_value' => $form_state->getValue('source', ''), '#description' => $this->t('Use this for a draft source, existing outline, or the passage you want to revise. For a first outline, leave it blank.')];
    $form['instruction'] = ['#type' => 'textarea', '#title' => $this->t('Direction for this pass'), '#rows' => 3, '#default_value' => $form_state->getValue('instruction', ''), '#description' => $this->t('For revisions, give a focused note such as “make the confrontation more tense and cut the dialogue by a third.”')];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['generate'] = ['#type' => 'submit', '#value' => $this->t('Generate writing'), '#button_type' => 'primary'];
    if ($form_state->get('result')) {
      $form['result'] = ['#type' => 'details', '#title' => $this->t('Generated draft'), '#open' => TRUE, '#weight' => 10];
      $form['result']['text'] = ['#type' => 'textarea', '#title' => $this->t('Edit the result'), '#rows' => 24, '#default_value' => $form_state->get('result'), '#description' => $this->t('Edit freely before saving or using this text as the next step.')];
      $form['result']['actions'] = ['#type' => 'actions'];
      $form['result']['actions']['outline'] = ['#type' => 'submit', '#value' => $this->t('Use as outline'), '#submit' => ['::useAsOutline']];
      $form['result']['actions']['draft'] = ['#type' => 'submit', '#value' => $this->t('Draft screenplay from this'), '#submit' => ['::useAsDraft']];
      $form['result']['save_to_project'] = ['#type' => 'select', '#title' => $this->t('Save script to project'), '#options' => $this->getProjectOptions(), '#empty_option' => $this->t('- Select project -')];
      $form['result']['actions']['save'] = ['#type' => 'submit', '#value' => $this->t('Save to project'), '#submit' => ['::saveToProject']];
    }
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $text = $this->writer->write((string) $form_state->getValue('model'), (string) $form_state->getValue('mode'), (string) $form_state->getValue('brief'), (string) $form_state->getValue('source'), (string) $form_state->getValue('instruction'));
      if ($text === '') {
        throw new \UnexpectedValueException('The provider returned empty writing.');
      }
      $form_state->set('result', $text);
      $form_state->setRebuild();
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not generate writing: @message', ['@message' => $e->getMessage()]));
      $form_state->setRebuild();
    }
  }

  /**
   *
   */
  public function useAsOutline(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue('source', $form_state->getValue(['result', 'text']));
    $form_state->setValue('mode', 'outline');
    $form_state->set('result', NULL);
    $form_state->setRebuild();
  }

  /**
   *
   */
  public function useAsDraft(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue('source', $form_state->getValue(['result', 'text']));
    $form_state->setValue('mode', 'draft');
    $form_state->set('result', NULL);
    $form_state->setRebuild();
  }

  /**
   *
   */
  public function saveToProject(array &$form, FormStateInterface $form_state): void {
    $id = $form_state->getValue(['result', 'save_to_project']);
    $text = trim((string) $form_state->getValue(['result', 'text']));
    $board = $id ? $this->entityTypeManager->getStorage('ai_storyboard')->load($id) : NULL;
    if ($text === '' || !$board || !$board->access('update')) {
      $this->messenger()->addError($this->t('Select a project you can edit.'));
      $form_state->setRebuild();
      return;
    }
    $board->set('script', $text);
    $board->save();
    $this->messenger()->addStatus($this->t('Script saved to @project. Run “Rebuild shots from script” in that project when you are ready.', ['@project' => $board->label()]));
    $form_state->setRedirect('entity.ai_storyboard.canonical', ['ai_storyboard' => $board->id()]);
  }

  /**
   *
   */
  private function getProjectOptions(): array {
    $ids = $this->entityTypeManager->getStorage('ai_storyboard')->getQuery()->accessCheck(TRUE)->sort('changed', 'DESC')->execute();
    $options = [];
    foreach ($this->entityTypeManager->getStorage('ai_storyboard')->loadMultiple($ids) as $board) {
      if ($board->access('update')) {
        $options[$board->id()] = $board->label();
      }
    }
    return $options;
  }

}
