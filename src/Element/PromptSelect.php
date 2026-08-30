<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Element;

use Drupal\ai\Element\AiPrompt;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
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
    foreach ($element['table']['#options'] ?? [] as $id => $row) {
      $options[$id] = (string) ($row['prompt_label'] ?? $id);
      $prompt_markup = preg_replace(
        '/<br\s*\/?>/i',
        "\n",
        (string) ($row['prompt'] ?? ''),
      );
      $prompts[$id] = trim(Html::decodeEntities(strip_tags($prompt_markup ?? '')));
    }
    natcasesort($options);

    $element['table']['#type'] = 'select';
    $element['table']['#weight'] = -2;
    $element['table']['#options'] = $options;
    $element['table']['#empty_option'] = t('- None -');
    $element['table']['#attributes']['class'][] = 'ai-image-studio-prompt-select';
    $element['table']['#ajax'] = [
      'callback' => [static::class, 'promptCallbackRefreshSelection'],
      'wrapper' => 'js-add-prompt-wrapper-for-' . $element['#id'],
      'parents' => $element['#parents'],
      'array_parents' => $element['#array_parents'] ?? $element['#parents'],
    ];
    unset(
      $element['table']['#header'],
      $element['table']['#multiple'],
      $element['table']['#empty'],
    );

    $selected = (string) ($element['table']['#default_value'] ?? '');
    if ($selected !== '' && isset($prompts[$selected])) {
      $element['selected_prompt'] = [
        '#type' => 'container',
        '#weight' => -1,
        '#attributes' => [
          'class' => ['ai-image-studio-prompt-preview'],
          'aria-live' => 'polite',
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'strong',
          '#value' => t('Selected prompt'),
          '#attributes' => ['class' => ['ai-image-studio-prompt-preview__label']],
        ],
        'text' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-image-studio-prompt-preview__text']],
          'content' => [
            '#plain_text' => $prompts[$selected],
          ],
        ],
        'edit' => [
          '#type' => 'link',
          '#title' => t('Edit selected prompt'),
          '#url' => Url::fromRoute('entity.ai_prompt.edit_form', [
            'ai_prompt' => $selected,
          ], [
            'query' => ['destination' => \Drupal::request()->getRequestUri()],
          ]),
          '#attributes' => [
            'class' => ['button', 'button--small'],
            'target' => '_blank',
          ],
        ],
      ];
    }

    $element['#attached']['library'][] = 'ai_image_studio/prompt_select';
    return $element;
  }

  /**
   * Refreshes the prompt selector after its value changes.
   */
  public static function promptCallbackRefreshSelection(
    array &$form,
    FormStateInterface $form_state,
  ): array {
    $ajax = $form_state->getTriggeringElement()['#ajax'];
    return (array) NestedArray::getValue(
      $form,
      $ajax['array_parents'] ?? $ajax['parents'],
    );
  }

}
