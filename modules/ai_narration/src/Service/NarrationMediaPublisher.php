<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Publishes generated narration files into the configured Audio library.
 */
final class NarrationMediaPublisher {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Creates an Audio media item from a permanent generated narration file.
   */
  public function publish(FileInterface $file, string $title): MediaInterface {
    $type = $this->entityTypeManager->getStorage('media_type')->load('audio');
    if ($type === NULL || $type->getSource()->getPluginId() !== 'audio_file') {
      throw new \RuntimeException('An Audio media type is required to save narration to the Media Library.');
    }
    $sourceField = $type->getSource()->getConfiguration()['source_field'] ?? '';
    if ($sourceField === '') {
      throw new \RuntimeException('The Audio media type has no configured source field.');
    }
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => 'audio',
      'name' => $title,
      $sourceField => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

}
