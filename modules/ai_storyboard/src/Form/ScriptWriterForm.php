<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_storyboard\Service\ScriptBreakdown;
use Drupal\ai_storyboard\Service\ScriptWriter;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an AI assisted screenplay writing workspace. */
final class ScriptWriterForm extends FormBase {

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
    $options = $this->scriptWriter()->getModelOptions();
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
      $form['result']['new_project_title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Or create a new storyboard project'),
        '#default_value' => $this->suggestTitle((string) $form_state->getValue('brief', '')),
        '#maxlength' => 255,
        '#description' => $this->t('Leave the existing project selector blank and enter a title here to create a new project.'),
      ];
      $form['result']['actions']['save'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save to project'),
        '#submit' => ['::saveToProject'],
        '#name' => 'save_script_to_project',
      ];
      if ($form_state->get('narration_result')) {
        $form['result']['narration'] = ['#type' => 'details', '#title' => $this->t('Narration text'), '#open' => TRUE];
        $form['result']['narration']['text'] = ['#type' => 'textarea', '#title' => $this->t('Review and edit narration text'), '#rows' => 14, '#default_value' => $form_state->get('narration_result'), '#description' => $this->t('This version is adapted for spoken narration. Review it before opening AI Narration.')];
        $form['result']['narration']['actions']['send'] = ['#type' => 'submit', '#value' => $this->t('Open in AI Narration'), '#submit' => ['::sendToNarration'], '#name' => 'send_to_ai_narration'];
      }
      $form['result']['narration_instruction'] = ['#type' => 'textarea', '#title' => $this->t('Narration adaptation direction'), '#rows' => 2, '#default_value' => $form_state->getValue(['result', 'narration_instruction'], ''), '#description' => $this->t('Optional tone or style, such as warm documentary or suspenseful third-person narration.')];
      $form['result']['actions']['prepare_narration'] = ['#type' => 'submit', '#value' => $this->t('Prepare narration text'), '#submit' => ['::prepareNarration'], '#name' => 'prepare_narration_text'];
    }
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $text = $this->scriptWriter()->write((string) $form_state->getValue('model'), (string) $form_state->getValue('mode'), (string) $form_state->getValue('brief'), (string) $form_state->getValue('source'), (string) $form_state->getValue('instruction'));
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

  /** Adapts the current editable screenplay into narration text. */
  public function prepareNarration(array &$form, FormStateInterface $form_state): void {
    $script = trim((string) ($form_state->getValue(['result', 'text']) ?? $form_state->get('result') ?? ''));
    if ($script === '') {
      $this->messenger()->addError($this->t('Generate or enter screenplay text before preparing narration.'));
      $form_state->setRebuild();
      return;
    }
    try {
      $text = $this->scriptWriter()->createNarrationText((string) $form_state->getValue('model'), $script, trim((string) $form_state->getValue(['result', 'narration_instruction'])));
      if ($text === '') {
        throw new \UnexpectedValueException('The provider returned empty narration text.');
      }
      $form_state->set('result', $script);
      $form_state->set('narration_result', $text);
    }
    catch (\Throwable $e) {
      $form_state->set('result', $script);
      $this->messenger()->addError($this->t('Could not prepare narration text: @message', ['@message' => $e->getMessage()]));
    }
    $form_state->setRebuild();
  }

  /** Transfers reviewed narration text to the AI Narration form. */
  public function sendToNarration(array &$form, FormStateInterface $form_state): void {
    if (!\Drupal::moduleHandler()->moduleExists('ai_narration') || !\Drupal::currentUser()->hasPermission('generate ai narration')) {
      $this->messenger()->addError($this->t('AI Narration is not available for your account.'));
      $form_state->setRebuild();
      return;
    }
    $text = trim((string) ($form_state->getValue(['result', 'narration', 'text']) ?? $form_state->get('narration_result') ?? ''));
    if ($text === '') {
      $this->messenger()->addError($this->t('Prepare narration text before opening AI Narration.'));
      $form_state->setRebuild();
      return;
    }
    \Drupal::service('tempstore.private')->get('ai_storyboard')->set('narration_text', $text);
    $form_state->setRedirect('ai_narration.generate', [], ['query' => ['storyboard_narration' => 1]]);
  }

  /**
   *
   */
  public function saveToProject(array &$form, FormStateInterface $form_state): void {
    $user_input = $form_state->getUserInput();
    $result_input = is_array($user_input['result'] ?? NULL) ? $user_input['result'] : [];
    $id = $form_state->getValue(['result', 'save_to_project'])
      ?? $result_input['save_to_project']
      ?? NULL;
    $text = trim((string) (
      $form_state->getValue(['result', 'text'])
      ?? $result_input['text']
      ?? $form_state->get('result')
      ?? ''
    ));
    $title = trim((string) ($form_state->getValue(['result', 'new_project_title'])
      ?? $form_state->getValue('new_project_title')
      ?? $result_input['new_project_title']
      ?? ''));
    if (!$id && $title !== '') {
      if ($title === '' || $text === '') {
        $this->messenger()->addError($this->t('Enter a title and make sure the script is not empty.'));
        $form_state->setRebuild();
        return;
      }
      $chat_options = $this->breakdown()->getModelOptions();
      $image_options = $this->imageGenerator()->getModelOptions('text_to_image');
      if (!$chat_options || !$image_options) {
        $this->messenger()->addError($this->t('Configure an available chat model and image generation model before creating a storyboard project.'));
        $form_state->setRebuild();
        return;
      }
      $settings = \Drupal::config('ai_image_studio.settings');
      $board = $this->entityTypeManager()->getStorage('ai_storyboard')->create([
        'uid' => $this->currentUser()->id(),
        'title' => $title,
        'script' => $text,
        'creative_brief' => (string) $form_state->getValue('brief'),
        'visual_style' => 'cinematic storyboard sketch',
        'aspect_ratio' => '16:9',
        'chat_model' => (string) array_key_first($chat_options),
        'image_model' => (string) array_key_first($image_options),
        'max_prompt_length' => (int) ($settings->get('max_prompt_length') ?: 4000),
        'status' => 'draft',
      ]);
      $board->save();
      $this->messenger()->addStatus($this->t('New storyboard project created with the script. Use “Rebuild shots from script” when you are ready to create scenes and shots.'));
      $form_state->setRedirect('entity.ai_storyboard.canonical', ['ai_storyboard' => $board->id()]);
      return;
    }
    $board = $id ? $this->entityTypeManager()->getStorage('ai_storyboard')->load($id) : NULL;
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
    $ids = $this->entityTypeManager()->getStorage('ai_storyboard')->getQuery()->accessCheck(TRUE)->sort('changed', 'DESC')->execute();
    $options = [];
    foreach ($this->entityTypeManager()->getStorage('ai_storyboard')->loadMultiple($ids) as $board) {
      if ($board->access('update')) {
        $options[$board->id()] = $board->label();
      }
    }
    return $options;
  }

  /** Suggests a concise project title from the creative brief. */
  private function suggestTitle(string $brief): string {
    $line = preg_split('/[\r\n.!?]+/', trim($brief))[0] ?? '';
    $line = trim((string) preg_replace('/\s+/', ' ', $line));
    return mb_substr($line ?: 'New screenplay', 0, 80);
  }

  /** Returns the script writer service without retaining it on form state. */
  private function scriptWriter(): ScriptWriter {
    return \Drupal::service('ai_storyboard.script_writer');
  }

  /** Returns the entity type manager without retaining it on form state. */
  private function entityTypeManager(): EntityTypeManagerInterface {
    return \Drupal::entityTypeManager();
  }

  /** Returns the script breakdown service. */
  private function breakdown(): ScriptBreakdown {
    return \Drupal::service('ai_storyboard.breakdown');
  }

  /** Returns the image generator service. */
  private function imageGenerator(): ImageGenerator {
    return \Drupal::service('ai_image_studio.generator');
  }

}
