<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Element;

use Drupal\ai\Element\AiPrompt;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Url;

/**
 * Provides the AI prompt manager with a compact selection control.
 */
#[FormElement('ai_image_studio_prompt')]
final class PromptSelect extends AiPrompt {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    $info = parent::getInfo();
    array_splice($info['#process'], 1, 0, [
      [static::class, 'processSelect'],
    ]);
    return $info;
  }

  /**
   * Converts only the generated prompt table into a select and preview.
   */
  public static function processSelect(
    array &$element,
    FormStateInterface $form_state,
    array &$complete_form,
  ): array {
    $options = [];
    $prompts = [];
    $edit_urls = [];
    $current_url = \Drupal::request()->getRequestUri();
    foreach ($element['table']['#options'] ?? [] as $id => $row) {
      $options[$id] = (string) ($row['prompt_label'] ?? $id);
      $prompt_markup = preg_replace(
        '/<br\s*\/?>/i',
        "\n",
        (string) ($row['prompt'] ?? ''),
      );
      $prompts[$id] = trim(Html::decodeEntities(strip_tags($prompt_markup ?? '')));
      $edit_urls[$id] = Url::fromRoute('entity.ai_prompt.edit_form', [
        'ai_prompt' => $id,
      ], [
        'query' => ['destination' => $current_url],
      ])->toString();
    }
    natcasesort($options);

    $element['table']['#type'] = 'select';
    $element['table']['#options'] = $options;
    $element['table']['#empty_option'] = t('- None -');
    $element['table']['#attributes']['class'][] = 'ai-image-studio-prompt-select';
    $element['table']['#attributes']['data-prompt-texts'] = json_encode($prompts);
    $element['table']['#attributes']['data-prompt-edit-urls'] = json_encode($edit_urls);
    $element['table']['#suffix'] = '<div class="ai-image-studio-prompt-preview" aria-live="polite"></div><a class="ai-image-studio-prompt-edit button button--small" target="_blank" hidden>' . t('Edit selected prompt') . '</a>';
    unset(
      $element['table']['#header'],
      $element['table']['#multiple'],
      $element['table']['#empty'],
    );

    $element['#attached']['library'][] = 'ai_image_studio/prompt_select';
    return $element;
  }

}
