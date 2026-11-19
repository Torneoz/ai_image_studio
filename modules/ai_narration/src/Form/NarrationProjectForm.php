<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits shared narration-project defaults.
 */
final class NarrationProjectForm extends ContentEntityForm {

  protected NarrationGenerator $generator;

  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->generator = $container->get('ai_narration.generator');
    return $form;
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form['#attached']['library'][] = 'ai_narration/character_counter';
    $form['#attached']['library'][] = 'ai_narration/enhanced_player';
    $form['#attached']['drupalSettings']['aiNarration']['playerModeDefault'] = (string) (\Drupal::service('user.data')->get('ai_narration', $this->currentUser()->id(), 'player_mode') ?: \Drupal::config('ai_narration.settings')->get('player_mode') ?: 'native');
    $form['#attached']['drupalSettings']['aiNarration']['playerAccentColour'] = (string) (\Drupal::config('ai_narration.settings')->get('player_accent_colour') ?: '#24c7b9');
    $form['#attached']['drupalSettings']['aiNarration']['playerColourMode'] = (string) (\Drupal::config('ai_narration.settings')->get('player_colour_mode') ?: 'default');
    $form['#attached']['drupalSettings']['aiNarration']['playerWaveformColour'] = (string) (\Drupal::config('ai_narration.settings')->get('player_waveform_colour') ?: '#74fff0');
    $form['#attached']['drupalSettings']['aiNarration']['playerBackgroundColour'] = (string) (\Drupal::config('ai_narration.settings')->get('player_background_colour') ?: '#102b2d');
    $form['#attached']['drupalSettings']['aiNarration']['playerTextColour'] = (string) (\Drupal::config('ai_narration.settings')->get('player_text_colour') ?: '#e9fffc');
    $form['#attached']['drupalSettings']['aiNarration']['playerHeight'] = (string) (\Drupal::config('ai_narration.settings')->get('player_height') ?: '4rem');
    $form['#attached']['drupalSettings']['aiNarration']['playerDisplayStyle'] = (string) (\Drupal::config('ai_narration.settings')->get('player_display_style') ?: 'waveform');
    $models = $this->generator->getModelOptions();
    $model = (string) ($form_state->getValue(['model', 0, 'value']) ?? $this->entity->get('model')->value);
    $model = isset($models[$model]) ? $model : '';

    $form['project_settings'] = ['#type' => 'container', '#attributes' => ['id' => 'ai-narration-project-settings'], '#weight' => 0];
    foreach (['model', 'voice', 'format', 'speed', 'text_normalization', 'max_input_characters', 'language', 'voice_instructions'] as $field) {
      if (isset($form[$field])) {
        $form['project_settings'][$field] = $form[$field];
        unset($form[$field]);
      }
    }

    $model_element =& $form['project_settings']['model']['widget'][0]['value'];
    $model_element['#type'] = 'select';
    $model_element['#options'] = $models;
    $model_element['#empty_option'] = $this->t('- Select a model -');
    $model_element['#multiple'] = FALSE;
    $model_element['#size'] = 1;
    $model_element['#description'] = $this->t('Choose the provider and model used by this project’s chapters.');
    $model_element['#ajax'] = ['callback' => '::refreshProjectSettings', 'wrapper' => 'ai-narration-project-settings'];

    $voices = $model === '' ? [] : $this->generator->getVoiceOptions($model);
    $voice_element =& $form['project_settings']['voice']['widget'][0]['value'];
    $voice_element['#type'] = 'select';
    $voice_element['#options'] = $voices;
    $voice_element['#empty_option'] = $this->t('- Provider default -');
    $voice_element['#multiple'] = FALSE;
    $voice_element['#size'] = 1;
    $voice_element['#default_value'] = isset($voices[$voice_element['#default_value'] ?? '']) ? $voice_element['#default_value'] : '';
    $voice_element['#description'] = $this->t('Available voices update when the model changes.');

