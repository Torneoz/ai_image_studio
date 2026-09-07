<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Controller;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Exports generated storyboard frames as image collections and animatics.
 */
final class StoryboardExportController extends ControllerBase {

  /**
   * Constructs the storyboard export controller.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $storyboardEntityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('transliteration'),
    );
  }

  /**
   * Downloads generated frames and shot metadata as a ZIP archive.
   */
  public function downloadImages(object $ai_storyboard): BinaryFileResponse {
    $frames = $this->frames($ai_storyboard);
    if ($frames === []) {
      throw new NotFoundHttpException('This storyboard has no generated frames.');
    }

    $temporary_path = tempnam($this->fileSystem->getTempDirectory(), 'ai-storyboard-');
    if ($temporary_path === FALSE) {
      throw new \RuntimeException('Could not create a temporary archive.');
    }
    $archive = new \ZipArchive();
    if ($archive->open($temporary_path, \ZipArchive::OVERWRITE) !== TRUE) {
      @unlink($temporary_path);
      throw new \RuntimeException('Could not open the temporary archive.');
    }

    $manifest = [
      'title' => (string) $ai_storyboard->label(),
      'aspect_ratio' => (string) $ai_storyboard->get('aspect_ratio')->value,
      'shots' => [],
    ];
    foreach ($frames as $index => $frame) {
      $shot = $frame['shot'];
      $extension = strtolower(pathinfo($frame['file']->getFilename(), PATHINFO_EXTENSION));
      $filename = sprintf(
        '%03d-scene-%d-shot-%d-%s%s',
        $index + 1,
        $shot->get('scene_number')->value,
        $shot->get('shot_number')->value,
        $this->safeFilename((string) $shot->label()),
        $extension !== '' ? '.' . $extension : '',
      );
      $archive->addFile($frame['path'], 'images/' . $filename);
      $manifest['shots'][] = [
        'position' => (int) $shot->get('position')->value,
        'scene' => (int) $shot->get('scene_number')->value,
        'shot' => (int) $shot->get('shot_number')->value,
        'title' => (string) $shot->label(),
        'duration' => (float) $shot->get('duration')->value,
        'action' => (string) $shot->get('action')->value,
        'dialogue' => (string) $shot->get('dialogue')->value,
        'audio' => (string) $shot->get('audio')->value,
        'image' => 'images/' . $filename,
      ];
    }
    $archive->addFromString(
      'storyboard.json',
      (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
    $archive->close();

    return $this->downloadResponse(
      $temporary_path,
      $this->safeFilename((string) $ai_storyboard->label()) . '-images.zip',
    );
  }

  /**
   * Downloads an MP4 animatic built from generated frames and shot durations.
   */
  public function downloadVideo(object $ai_storyboard): BinaryFileResponse|RedirectResponse {
    if ((new ExecutableFinder())->find('ffmpeg') === NULL) {
      $this->messenger()->addError($this->t('MP4 export requires FFmpeg on the server.'));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }
    $frames = $this->frames($ai_storyboard);
    if ($frames === []) {
      $this->messenger()->addError($this->t('Generate at least one storyboard frame before exporting an MP4.'));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }

    [$width, $height] = $this->videoDimensions((string) $ai_storyboard->get('aspect_ratio')->value);
    $output_path = tempnam($this->fileSystem->getTempDirectory(), 'ai-storyboard-video-');
    if ($output_path === FALSE) {
      throw new \RuntimeException('Could not prepare the temporary MP4 file.');
    }
    $command = ['ffmpeg', '-y'];
    foreach ($frames as $frame) {
      $command = array_merge($command, [
        '-loop', '1',
        '-t', (string) max(0.1, (float) $frame['shot']->get('duration')->value),
        '-i', $frame['path'],
      ]);
    }
    $filters = [];
    $streams = '';
    foreach (array_keys($frames) as $index) {
      $filters[] = sprintf(
        '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=decrease,'
        . 'pad=%2$d:%3$d:(ow-iw)/2:(oh-ih)/2:color=black,'
        . 'setsar=1,fps=25,format=yuv420p[v%1$d]',
        $index,
        $width,
        $height,
      );
      $streams .= sprintf('[v%d]', $index);
    }
    $filters[] = sprintf(
      '%sconcat=n=%d:v=1:a=0[outv]',
      $streams,
      count($frames),
    );
    $command = array_merge($command, [
      '-filter_complex', implode(';', $filters),
      '-map', '[outv]',
      '-c:v', 'libx264',
      '-preset', 'medium',
      '-crf', '20',
      '-pix_fmt', 'yuv420p',
      '-movflags', '+faststart',
      '-f', 'mp4',
      $output_path,
    ]);

    $process = new Process($command);
    $process->setTimeout(900);
    $process->run();
    if (!$process->isSuccessful() || !is_file($output_path)
      || filesize($output_path) === 0) {
      @unlink($output_path);
      $this->messenger()->addError($this->t('The MP4 could not be created. FFmpeg reported: @message', [
        '@message' => mb_substr(trim($process->getErrorOutput()), 0, 500),
      ]));
      return new RedirectResponse($ai_storyboard->toUrl()->toString());
    }

    return $this->downloadResponse(
      $output_path,
      $this->safeFilename((string) $ai_storyboard->label()) . '-animatic.mp4',
    );
  }

  /**
   * Loads generated frames in storyboard position order.
   */
  private function frames(object $storyboard): array {
    $shot_storage = $this->storyboardEntityTypeManager->getStorage('ai_storyboard_shot');
    $shot_ids = $shot_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('storyboard_id', $storyboard->id())
      ->sort('position', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    $frames = [];
    foreach ($shot_storage->loadMultiple($shot_ids) as $shot) {
      $turn = $shot->get('studio_turn_id')->entity;
      $file = $turn?->get('image')->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }
      $path = $this->fileSystem->realpath($file->getFileUri());
      if ($path !== FALSE && is_file($path)) {
        $frames[] = ['shot' => $shot, 'file' => $file, 'path' => $path];
      }
    }
    return $frames;
  }

  /**
   * Returns an HD output canvas for a storyboard aspect ratio.
   */
  private function videoDimensions(string $aspect_ratio): array {
    return match ($aspect_ratio) {
      '9:16' => [720, 1280],
      '1:1' => [1080, 1080],
      '4:3' => [1280, 960],
      '2.39:1' => [1280, 536],
      default => [1280, 720],
    };
  }

  /**
   * Creates a self-cleaning attachment response.
   */
  private function downloadResponse(string $path, string $filename): BinaryFileResponse {
    $response = new BinaryFileResponse($path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $filename,
    );
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

  /**
   * Converts a label into a portable, non-empty filename.
   */
  private function safeFilename(string $label): string {
    $filename = strtolower($this->transliteration->transliterate($label));
    $filename = trim((string) preg_replace('/[^a-z0-9]+/', '-', $filename), '-');
    return $filename !== '' ? $filename : 'storyboard';
  }

}
