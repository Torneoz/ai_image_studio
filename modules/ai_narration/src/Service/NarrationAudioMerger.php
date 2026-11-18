<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Symfony\Component\Process\Process;

final class NarrationAudioMerger {
  public function __construct(private readonly FileSystemInterface $fileSystem) {}

  /** Joins compatible narration files without re-encoding them. */
  public function merge(array $files, string $name): ?FileInterface {
    if (count($files) < 2) return NULL;
    $directory = 'temporary://ai-narration-merge';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $output = $this->fileSystem->realpath($directory . '/entire-' . uniqid() . '.mp3');
    $command = ['ffmpeg', '-y'];
    foreach ($files as $file) {
      $command = [...$command, '-i', $this->fileSystem->realpath($file->getFileUri())];
    }
    $inputs = implode('', array_map(static fn (int $index) => '[' . $index . ':a]', array_keys($files)));
    // Decode every source first, allowing MP3 and WAV Chapters in one join.
    $command = [...$command, '-filter_complex', $inputs . 'concat=n=' . count($files) . ':v=0:a=1[out]', '-map', '[out]', '-c:a', 'libmp3lame', '-q:a', '2', $output];
    $process = new Process($command);
    $process->run();
    if (!$process->isSuccessful() || !is_file($output)) return NULL;
    $public_directory = 'public://ai-narration';
    $uri = $public_directory . '/' . $this->fileSystem->basename($output);
    $this->fileSystem->prepareDirectory($public_directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->fileSystem->copy($output, $uri, FileSystemInterface::EXISTS_RENAME);
    @unlink($output);
    $file = File::create(['uri' => $uri, 'status' => TRUE]);
    $file->save();
    return $file;
  }
}
