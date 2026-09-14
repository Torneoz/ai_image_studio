<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Plugin\AiProvider;

use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInput;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInterface;
use Drupal\ai\OperationType\ImageToImage\ImageToImageOutput;
use Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider;
use Gemini\Data\Blob;
use Gemini\Data\Content;
use Gemini\Data\GenerationConfig;
use Gemini\Data\ImageConfig;
use Gemini\Enums\ResponseModality;
use Gemini\Enums\Role;
use Drupal\ai\Exception\AiResponseErrorException;

/**
 * Adds Gemini's native multimodal image editing to the Drupal AI provider.
 *
 * Gemini image models accept an image and an editing instruction through the
 * generateContent endpoint. The upstream provider currently exposes the same
 * endpoint only as text-to-image.
 */
final class GeminiImageProvider extends GeminiProvider implements ImageToImageInterface {

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return array_values(array_unique([
      ...parent::getSupportedOperationTypes(),
      'image_to_image',
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    if ($operation_type !== 'image_to_image') {
      return parent::getConfiguredModels($operation_type, $capabilities);
    }

    // Gemini image models use the same generateContent API for both operations.
    return parent::getConfiguredModels('text_to_image', $capabilities);
  }

  /**
   * {@inheritdoc}
   */
  public function imageToImage(string|array|ImageToImageInput $input, string $model_id, array $tags = []): ImageToImageOutput {
    $this->loadClient();

    if (!$input instanceof ImageToImageInput) {
      throw new AiResponseErrorException('Gemini image editing requires an ImageToImageInput object.');
    }

    $image = $input->getImageFile();
    $prompt = trim((string) $input->getPrompt());
    if ($prompt === '') {
      throw new AiResponseErrorException('Gemini image editing requires an editing prompt.');
    }

    $config_args = [
      'responseModalities' => [ResponseModality::IMAGE],
    ];
    $configuration = $this->getConfiguration();
    if (!empty($configuration['aspectRatio'])) {
      $config_args['imageConfig'] = new ImageConfig(
        aspectRatio: $configuration['aspectRatio'],
      );
    }

    $config = new GenerationConfig(...$config_args);
    $content = Content::parse([
      $prompt,
      Blob::from([
        'mimeType' => $image->getMimeType(),
        'data' => $image->getAsBase64EncodedString(''),
      ]),
    ], Role::USER);

    try {
      $generative_model = $this->client->generativeModel($model_id);
      $this->applySafetySettings($generative_model);
      $response = $generative_model
        ->withGenerationConfig($config)
        ->generateContent($content);
    }
    catch (\Throwable $e) {
      throw new AiResponseErrorException($e->getMessage(), 0, $e);
    }

    $images = [];
    foreach ($response->parts() as $part) {
      if ($part->inlineData === NULL) {
        continue;
      }
      $mime_type = $part->inlineData->mimeType->value;
      $extension = match ($mime_type) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/webp' => 'webp',
        default => 'png',
      };
      $images[] = new ImageFile(
        base64_decode($part->inlineData->data),
        $mime_type,
        'generated-image.' . $extension,
      );
    }

    if ($images === []) {
      throw new AiResponseErrorException('No image data found in the Gemini image editing response.');
    }

    return new ImageToImageOutput($images, $response->toArray(), []);
  }

  /**
   * {@inheritdoc}
   */
  public function requiresImageToImageMask(string $model_id): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasImageToImageMask(string $model_id): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresImageToImagePrompt(string $model_id): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasImageToImagePrompt(string $model_id): bool {
    return TRUE;
  }

}
