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
    return $form;
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
    }
    return $actions;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Narration project %title has been saved.', ['%title' => $this->entity->label()]));
    $form_state->setRedirect('entity.ai_narration_project.collection');
    return $result;
  }

}
