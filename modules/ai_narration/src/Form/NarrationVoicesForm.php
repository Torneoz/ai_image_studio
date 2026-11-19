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
    $rows = [];
    foreach ($this->generator->getModelOptions() as $model => $label) foreach ($this->generator->getVoiceOptions($model) as $voice => $voice_label) {
      $url = ($form_state->get('examples') ?? [])[$model . '::' . $voice] ?? NULL;
      $rows[] = [(string) $label, (string) $voice_label, $url ? ['data' => ['#type' => 'html_tag', '#tag' => 'audio', '#attributes' => ['controls' => TRUE, 'src' => $url]]] : $this->t('Not generated')];
    }
    $form['voices'] = ['#type' => 'table', '#header' => [$this->t('Provider / model'), $this->t('Voice'), $this->t('Example')], '#rows' => $rows, '#empty' => $this->t('No authenticated provider voices are available.')];
    return $form;
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $examples = [];
    foreach ($this->generator->getModelOptions() as $model => $label) foreach ($this->generator->getVoiceOptions($model) as $voice => $voice_label) {
      try { $file = $this->generator->generate((string) $form_state->getValue('text'), $model, $voice); $examples[$model . '::' . $voice] = $this->urls->generateAbsoluteString($file->getFileUri()); } catch (\Throwable $e) { $this->messenger()->addWarning($this->t('Could not generate @voice.', ['@voice' => $voice_label])); }
    }
    $form_state->set('examples', $examples)->setRebuild();
  }
}
