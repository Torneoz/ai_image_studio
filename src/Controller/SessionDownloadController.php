<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Controller;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Downloads all generated assets belonging to a Studio session.
 */
final class SessionDownloadController extends ControllerBase {

  /**
   * Constructs the session download controller.
   */
  public function __construct(
    protected EntityTypeManagerInterface $studioEntityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected TransliterationInterface $transliteration,
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
   * Returns a ZIP containing every completed image and video result.
   */
  public function download(object $ai_image_studio_session): BinaryFileResponse {
    $turn_storage = $this->studioEntityTypeManager
      ->getStorage('ai_image_studio_turn');
    $turn_ids = $turn_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $ai_image_studio_session->id())
      ->condition('status', 'completed')
      ->sort('created', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    $temporary_path = tempnam($this->fileSystem->getTempDirectory(), 'ai-studio-');
    if ($temporary_path === FALSE) {
      throw new \RuntimeException('Could not create a temporary archive.');
    }
    $archive = new \ZipArchive();
    if ($archive->open($temporary_path, \ZipArchive::OVERWRITE) !== TRUE) {
      @unlink($temporary_path);
      throw new \RuntimeException('Could not open the temporary archive.');
    }

    $asset_count = 0;
    foreach (array_values($turn_storage->loadMultiple($turn_ids)) as $index => $turn) {
      $file = !$turn->get('video')->isEmpty()
        ? $turn->get('video')->entity
        : $turn->get('image')->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }
      $source_path = $this->fileSystem->realpath($file->getFileUri());
      if ($source_path === FALSE || !is_file($source_path)) {
        continue;
      }
      $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
      $base_name = $this->safeFilename((string) $this->t('version-@number', [
        '@number' => $index + 1,
      ]));
      $archive->addFile(
        $source_path,
        $base_name . ($extension !== '' ? '.' . strtolower($extension) : ''),
      );
      $asset_count++;
    }
    $archive->close();

    if ($asset_count === 0) {
      @unlink($temporary_path);
      throw new NotFoundHttpException('This session has no downloadable results.');
    }

    $download_name = $this->safeFilename((string) $ai_image_studio_session->label())
      . '-results.zip';
    $response = new BinaryFileResponse($temporary_path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $download_name,
    );
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

  /**
   * Joins completed videos in turn order with normalized timestamps.
   */
  public function joinVideos(
    object $ai_image_studio_session,
    Request $request,
  ): BinaryFileResponse|RedirectResponse {
    $executable_finder = new ExecutableFinder();
    if ($executable_finder->find('ffmpeg') === NULL || $executable_finder->find('ffprobe') === NULL) {
      $this->messenger()->addError($this->t('Joining videos requires FFmpeg and FFprobe on the server.'));
      return new RedirectResponse($ai_image_studio_session->toUrl()->toString());
    }

    $turn_storage = $this->studioEntityTypeManager
      ->getStorage('ai_image_studio_turn');
    $turn_ids = $turn_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $ai_image_studio_session->id())
      ->condition('status', 'completed')
      ->condition('video.target_id', NULL, 'IS NOT NULL')
      ->sort('created', 'ASC')
      ->sort('id', 'ASC')
      ->execute();
    $requested_turn_ids = array_values(array_unique(array_filter(array_map(
      'intval',
      explode(',', (string) $request->query->get('turn_ids', '')),
    ))));
    if ($requested_turn_ids !== []) {
      $turn_ids = array_intersect($turn_ids, $requested_turn_ids);
    }
    $source_paths = [];
    foreach ($turn_storage->loadMultiple($turn_ids) as $turn) {
      $file = $turn->get('video')->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }
      $path = $this->fileSystem->realpath($file->getFileUri());
      if ($path !== FALSE && is_file($path)) {
        $source_paths[] = $path;
      }
    }
    if (count($source_paths) < 2) {
      $this->messenger()->addError($this->t('At least two completed videos are required to create a joined video.'));
      return new RedirectResponse($ai_image_studio_session->toUrl()->toString());
    }

    $temporary_directory = $this->fileSystem->getTempDirectory();
    $output_path = tempnam($temporary_directory, 'ai-studio-joined-');
    if ($output_path === FALSE) {
      $this->messenger()->addError($this->t('Could not prepare temporary files for the joined video.'));
      return new RedirectResponse($ai_image_studio_session->toUrl()->toString());
    }

