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
    $list = $this->fileSystem->realpath($directory . '/inputs-' . uniqid() . '.txt');
    $output = $this->fileSystem->realpath($directory . '/entire-' . uniqid() . '.mp3');
    $lines = array_map(fn (FileInterface $file) => "file '" . str_replace("'", "'\\\\''", $this->fileSystem->realpath($file->getFileUri())) . "'", $files);
    file_put_contents($list, implode("\n", $lines));
    // Re-encode rather than stream-copy: providers can produce files with
    // incompatible timing metadata, which otherwise yields silent joins.
    $process = new Process(['ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', $list, '-c:a', 'libmp3lame', '-q:a', '2', $output]);
    $process->run();
    @unlink($list);
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
