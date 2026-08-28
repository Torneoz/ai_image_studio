<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Displays a generated image as a linked preview.
 */
#[ViewsField('ai_image_studio_image_preview')]
final class ImagePreview extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): array|string {
    $turn = $values->_entity ?? NULL;
    if ($turn === NULL || $turn->get('image')->isEmpty()) {
      return '';
    }

    $file = $turn->get('image')->entity;
    if (!$file instanceof FileInterface) {
      return '';
    }

    return [
      '#type' => 'link',
      '#title' => [
        '#theme' => 'image',
        '#uri' => $file->getFileUri(),
        '#alt' => $file->getFilename(),
        '#attributes' => [
          'class' => ['ai-image-studio-views-preview__image'],
          'loading' => 'lazy',
        ],
      ],
      '#url' => Url::fromUserInput($file->createFileUrl()),
      '#attributes' => [
        'class' => ['ai-image-studio-views-preview__link'],
        'aria-label' => $this->t('Open generated image @name', [
          '@name' => $file->getFilename(),
        ]),
      ],
      '#attached' => [
        'library' => ['ai_image_studio/views_previews'],
      ],
    ];
  }

}
