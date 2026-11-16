<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Symfony\Component\Process\Process;

/**
 * Reads exact audio duration from a generated file using ffprobe.
 */
final class NarrationDurationInspector {

  public function __construct(private readonly FileSystemInterface $fileSystem) {}

  /**
   * Returns duration in seconds, or NULL when the codec cannot be inspected.
   */
  public function getDuration(FileInterface $file): ?float {
    $path = $this->fileSystem->realpath($file->getFileUri());
    if (!$path || !is_file($path)) {
      return NULL;
    }
    try {
      $process = new Process([
        'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1', $path,
      ]);
      $process->setTimeout(10);
      $process->run();
      $duration = trim($process->getOutput());
      return $process->isSuccessful() && is_numeric($duration) && (float) $duration >= 0 ? (float) $duration : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
