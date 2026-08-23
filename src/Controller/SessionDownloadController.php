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
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Downloads all generated assets belonging to a Studio session.
 */
final class SessionDownloadController extends ControllerBase {

  /**
   * Constructs the session download controller.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
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
    $turn_storage = $this->entityTypeManager->getStorage('ai_image_studio_turn');
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
   * Converts a label into a portable, non-empty filename.
   */
  private function safeFilename(string $label): string {
    $filename = strtolower($this->transliteration->transliterate($label));
    $filename = trim((string) preg_replace('/[^a-z0-9]+/', '-', $filename), '-');
    return $filename !== '' ? $filename : 'ai-image-studio';
  }

}
