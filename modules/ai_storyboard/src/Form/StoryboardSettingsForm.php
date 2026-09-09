<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures Storyboard-specific request limits.
 */
final class StoryboardSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_storyboard_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_storyboard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['breakdown_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Script breakdown timeout (seconds)'),
      '#description' => $this->t('How long to wait for the AI response when creating or rebuilding shots. Applies only to Storyboard breakdowns using the standard Drupal AI HTTP client; other AI requests keep their existing timeout. Hosting or provider-specific limits may still be shorter. Failed requests are not automatically retried.'),
      '#default_value' => $this->config('ai_storyboard.settings')->get('breakdown_timeout') ?? 300,
      '#min' => 30,
      '#max' => 480,
      '#step' => 1,
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ai_storyboard.settings')
      ->set('breakdown_timeout', (int) $form_state->getValue('breakdown_timeout'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
