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

  public function __construct(
    private readonly ScriptWriter $scriptWriter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ScriptBreakdown $breakdown,
    private readonly ImageGenerator $imageGenerator,
  ) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_storyboard.script_writer'),
      $container->get('entity_type.manager'),
      $container->get('ai_storyboard.breakdown'),
      $container->get('ai_image_studio.generator'),
    );
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
    $options = $this->scriptWriter->getModelOptions();
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
      $form['result']['create_project'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Create a new storyboard project'),
        '#description' => $this->t('Create a project with this script and continue to its storyboard workspace.'),
      ];
      $form['result']['new_project_title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('New project title'),
        '#default_value' => $this->suggestTitle((string) $form_state->getValue('brief', '')),
        '#maxlength' => 255,
        '#states' => ['visible' => [':input[name="result[create_project]"]' => ['checked' => TRUE]], 'required' => [':input[name="result[create_project]"]' => ['checked' => TRUE]]],
      ];
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
      $text = $this->scriptWriter->write((string) $form_state->getValue('model'), (string) $form_state->getValue('mode'), (string) $form_state->getValue('brief'), (string) $form_state->getValue('source'), (string) $form_state->getValue('instruction'));
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
    if ($form_state->getValue(['result', 'create_project'])) {
      $title = trim((string) $form_state->getValue(['result', 'new_project_title']));
      if ($title === '' || $text === '') {
        $this->messenger()->addError($this->t('Enter a title and make sure the script is not empty.'));
        $form_state->setRebuild();
        return;
      }
      $chat_options = $this->breakdown->getModelOptions();
      $image_options = $this->imageGenerator->getModelOptions('text_to_image');
      if (!$chat_options || !$image_options) {
        $this->messenger()->addError($this->t('Configure an available chat model and image generation model before creating a storyboard project.'));
        $form_state->setRebuild();
        return;
      }
      $settings = \Drupal::config('ai_image_studio.settings');
      $board = $this->entityTypeManager->getStorage('ai_storyboard')->create([
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

  /** Suggests a concise project title from the creative brief. */
  private function suggestTitle(string $brief): string {
    $line = preg_split('/[\r\n.!?]+/', trim($brief))[0] ?? '';
    $line = trim((string) preg_replace('/\s+/', ' ', $line));
    return mb_substr($line ?: 'New screenplay', 0, 80);
  }

}