    try {
      $clips = array_map(fn (string $path): array => $this->videoMetadata($path), $source_paths);
      $width = max(array_column($clips, 'width'));
      $height = max(array_column($clips, 'height'));
      $width -= $width % 2;
      $height -= $height % 2;
      if ($width < 2 || $height < 2) {
        throw new \RuntimeException('One of the selected videos has invalid dimensions.');
      }
      $command = ['ffmpeg', '-y'];
      foreach ($source_paths as $source_path) {
        $command[] = '-i';
        $command[] = $source_path;
      }
      $filters = [];
      $concat_inputs = '';
      foreach ($clips as $index => $clip) {
        $filters[] = sprintf('[%1$d:v:0]setpts=PTS-STARTPTS,fps=30,scale=%2$d:%3$d:force_original_aspect_ratio=decrease,pad=%2$d:%3$d:(ow-iw)/2:(oh-ih)/2,setsar=1[v%1$d]', $index, $width, $height);
        $filters[] = $clip['has_audio']
          ? sprintf('[%1$d:a:0]aformat=sample_rates=48000:channel_layouts=stereo,apad,atrim=duration=%2$.6F,asetpts=PTS-STARTPTS[a%1$d]', $index, $clip['duration'])
          : sprintf('anullsrc=r=48000:cl=stereo,atrim=duration=%1$.6F,asetpts=PTS-STARTPTS[a%2$d]', $clip['duration'], $index);
        $concat_inputs .= sprintf('[v%1$d][a%1$d]', $index);
      }
      $filters[] = $concat_inputs . 'concat=n=' . count($clips) . ':v=1:a=1[v][a]';
      $command = array_merge($command, [
        '-filter_complex', implode(';', $filters), '-map', '[v]', '-map', '[a]',
        '-c:v', 'libx264', '-preset', 'medium', '-crf', '20',
        '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-ar', '48000',
        '-movflags', '+faststart', '-f', 'mp4', $output_path,
      ]);
      $process = new Process($command);
      $process->setTimeout(600);
      $process->run();
      if (!$process->isSuccessful() || !is_file($output_path)
        || filesize($output_path) === 0) {
        throw new \RuntimeException(trim($process->getErrorOutput()));
      }
    }
    catch (\Throwable $exception) {
      @unlink($output_path);
      $this->messenger()->addError($this->t('The videos could not be joined. FFmpeg reported: @message', [
        '@message' => mb_substr($exception->getMessage(), 0, 500),
      ]));
      return new RedirectResponse($ai_image_studio_session->toUrl()->toString());
    }
    $download_name = $this->safeFilename((string) $ai_image_studio_session->label())
      . '-joined.mp4';
    $response = new BinaryFileResponse($output_path);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $download_name,
    );
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

  /**
   * Reads the information required to normalize a source clip.
   */
  private function videoMetadata(string $path): array {
    $process = new Process([
      'ffprobe', '-v', 'error', '-show_entries',
      'format=duration:stream=codec_type,width,height', '-of', 'json', $path,
    ]);
    $process->setTimeout(30);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new \RuntimeException('Could not inspect a selected video.');
    }
    $metadata = json_decode($process->getOutput(), TRUE);
    if (!is_array($metadata)) {
      throw new \RuntimeException('Could not read selected video metadata.');
    }
    $video = NULL;
    $has_audio = FALSE;
    foreach ($metadata['streams'] ?? [] as $stream) {
      if (($stream['codec_type'] ?? '') === 'video' && $video === NULL) {
        $video = $stream;
      }
      $has_audio = $has_audio || (($stream['codec_type'] ?? '') === 'audio');
    }
    $duration = (float) ($metadata['format']['duration'] ?? 0);
    $width = (int) ($video['width'] ?? 0);
    $height = (int) ($video['height'] ?? 0);
    if ($video === NULL || !is_finite($duration) || $duration <= 0 || $width < 2 || $height < 2) {
      throw new \RuntimeException('A selected video has no usable video stream.');
    }
    return compact('duration', 'width', 'height', 'has_audio');
  }

  /**
   * Converts a label into a portable, non-empty filename.
   */
  private function safeFilename(string $label): string {
    $filename = strtolower($this->transliteration->transliterate($label));
    $filename = trim((string) preg_replace('/[^a-z0-9]+/', '-', $filename), '-');
    return $filename !== '' ? $filename : 'ai-image-studio';
  }

}
