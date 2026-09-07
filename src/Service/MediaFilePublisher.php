<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;

/**
 * Copies private Studio files into public Media field destinations.
 */
final class MediaFilePublisher {

  /**
   * Constructs the Media file publisher.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
  ) {}

  /**
   * Creates a permanent public copy for a Media file field.
   */
  public function copyToPublic(
    FileInterface $source,
    FieldItemListInterface $items,
    array $token_data = [],
    ?string $filename = NULL,
  ): FileInterface {
    $item = $items->first();
    $upload_location = $item !== NULL && method_exists($item, 'getUploadLocation')
      ? (string) $item->getUploadLocation($token_data)
      : 'public://';
    $parts = explode('://', $upload_location, 2);
    $directory = 'public://' . trim($parts[1] ?? '', '/');
    $this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $filename ??= $source->getFilename();
    $copy = $this->fileRepository->copy(
      $source,
      rtrim($directory, '/') . '/' . $filename,
      FileExists::Rename,
    );
    $copy->setPermanent();
    $copy->save();
    return $copy;
  }

}
