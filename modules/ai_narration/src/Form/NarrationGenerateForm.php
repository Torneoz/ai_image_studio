<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets authorized users generate and download narrated audio.
 */
final class NarrationGenerateForm extends FormBase {

  public function __construct(
    private readonly NarrationGenerator $generator,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_narration.generator'),
      $container->get('config.factory'),
      $container->get('file_url_generator'),
    );
  }

  public function getFormId(): string {
    return 'ai_narration_generate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = $this->generator->getModelOptions();
    $default = (string) $this->configFactory->get('ai_narration.settings')->get('default_model');
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Narration text'),
      '#description' => $this->t('Use only text you are authorized to turn into speech. Your selected provider applies its own length and content limits.'),
      '#required' => TRUE,
      '#rows' => 10,
    ];
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Voice'),
      '#options' => $options,
      '#default_value' => isset($options[$default]) ? $default : '',
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a voice -'),
      '#description' => $options === [] ? $this->t('No usable text-to-speech provider is configured.') : $this->t('Voices are supplied by the enabled Drupal AI providers.'),
    ];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Generate narration'), '#disabled' => $options === []];
    if ($audioUrl = $form_state->get('audio_url')) {
      $form['result'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-narration-result']],
      ];
      $form['result']['player'] = [
        '#type' => 'html_tag',
        '#tag' => 'audio',
        '#attributes' => ['controls' => TRUE, 'src' => $audioUrl],
      ];
      $form['result']['download'] = [
        '#type' => 'link',
        '#title' => $this->t('Download generated narration'),
        '#url' => Url::fromUri($audioUrl),
        '#attributes' => ['download' => TRUE],
      ];
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $file = $this->generator->generate((string) $form_state->getValue('text'), (string) $form_state->getValue('model'));
      $url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      $form_state->set('audio_url', $url);
      $form_state->setRebuild();
      $this->messenger()->addStatus($this->t('Narration generated. Use the player below to preview or download it.'));
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
