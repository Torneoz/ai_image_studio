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
    if ((new ExecutableFinder())->find('ffmpeg') === NULL) {
      $this->messenger()->addError($this->t('Joining videos requires FFmpeg on the server.'));
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
    $manifest_path = tempnam($temporary_directory, 'ai-studio-videos-');
    $output_path = tempnam($temporary_directory, 'ai-studio-joined-');
    if ($manifest_path === FALSE || $output_path === FALSE) {
      $this->messenger()->addError($this->t('Could not prepare temporary files for the joined video.'));
      return new RedirectResponse($ai_image_studio_session->toUrl()->toString());
    }

    try {
      $manifest = implode("\n", array_map(
        static fn (string $path): string => "file '"
          . str_replace("'", "'\\''", $path) . "'",
        $source_paths,
      )) . "\n";
      if (file_put_contents($manifest_path, $manifest) === FALSE) {
        throw new \RuntimeException('Could not write the FFmpeg input list.');
      }
      $process = new Process([
        'ffmpeg', '-y', '-fflags', '+genpts',
        '-f', 'concat', '-safe', '0', '-i', $manifest_path,
        '-map', '0:v:0', '-map', '0:a?',
        '-c:v', 'libx264', '-preset', 'medium', '-crf', '20',
        '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k',
        '-avoid_negative_ts', 'make_zero', '-movflags', '+faststart',
        '-f', 'mp4', $output_path,
      ]);
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
    finally {
      @unlink($manifest_path);
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
   * Converts a label into a portable, non-empty filename.
   */
  private function safeFilename(string $label): string {
    $filename = strtolower($this->transliteration->transliterate($label));
    $filename = trim((string) preg_replace('/[^a-z0-9]+/', '-', $filename), '-');
    return $filename !== '' ? $filename : 'ai-image-studio';
  }

}
