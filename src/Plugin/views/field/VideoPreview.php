<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Plugin\views\field;

use Drupal\file\FileInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Displays a generated video with playback controls.
 */
#[ViewsField('ai_image_studio_video_preview')]
final class VideoPreview extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): array|string {
    $turn = $values->_entity ?? NULL;
    if ($turn === NULL || $turn->get('video')->isEmpty()) {
      return '';
    }

    $file = $turn->get('video')->entity;
    if (!$file instanceof FileInterface) {
      return '';
    }

    $attributes = [
      'class' => ['ai-image-studio-views-preview__video'],
      'controls' => 'controls',
      'preload' => 'auto',
      'src' => $file->createFileUrl(),
    ];
    $poster = $turn->get('source_file_id')->entity;
    if ($poster instanceof FileInterface) {
      $attributes['poster'] = $poster->createFileUrl();
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'video',
      '#attributes' => $attributes,
      '#attached' => [
        'library' => ['ai_image_studio/views_previews'],
      ],
    ];
  }

}