    $formats = $model === '' ? [] : $this->generator->getFormatOptions($model);
    $format_element =& $form['project_settings']['format']['widget'][0]['value'];
    $format_element['#type'] = 'select';
    $format_element['#options'] = $formats;
    $format_element['#empty_option'] = $this->t('- Provider default -');
    $format_element['#multiple'] = FALSE;
    $format_element['#size'] = 1;
    $format_element['#default_value'] = isset($formats[$format_element['#default_value'] ?? '']) ? $format_element['#default_value'] : '';
    $format_element['#description'] = $this->t('Choose the default audio encoding when the provider supports it.');

    $provider = explode('__', $model, 2)[0] ?? '';
    $form['project_settings']['speed']['#access'] = in_array($provider, ['openai', 'grok', 'elevenlabs'], TRUE);
    $form['project_settings']['text_normalization']['#access'] = $provider === 'grok';
    $form['project_settings']['language']['#access'] = in_array($provider, ['gemini', 'grok', 'elevenlabs'], TRUE);
    if (!$this->entity->isNew() && ($file = $this->entity->get('joined_audio')->entity)) {
      $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      $markers = [];
      $chapter_storage = $this->entityTypeManager->getStorage('ai_narration_chapter');
      $chapter_ids = $chapter_storage->getQuery()->accessCheck(FALSE)->condition('project_id', $this->entity->id())->sort('position')->execute();
      foreach ($chapter_storage->loadMultiple($chapter_ids) as $chapter) {
        $metadata = json_decode((string) $chapter->get('generation_metadata')->value, TRUE) ?: [];
        $audio = !empty($metadata['entire_file_id']) ? $this->entityTypeManager->getStorage('file')->load((int) $metadata['entire_file_id']) : $chapter->get('last_generated_audio')->entity;
        if ($audio) {
          $markers[] = ['duration' => \Drupal::service('ai_narration.duration_inspector')->getDuration($audio) ?? 0.0, 'label' => (string) $chapter->label()];
        }
      }
      $form['joined_result'] = ['#type' => 'details', '#title' => $this->t('Joined chapter audio'), '#open' => TRUE, '#weight' => 80, '#attributes' => ['class' => ['ai-narration-result']]];
      $form['joined_result']['audio'] = ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $url, 'style' => 'width: 100%', 'data-ai-narration-segment-markers' => json_encode($markers)]];
      $form['joined_result']['download'] = ['#type' => 'link', '#title' => $this->t('Download joined audio'), '#url' => Url::fromUri($url), '#attributes' => ['download' => TRUE, 'class' => ['button']]];
      $form['joined_result']['metadata'] = ['#theme' => 'item_list', '#title' => $this->t('Join details'), '#items' => [$this->t('File: @name', ['@name' => $file->getFilename()]), $this->t('Size: @size bytes', ['@size' => number_format($file->getSize())]), $this->t('Generated from all available Project chapters')]];
    }
    if (!$this->entity->isNew()) {
      $chapters = $this->entityTypeManager->getStorage('ai_narration_chapter')->loadMultiple($this->entityTypeManager->getStorage('ai_narration_chapter')->getQuery()->accessCheck(FALSE)->condition('project_id', $this->entity->id())->sort('position')->execute());
      $form['chapter_audio'] = ['#type' => 'details', '#title' => $this->t('Chapter audio'), '#open' => FALSE, '#weight' => 81, '#attributes' => ['class' => ['ai-narration-result']]];
      $form['chapter_audio']['table'] = ['#type' => 'table', '#header' => [$this->t('Chapter'), $this->t('Position'), $this->t('Audio')], '#empty' => $this->t('No generated chapter audio is available.'), '#tabledrag' => [['action' => 'order', 'relationship' => 'sibling', 'group' => 'ai-narration-chapter-weight']]];
      foreach ($chapters as $chapter) {
        $metadata = json_decode((string) $chapter->get('generation_metadata')->value, TRUE) ?: [];
        $audio = !empty($metadata['entire_file_id']) ? $this->entityTypeManager->getStorage('file')->load((int) $metadata['entire_file_id']) : $chapter->get('last_generated_audio')->entity;
        if (!$audio) continue;
        $url = \Drupal::service('file_url_generator')->generateAbsoluteString($audio->getFileUri());
        $key = 'chapter_' . $chapter->id();
        $title = mb_strimwidth((string) $chapter->label(), 0, 80, '…');
        $form['chapter_audio']['table'][$key] = ['#attributes' => ['class' => ['draggable']], 'title' => ['data' => ['#type' => 'link', '#title' => $title, '#url' => $chapter->toUrl('edit-form')]], 'position' => ['data' => ['#type' => 'number', '#default_value' => $chapter->get('position')->value, '#attributes' => ['class' => ['ai-narration-chapter-weight']], '#parents' => ['chapter_order', $chapter->id(), 'position']]], 'audio' => ['#attributes' => ['class' => ['ai-narration-project-audio-cell']], 'data' => ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $url]]]];
      }
      $form['chapter_audio']['save_order'] = ['#type' => 'submit', '#value' => $this->t('Save chapter order'), '#submit' => ['::saveChapterOrder'], '#limit_validation_errors' => [['chapter_order']]];
    }
    return $form;
  }

  /** Saves the positions set by the Project's draggable Chapter table. */
  public function saveChapterOrder(array &$form, FormStateInterface $form_state): void {
    foreach ($form_state->getValue('chapter_order') ?? [] as $id => $values) {
      if ($chapter = $this->entityTypeManager->getStorage('ai_narration_chapter')->load((int) $id)) {
        $chapter->set('position', (int) $values['position']);
        $chapter->save();
      }
    }
    $this->messenger()->addStatus($this->t('Chapter order saved.'));
    $form_state->setRebuild();
  }

  public function refreshProjectSettings(array &$form, FormStateInterface $form_state): array {
    return $form['project_settings'];
  }

  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    if (!$this->entity->isNew()) {
      $actions['create_chapter'] = [
        '#type' => 'link',
        '#title' => $this->t('Create chapter'),
        '#url' => Url::fromRoute('entity.ai_narration_chapter.add_form', [], ['query' => ['project' => $this->entity->id()]]),
        '#attributes' => ['class' => ['button']],
      ];
      $actions['create_edition'] = [
        '#type' => 'link',
        '#title' => $this->t('Create edition'),
        '#url' => Url::fromRoute('entity.ai_narration_edition.add_form', [], ['query' => ['project' => $this->entity->id()]]),
        '#attributes' => ['class' => ['button']],
      ];
      $actions['join_chapter_audio'] = ['#type' => 'submit', '#value' => $this->t('Join chapter audio'), '#submit' => ['::joinChapterAudio'], '#limit_validation_errors' => []];
    }
    foreach ($actions as &$action) {
      $action['#attributes']['class'][] = 'ai-narration-project-action';
    }
    unset($action);
    return $actions;
  }

  /** Joins the latest audio for all Project chapters in position order. */
  public function joinChapterAudio(array &$form, FormStateInterface $form_state): void {
    $storage = $this->entityTypeManager->getStorage('ai_narration_chapter');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('project_id', $this->entity->id())->sort('position')->execute();
    $files = [];
    foreach ($storage->loadMultiple($ids) as $chapter) {
      $metadata = json_decode((string) $chapter->get('generation_metadata')->value, TRUE) ?: [];
      $file = !empty($metadata['entire_file_id']) ? $this->entityTypeManager->getStorage('file')->load((int) $metadata['entire_file_id']) : $chapter->get('last_generated_audio')->entity;
      if ($file) $files[] = $file;
    }
    $joined = \Drupal::service('ai_narration.audio_merger')->merge($files, (string) $this->entity->label());
    if (!$joined) { $this->messenger()->addError($this->t('At least two generated chapter files are needed to join audio.')); return; }
    $this->entity->set('joined_audio', ['target_id' => $joined->id()]);
    $this->entity->save();
    $this->messenger()->addStatus($this->t('Joined @count chapter audio files.', ['@count' => count($files)]));
    $form_state->setRebuild();
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Narration project %title has been saved.', ['%title' => $this->entity->label()]));
    $form_state->setRedirect('entity.ai_narration_project.collection');
    return $result;
  }

}
