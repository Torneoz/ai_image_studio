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
    $this->loadScriptFromQuery($form_state);
    $options = $this->scriptWriter()->getModelOptions();
    $form['#attached']['library'][] = 'ai_storyboard/workspace';
    $form['intro'] = ['#markup' => '<p>' . $this->t('Develop an idea into a story outline, draft it as a screenplay, then revise passages with focused notes. Save scripts as reusable, editable documents.') . '</p>'];
    $form['script_id'] = ['#type' => 'hidden', '#value' => $form_state->get('script_id')];
    $form['saved_scripts'] = ['#type' => 'details', '#title' => $this->t('Saved scripts'), '#open' => FALSE];
    $form['saved_scripts']['script'] = [
      '#type' => 'select',
      '#title' => $this->t('Open a saved script'),
      '#options' => $this->getSavedScriptOptions(),
      '#empty_option' => $this->t('- Select a script -'),
      '#default_value' => $form_state->get('script_id') ?: '',
    ];
    $form['saved_scripts']['load'] = ['#type' => 'submit', '#value' => $this->t('Open script'), '#submit' => ['::loadSavedScript']];
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
      $form['result']['title'] = ['#type' => 'textfield', '#title' => $this->t('Script title'), '#required' => TRUE, '#maxlength' => 255, '#default_value' => $form_state->getValue(['result', 'title']) ?: $this->suggestTitle((string) $form_state->getValue('brief', ''))];
      $form['result']['text'] = ['#type' => 'textarea', '#title' => $this->t('Edit the result'), '#rows' => 24, '#default_value' => $form_state->get('result'), '#description' => $this->t('Edit freely before saving or using this text as the next step.')];
      $form['result']['actions'] = ['#type' => 'actions'];
      $form['result']['actions']['outline'] = ['#type' => 'submit', '#value' => $this->t('Use as outline'), '#submit' => ['::useAsOutline']];
      $form['result']['actions']['draft'] = ['#type' => 'submit', '#value' => $this->t('Draft screenplay from this'), '#submit' => ['::useAsDraft']];
      $form['result']['actions']['save_script'] = ['#type' => 'submit', '#value' => $this->t($form_state->get('script_id') ? 'Save changes to script' : 'Save script'), '#button_type' => 'primary', '#submit' => ['::saveScript'], '#name' => 'save_script_draft'];
      $form['result']['save_to_project'] = [
        '#type' => 'select',
        '#title' => $this->t('Link script to storyboard project'),
        '#options' => $this->getProjectOptions(),
        '#empty_option' => $this->t('- Select project -'),
        '#default_value' => $form_state->getValue(['result', 'save_to_project']) ?: $this->getScriptProjectId((int) $form_state->get('script_id')),
        '#description' => $this->t('The saved Script becomes a child of this project and its text stays synchronized with the project script.'),
      ];
      $form['result']['new_project_title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Or create a new storyboard project'),
        '#default_value' => $this->suggestTitle((string) $form_state->getValue('brief', '')),
        '#maxlength' => 255,
        '#description' => $this->t('Leave the existing project selector blank and enter a title here to create a new project.'),
      ];
      $form['result']['actions']['save'] = [
        '#type' => 'submit',
        '#value' => $this->t('Link script to project'),
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
      $form_state->set('narration_result', NULL);
      $form_state->setRebuild();
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Could not generate writing: @message', ['@message' => $e->getMessage()]));
      $form_state->setRebuild();
    }
  }

  /** Opens an owned saved script in the writer workspace. */
  public function loadSavedScript(array &$form, FormStateInterface $form_state): void {
    $id = (int) ($form_state->getValue(['saved_scripts', 'script']) ?? 0);
    $script = $id ? $this->entityTypeManager()->getStorage('ai_storyboard_script')->load($id) : NULL;
    if (!$script || !$script->access('view')) {
      $this->messenger()->addError($this->t('Select a saved script you can access.'));
      $form_state->setRebuild();
      return;
    }
    $this->populateFromScript($script, $form_state);
    $this->messenger()->addStatus($this->t('Opened @title.', ['@title' => $script->label()]));
    $form_state->setRebuild();
  }

  /** Saves the editable writer workspace to its script entity. */
  public function saveScript(array &$form, FormStateInterface $form_state): void {
    $script_text = trim((string) ($form_state->getValue(['result', 'text']) ?? $form_state->get('result') ?? ''));
    $title = trim((string) $form_state->getValue(['result', 'title']));
    if ($script_text === '') {
      $this->messenger()->addError($this->t('Write or generate screenplay text before saving.'));
      $form_state->setRebuild();
      return;
    }
    if ($title === '') {
      $title = $this->suggestTitle((string) $form_state->getValue('brief', ''));
    }
    $storage = $this->entityTypeManager()->getStorage('ai_storyboard_script');
    $id = (int) ($form_state->getValue('script_id') ?: $form_state->get('script_id'));
    $script = $id ? $storage->load($id) : NULL;
    if ($script && !$script->access('update')) {
      $this->messenger()->addError($this->t('You cannot edit this saved script.'));
      $form_state->setRebuild();
      return;
    }
    $script ??= $storage->create(['uid' => $this->currentUser()->id()]);
    $script->set('title', $title);
    $script->set('brief', (string) $form_state->getValue('brief'));
    $script->set('writing_mode', (string) $form_state->getValue('mode'));
    $script->set('source_text', (string) $form_state->getValue('source'));
    $script->set('instruction', (string) $form_state->getValue('instruction'));
    $script->set('model', (string) $form_state->getValue('model'));
    $script->set('script_text', $script_text);
    $script->set('narration_text', (string) ($form_state->getValue(['result', 'narration', 'text']) ?? $form_state->get('narration_result') ?? ''));
    $script->set('narration_instruction', (string) $form_state->getValue(['result', 'narration_instruction']));
    $script->save();
    if ($project = $script->get('storyboard_id')->entity) {
      if ((int) $project->get('script_entity_id')->target_id === (int) $script->id()) {
        $project->set('script', $script_text);
        $project->set('creative_brief', (string) $script->get('brief')->value);
        $project->save();
      }
    }
    $form_state->set('script_id', $script->id());
    $form_state->setValue('script_id', $script->id());
    $form_state->set('result', $script_text);
    $form_state->set('narration_result', $script->get('narration_text')->value ?: NULL);
    $this->messenger()->addStatus($this->t('Saved @title.', ['@title' => $script->label()]));
    $form_state->setRebuild();
  }

  /** Loads a saved document when ScriptWriter is opened from its collection. */
  private function loadScriptFromQuery(FormStateInterface $form_state): void {
    if ($form_state->get('script_writer_query_checked')) {
      return;
    }
    $form_state->set('script_writer_query_checked', TRUE);
    $id = (int) \Drupal::request()->query->get('script', 0);
    $script = $id ? $this->entityTypeManager()->getStorage('ai_storyboard_script')->load($id) : NULL;
    if ($script && $script->access('view')) {
      $this->populateFromScript($script, $form_state);
    }
  }

  /** Populates writer form state from a saved Script entity. */
  private function populateFromScript(object $script, FormStateInterface $form_state): void {
    foreach (['brief', 'writing_mode', 'source_text', 'instruction', 'model'] as $field) {
      $key = $field === 'writing_mode' ? 'mode' : ($field === 'source_text' ? 'source' : $field);
      $form_state->setValue($key, (string) $script->get($field)->value);
    }
    $form_state->setValue('result', [
      'title' => $script->label(),
      'text' => (string) $script->get('script_text')->value,
      'narration' => ['text' => (string) $script->get('narration_text')->value],
      'narration_instruction' => (string) $script->get('narration_instruction')->value,
    ]);
    $form_state->set('result', (string) $script->get('script_text')->value);
    $form_state->set('narration_result', $script->get('narration_text')->value ?: NULL);
    $form_state->set('script_id', (int) $script->id());
    $form_state->setValue('script_id', (int) $script->id());
  }

  /** Returns accessible saved script labels for the workspace selector. */
  private function getSavedScriptOptions(): array {
    $storage = $this->entityTypeManager()->getStorage('ai_storyboard_script');
    $ids = $storage->getQuery()->accessCheck(TRUE)->sort('changed', 'DESC')->execute();
    $options = [];
    foreach ($storage->loadMultiple($ids) as $script) {
      if ($script->access('view')) {
        $options[$script->id()] = $script->label();
      }
    }
    return $options;
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
      $this->attachScriptToProject($board, $text, $form_state);
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
    $this->attachScriptToProject($board, $text, $form_state);
    $this->messenger()->addStatus($this->t('Linked the saved script to @project. Run “Rebuild shots from script” there when you are ready.', ['@project' => $board->label()]));
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

  /** Links the current document to a storyboard and marks it as the source. */
  private function attachScriptToProject(object $board, string $text, FormStateInterface $form_state): object {
    $storage = $this->entityTypeManager()->getStorage('ai_storyboard_script');
    $id = (int) ($form_state->getValue('script_id') ?: $form_state->get('script_id'));
    $script = $id ? $storage->load($id) : NULL;
    if (!$script || !$script->access('update') || ($script->get('storyboard_id')->target_id && (int) $script->get('storyboard_id')->target_id !== (int) $board->id())) {
      $script = $storage->create(['uid' => $board->getOwnerId()]);
      $script->set('brief', (string) $form_state->getValue('brief'));
      $script->set('writing_mode', (string) $form_state->getValue('mode'));
      $script->set('source_text', (string) $form_state->getValue('source'));
      $script->set('instruction', (string) $form_state->getValue('instruction'));
      $script->set('model', (string) $form_state->getValue('model'));
      $script->set('narration_text', (string) ($form_state->getValue(['result', 'narration', 'text']) ?? $form_state->get('narration_result') ?? ''));
      $script->set('narration_instruction', (string) $form_state->getValue(['result', 'narration_instruction']));
    }
    $script->set('title', trim((string) ($form_state->getValue(['result', 'title']) ?: $board->label())));
    $script->set('storyboard_id', $board->id());
    $script->setOwnerId((int) $board->getOwnerId());
    $script->set('script_text', $text);
    $script->save();
    $board->set('script', $text);
    $board->set('script_entity_id', $script->id());
    $board->save();
    $form_state->set('script_id', $script->id());
    $form_state->setValue('script_id', $script->id());
    return $script;
  }

  /** Returns the parent project ID for a saved script. */
  private function getScriptProjectId(int $script_id): string {
    $script = $script_id ? $this->entityTypeManager()->getStorage('ai_storyboard_script')->load($script_id) : NULL;
    return $script && $script->access('view') ? (string) ($script->get('storyboard_id')->target_id ?: '') : '';
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
