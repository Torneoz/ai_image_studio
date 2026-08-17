<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Element;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\Component\Utility\Html;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\media_library\MediaLibraryUiBuilder;

/**
 * Provides a single-value Media Library form element for AI Image Studio.
 */
#[FormElement('ai_image_studio_media_library')]
final class MediaLibrary extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#input' => TRUE,
      '#tree' => TRUE,
      '#allowed_bundles' => [],
      '#process' => [
        [self::class, 'processAjaxForm'],
        [self::class, 'processElement'],
        [self::class, 'processGroup'],
      ],
      '#pre_render' => [[self::class, 'preRenderGroup']],
      '#element_validate' => [[self::class, 'validateElement']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state): mixed {
    if (is_array($input) && array_key_exists('selection_id', $input)) {
      return (string) $input['selection_id'];
    }
    if ($input === FALSE) {
      return (string) ($element['#default_value'] ?? '');
    }
    return '';
  }

  /**
   * Expands the control into a preview, modal button, and hidden value.
   */
  public static function processElement(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $bundles = array_values(array_filter((array) $element['#allowed_bundles']));
    if ($bundles === []) {
      throw new \InvalidArgumentException('The Media Library element requires an allowed media bundle.');
    }

    $selection_id = (int) (is_array($element['#value'])
      ? ($element['#value']['selection_id'] ?? 0)
      : $element['#value']);
    $media = $selection_id > 0
      ? \Drupal::entityTypeManager()->getStorage('media')->load($selection_id)
      : NULL;
    $widget_id = Html::getId(implode('-', $element['#parents']));
    $wrapper_id = $widget_id . '-media-library-wrapper';
    $state = MediaLibraryState::create(
      'media_library.opener.ai_image_studio_form',
      $bundles,
      reset($bundles),
      1,
      ['widget_id' => $widget_id],
    );

    $element['#attributes']['id'] = $wrapper_id;
    $element['#attributes']['class'][] = 'media-library-widget';
    $element['#attached']['library'][] = 'ai_image_studio/media_library_element';
    $element['selection'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['media-library-selection'],
        'data-ai-image-studio-media-selection' => $widget_id,
      ],
    ];
    if ($media instanceof MediaInterface) {
      $element['selection']['preview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['media-library-item', 'media-library-item--grid']],
        'media' => \Drupal::entityTypeManager()
          ->getViewBuilder('media')
          ->view($media, 'media_library'),
        'remove' => [
          '#type' => 'submit',
          '#value' => t('Remove'),
          '#name' => $widget_id . '-remove',
          '#attributes' => [
            'class' => ['media-library-item__remove'],
            'aria-label' => t('Remove @label', ['@label' => $media->label()]),
          ],
          '#studio_element_parents' => $element['#parents'],
          '#studio_array_parents' => $element['#array_parents'],
          '#submit' => [[self::class, 'removeSelection']],
          '#ajax' => [
            'callback' => [self::class, 'updateElement'],
            'wrapper' => $wrapper_id,
          ],
          '#limit_validation_errors' => [],
        ],
      ];
    }
    else {
      $element['selection']['empty'] = [
        '#markup' => '<p>' . t('No media item selected.') . '</p>',
      ];
    }

    $element['open'] = [
      '#type' => 'button',
      '#value' => $media instanceof MediaInterface ? t('Replace media') : t('Add media'),
      '#name' => $widget_id . '-open',
      '#attributes' => [
        'class' => ['media-library-open-button', 'js-media-library-open-button'],
        'data-disable-refocus' => 'true',
        'data-ai-image-studio-media-open' => $widget_id,
      ],
      '#media_library_state' => $state,
      '#ajax' => [
        'callback' => [self::class, 'openMediaLibrary'],
        'progress' => ['type' => 'throbber', 'message' => t('Opening media library.')],
      ],
      '#limit_validation_errors' => [],
    ];
    $element['selection_id'] = [
      '#type' => 'hidden',
      '#value' => $selection_id ?: '',
      '#attributes' => ['data-ai-image-studio-media-value' => $widget_id],
    ];
    $element['update'] = [
      '#type' => 'submit',
      '#value' => t('Update media selection'),
      '#name' => $widget_id . '-update',
      '#attributes' => [
        'class' => ['js-hide'],
        'data-ai-image-studio-media-update' => $widget_id,
      ],
      '#studio_array_parents' => $element['#array_parents'],
      '#submit' => [[self::class, 'rebuildForm']],
      '#ajax' => [
        'callback' => [self::class, 'updateElement'],
        'wrapper' => $wrapper_id,
      ],
      '#limit_validation_errors' => [],
    ];
    return $element;
  }

  /**
   * Opens the core Media Library modal.
   */
  public static function openMediaLibrary(array $form, FormStateInterface $form_state): AjaxResponse {
    $state = $form_state->getTriggeringElement()['#media_library_state'];
    $library_ui = \Drupal::service('media_library.ui_builder')->buildUi($state);
    $options = MediaLibraryUiBuilder::dialogOptions();
    return (new AjaxResponse())->addCommand(new OpenModalDialogCommand(
      $options['title'],
      $library_ui,
      $options,
      NULL,
      '#modal-media-library',
    ));
  }

  /**
   * Returns the rebuilt element after a selection change.
   */
  public static function updateElement(array $form, FormStateInterface $form_state): array {
    $parents = $form_state->getTriggeringElement()['#studio_array_parents'];
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Rebuilds the form with the newly selected media ID.
   */
  public static function rebuildForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * Removes the current selection and rebuilds the control.
   */
  public static function removeSelection(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $parents = $trigger['#studio_element_parents'];
    $form_state->setValue($parents, ['selection_id' => '']);
    $input = $form_state->getUserInput();
    NestedArray::setValue($input, $parents, ['selection_id' => '']);
    $form_state->setUserInput($input);
    $form_state->setRebuild();
  }

  /**
   * Validates and flattens the element value to a media entity ID.
   */
  public static function validateElement(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $value = $element['#value'];
    $selection_id = (int) (is_array($value) ? ($value['selection_id'] ?? 0) : $value);
    if ($selection_id > 0) {
      $media = \Drupal::entityTypeManager()->getStorage('media')->load($selection_id);
      if (!$media instanceof MediaInterface
        || !in_array($media->bundle(), $element['#allowed_bundles'], TRUE)
        || !$media->access('view')) {
        $form_state->setError($element, t('The selected media item is not available.'));
        return;
      }
    }
    $form_state->setValueForElement($element, $selection_id > 0 ? (string) $selection_id : '');
  }

}
