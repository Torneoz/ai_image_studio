<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Form;

use Drupal\ai_narration\Service\NarrationGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\user\UserDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets authorized users generate and download narrated audio.
 */
final class NarrationGenerateForm extends FormBase {

  public function __construct(
    private readonly NarrationGenerator $generator,
    private readonly ConfigFactoryInterface $narrationConfigFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly UserDataInterface $userData,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_narration.generator'),
      $container->get('config.factory'),
      $container->get('file_url_generator'),
      $container->get('user.data'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'ai_narration_generate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = $this->generator->getModelOptions();
    $default = (string) $this->narrationConfigFactory->get('ai_narration.settings')->get('default_model');
    $savedText = (string) ($this->userData->get('ai_narration', $this->currentUser->id(), 'last_text') ?? '');
    $savedModel = (string) ($this->userData->get('ai_narration', $this->currentUser->id(), 'last_model') ?? '');
    $submittedModel = (string) ($form_state->getValue('model') ?? '');
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Narration text'),
      '#description' => $this->t('Use only text you are authorized to turn into speech. Your selected provider applies its own length and content limits.'),
      '#required' => TRUE,
      '#rows' => 10,
      '#default_value' => $form_state->getValue('text') ?? $savedText,
    ];
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Voice'),
      '#options' => $options,
      '#default_value' => isset($options[$submittedModel]) ? $submittedModel : (isset($options[$savedModel]) ? $savedModel : (isset($options[$default]) ? $default : '')),
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
      $text = (string) $form_state->getValue('text');
      $model = (string) $form_state->getValue('model');
      $this->userData->set('ai_narration', $this->currentUser->id(), 'last_text', $text);
      $this->userData->set('ai_narration', $this->currentUser->id(), 'last_model', $model);
      $file = $this->generator->generate($text, $model);
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
