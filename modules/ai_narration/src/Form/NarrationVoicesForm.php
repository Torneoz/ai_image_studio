<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class NarrationVoicesForm extends FormBase {
  public function __construct(private readonly NarrationGenerator $generator, private readonly FileUrlGeneratorInterface $urls) {}
  public static function create(ContainerInterface $c): static { return new static($c->get('ai_narration.generator'), $c->get('file_url_generator')); }
  public function getFormId(): string { return 'ai_narration_voices'; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['text'] = ['#type' => 'textarea', '#title' => $this->t('Example text'), '#default_value' => $form_state->getValue('text') ?: 'This is a narration voice example.', '#required' => TRUE, '#rows' => 3];
    $form['actions']['generate'] = ['#type' => 'submit', '#value' => $this->t('Generate examples'), '#button_type' => 'primary'];
    $form['voices'] = ['#type' => 'table', '#attributes' => ['id' => 'ai-narration-voices-table'], '#header' => [$this->t('Provider / model'), $this->t('Voice'), $this->t('Estimated cost'), $this->t('Example'), $this->t('Action')], '#empty' => $this->t('No authenticated provider voices are available.')];
    $characters = mb_strlen((string) ($form_state->getValue('text') ?: 'This is a narration voice example.'));
    foreach ($this->voiceModels() as $model => $label) foreach ($this->generator()->getVoiceOptions($model) as $voice => $voice_label) {
      $url = ($form_state->get('examples') ?? [])[$model . '::' . $voice] ?? NULL;
      $key = md5($model . '::' . $voice);
      $form['voices'][$key] = ['provider' => ['#plain_text' => $label], 'voice' => ['#plain_text' => $voice_label], 'cost' => ['#plain_text' => $this->estimateCost($model, $characters)], 'example' => $url ? ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $url]] : ['#markup' => $this->t('Not generated')], 'action' => ['#type' => 'submit', '#value' => $this->t('Generate'), '#submit' => ['::generateExample'], '#limit_validation_errors' => [['text']], '#model_option' => $model, '#voice_option' => $voice, '#ajax' => ['callback' => '::ajaxGenerateExample', 'wrapper' => 'ai-narration-voices-table', 'progress' => ['type' => 'throbber', 'message' => $this->t('Generating example…')]]]];
    }
    return $form;
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $examples = [];
    foreach ($this->voiceModels() as $model => $label) foreach ($this->generator->getVoiceOptions($model) as $voice => $voice_label) {
      try { $file = $this->generator()->generate((string) $form_state->getValue('text'), $model, $voice); $examples[$model . '::' . $voice] = $this->urlGenerator()->generateAbsoluteString($file->getFileUri()); } catch (\Throwable $e) { $this->messenger()->addWarning($this->t('Could not generate @voice.', ['@voice' => $voice_label])); }
    }
    $form_state->set('examples', $examples)->setRebuild();
  }
  public function generateExample(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    try {
      $file = $this->generator()->generate((string) $form_state->getValue('text'), $trigger['#model_option'], $trigger['#voice_option']);
      $examples = $form_state->get('examples') ?? [];
      $examples[$trigger['#model_option'] . '::' . $trigger['#voice_option']] = $this->urlGenerator()->generateAbsoluteString($file->getFileUri());
      $form_state->set('examples', $examples)->setRebuild();
    }
    catch (\Throwable $e) { $this->messenger()->addError($e->getMessage()); }
  }
  public function ajaxGenerateExample(array &$form, FormStateInterface $form_state): array { return $form['voices']; }
  private function estimateCost(string $model, int $characters): string {
    if (!str_starts_with($model, 'openai__')) return (string) $this->t('Unavailable');
    $rate = str_contains($model, 'tts-1-hd') ? 0.00003 : 0.000015;
    return '$' . number_format($characters * $rate, 4) . ' / ' . number_format($characters) . ' chars';
  }
  /** Returns the preferred current model for each provider; Grok models are voices. */
  private function voiceModels(): array {
    $models = $this->generator()->getModelOptions();
    $preferred = [];
    foreach ($models as $id => $label) {
      $provider = explode('__', $id, 2)[0] ?? '';
      if ($provider === 'grok') { $preferred[$id] = $id; continue; }
      if (!isset($preferred[$provider])) $preferred[$provider] = $id;
      if (($provider === 'openai' && str_contains($id, 'gpt-4o-mini-tts')) || ($provider === 'gemini' && str_contains($id, '2.5'))) $preferred[$provider] = $id;
    }
    $ids = array_values($preferred);
    return array_intersect_key($models, array_flip($ids));
  }
  private function generator(): NarrationGenerator { return \Drupal::service('ai_narration.generator'); }
  private function urlGenerator(): FileUrlGeneratorInterface { return \Drupal::service('file_url_generator'); }
}
