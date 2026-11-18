<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Symfony\Component\Process\Process;

final class NarrationAudioMerger {
  public function __construct(private readonly FileSystemInterface $fileSystem, private readonly FileRepositoryInterface $fileRepository) {}

  /** Joins compatible narration files without re-encoding them. */
  public function merge(array $files, string $name): ?FileInterface {
    if (count($files) < 2) return NULL;
    $directory = 'temporary://ai-narration-merge';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $list = $this->fileSystem->realpath($directory . '/inputs-' . uniqid() . '.txt');
    $output = $this->fileSystem->realpath($directory . '/entire-' . uniqid() . '.' . pathinfo($files[0]->getFilename(), PATHINFO_EXTENSION));
    $lines = array_map(fn (FileInterface $file) => "file '" . str_replace("'", "'\\\\''", $this->fileSystem->realpath($file->getFileUri())) . "'", $files);
    file_put_contents($list, implode("\n", $lines));
    $process = new Process(['ffmpeg', '-y', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', $output]);
    $process->run();
    @unlink($list);
    if (!$process->isSuccessful() || !is_file($output)) return NULL;
    $uri = 'public://ai-narration/' . $this->fileSystem->basename($output);
    $this->fileSystem->prepareDirectory('public://ai-narration', FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->fileSystem->copy($output, $uri, FileSystemInterface::EXISTS_RENAME);
    @unlink($output);
    return $this->fileRepository->createFileFromUri($uri, TRUE);
  }
}
