<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates Studio turns through Drupal AI operation types.
 */
final class ImageGenerator {

  /**
   * Constructs the generator.
   */
  public function __construct(
    private readonly AiProviderPluginManager $providerManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ?object $pricingCatalog = NULL,
  ) {}

  /**
   * Returns configured provider/model options for an operation.
   */
  public function getModelOptions(string $operation): array {
    return $this->providerManager->getSimpleProviderModelOptions($operation, TRUE);
  }

  /**
   * Returns the configured default option for an operation.
   */
  public function getDefaultModel(string $operation): string {
    $options = $this->providerManager->getSimpleProviderModelOptions(
      $operation,
      FALSE,
    );
    $default = $this->providerManager->getSimpleDefaultProviderOptions($operation);
    return isset($options[$default])
      ? $default
      : (string) (array_key_first($options) ?? '');
  }

  /**
   * Generates and persists a turn.
   *
   * @param \Drupal\ai_image_studio\Entity\ImageStudioSession $session
   *   The owning session.
   * @param string $prompt
   *   The generation or editing prompt.
   * @param string $model_option
   *   A Drupal AI provider/model simple option.
   * @param \Drupal\ai_image_studio\Entity\ImageStudioTurn|null $parent
   *   The previous generated turn.
   * @param \Drupal\file\FileInterface|null $source
   *   An optional uploaded source image for the first turn.
   * @param array $generation_settings
   *   Provider configuration for image dimensions and output options.
   *
   * @return \Drupal\ai_image_studio\Entity\ImageStudioTurn
   *   The saved completed or failed turn.
   */
  public function generate(
    object $session,
    string $prompt,
    string $model_option,
    ?object $parent = NULL,
    ?FileInterface $source = NULL,
    array $generation_settings = [],
  ): object {
    $source ??= $parent && !$parent->get('image')->isEmpty()
      ? $parent->get('image')->entity
      : NULL;
    $operation = $source instanceof FileInterface ? 'image_to_image' : 'text_to_image';
    [$provider_id, $model_id] = $this->parseModelOption($model_option);

    $turn = $this->entityTypeManager->getStorage('ai_image_studio_turn')->create([
      'session_id' => $session->id(),
      'parent_id' => $parent?->id(),
      'prompt' => $prompt,
      'provider_id' => $provider_id,
      'model_id' => $model_id,
      'operation' => $operation,
      'generation_settings' => $generation_settings,
      'status' => 'pending',
    ]);
    $turn->save();
    $started_at = hrtime(TRUE);

    try {
      $provider = $this->providerManager->createInstance($provider_id);
      $provider->setConfiguration($this->normalizeGenerationSettings(
        $provider_id,
        $operation,
        $generation_settings,
      ));
      if ($operation === 'image_to_image') {
        $binary = file_get_contents($source->getFileUri());
        if ($binary === FALSE) {
          throw new \RuntimeException('The source image could not be read.');
        }
        $image = new ImageFile($binary, $source->getMimeType(), $source->getFilename());
        $input = new ImageToImageInput($image);
        $input->setPrompt($prompt);
        $output = $provider->imageToImage($input, $model_id, ['ai_image_studio']);
      }
      else {
        $output = $provider->textToImage(
          new TextToImageInput($prompt),
          $model_id,
          ['ai_image_studio'],
        );
      }
      $duration_ms = (int) round((hrtime(TRUE) - $started_at) / 1_000_000);
      $metadata = is_array($output->getMetadata()) ? $output->getMetadata() : [];
      $raw_output = is_array($output->getRawOutput()) ? $output->getRawOutput() : [];
      $token_usage = $this->findTokenUsage($metadata, $raw_output);
      $reported_cost = $this->findReportedCost($metadata, $raw_output);
      $estimated_cost = $reported_cost ?? $this->estimateCost(
        $provider_id,
        $operation,
        $model_id,
        $generation_settings,
        $metadata,
      );

      $images = $output->getNormalized();
      $image = reset($images);
      if (!$image instanceof ImageFile || $image->getBinary() === '') {
        throw new \RuntimeException('The provider returned no generated image.');
      }

      $file = $this->saveGeneratedFile($image, (int) $session->id(), (int) $turn->id());
      $turn->set('image', ['target_id' => $file->id()]);
      $turn->set('duration_ms', $duration_ms);
      $turn->set('estimated_cost', $estimated_cost);
      $turn->set('token_usage', $token_usage);
      $turn->set('cost_source', $reported_cost !== NULL
        ? 'reported'
        : ($estimated_cost !== NULL ? 'estimated' : 'unavailable'));
      $turn->set('status', 'completed');
      $turn->save();
      $session->setChangedTime($this->time->getRequestTime());
      $session->save();
    }
    catch (\Throwable $exception) {
      $message = mb_substr($exception->getMessage(), 0, 4000);
      $turn->set('duration_ms', (int) round((hrtime(TRUE) - $started_at) / 1_000_000));
      $turn->set('status', 'failed');
      $turn->set('error_message', $message);
      $turn->save();
      $this->logger->error('Image generation failed for session @session: @message', [
        '@session' => $session->id(),
        '@message' => $message,
      ]);
    }

    return $turn;
  }

  /**
   * Publishes a completed turn to the configured Media bundle.
   */
  public function publish(object $turn, string $name, string $alt): MediaInterface {
    if ($turn->get('status')->value !== 'completed' || $turn->get('image')->isEmpty()) {
      throw new \LogicException('Only completed image turns can be published.');
    }
    if (!$turn->get('media_id')->isEmpty()) {
      $media = $turn->get('media_id')->entity;
      if ($media instanceof MediaInterface) {
        return $media;
      }
    }

    $settings = $this->configFactory->get('ai_image_studio.settings');
    $bundle = (string) $settings->get('media_bundle');
    $field = (string) $settings->get('media_source_field');
    $file = $turn->get('image')->entity;
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException('The generated file is unavailable.');
    }

    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $bundle,
      'name' => $name,
      $field => [
        'target_id' => $file->id(),
        'alt' => $alt,
      ],
      'status' => 1,
    ]);
    $media->save();
    $turn->set('media_id', ['target_id' => $media->id()]);
    $turn->save();
    return $media;
  }

  /**
   * Splits a Drupal AI simple provider/model option.
   */
  private function parseModelOption(string $option): array {
    $parts = explode('__', $option, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw new \InvalidArgumentException('Select a configured AI provider and model.');
    }
    return $parts;
  }

  /**
   * Normalizes Studio controls for the selected provider.
   */
  private function normalizeGenerationSettings(
    string $provider_id,
    string $operation,
    array $settings,
  ): array {
    if (str_contains($provider_id, 'openai') && $operation === 'text_to_image') {
      $ratio = (string) ($settings['aspect_ratio'] ?? 'auto');
      $portrait = ['9:16', '3:4', '2:3', '1:2', '9:19.5', '9:20'];
      $landscape = ['16:9', '4:3', '3:2', '2:1', '19.5:9', '20:9'];
      $size = match (TRUE) {
        in_array($ratio, $portrait, TRUE) => '1024x1536',
        in_array($ratio, $landscape, TRUE) => '1536x1024',
        default => '1024x1024',
      };
      return [
        'size' => $size,
        'quality' => ($settings['resolution'] ?? '1k') === '2k'
          ? 'high'
          : 'auto',
        'output_format' => 'png',
      ];
    }

    // Do not send unknown configuration keys to providers that have not
    // declared a compatible mapping because some APIs reject them.
    if (!str_contains($provider_id, 'grok')) {
      return [];
    }

    $normalized = array_filter([
      'aspect_ratio' => (string) ($settings['aspect_ratio'] ?? ''),
      'resolution' => (string) ($settings['resolution'] ?? ''),
      'transparent_background' => !empty($settings['transparent_background']),
    ], static fn (mixed $value): bool => $value !== '' && $value !== FALSE);

    // xAI image editing does not accept the text-to-image "auto" ratio.
    if ($operation === 'image_to_image'
      && ($normalized['aspect_ratio'] ?? '') === 'auto') {
      unset($normalized['aspect_ratio']);
    }

    // Transparency is a Grok text-to-image prompting feature.
    if ($operation !== 'text_to_image') {
      unset($normalized['transparent_background']);
    }

    return $normalized;
  }

  /**
   * Finds xAI's provider-reported request cost in nested response data.
   */
  private function findReportedCost(array ...$sources): ?float {
    foreach ($sources as $source) {
      $ticks = $this->findNestedNumericValue($source, 'cost_in_usd_ticks');
      if ($ticks !== NULL) {
        return $ticks / 10_000_000_000;
      }
    }
    return NULL;
  }

  /**
   * Finds and normalizes provider-reported token usage.
   */
  private function findTokenUsage(array $metadata, array $raw_output): array {
    $usage = [];
    foreach ([
      $metadata['token_usage'] ?? NULL,
      $metadata['usage'] ?? NULL,
      $raw_output['usage'] ?? NULL,
      $metadata['response']['usage'] ?? NULL,
    ] as $candidate) {
      if (is_array($candidate)) {
        $usage = $candidate;
        break;
      }
    }

    $input = $this->nullableInteger(
      $usage['input'] ?? $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? NULL,
    );
    $output = $this->nullableInteger(
      $usage['output'] ?? $usage['output_tokens'] ?? $usage['completion_tokens'] ?? NULL,
    );
    $total = $this->nullableInteger($usage['total'] ?? $usage['total_tokens'] ?? NULL);
    if ($total === NULL && ($input !== NULL || $output !== NULL)) {
      $total = ($input ?? 0) + ($output ?? 0);
    }

    return array_filter([
      'input' => $input,
      'output' => $output,
      'total' => $total,
      'cached' => $this->nullableInteger(
        $usage['cached']
          ?? $usage['input_tokens_details']['cached_tokens']
          ?? NULL,
      ),
      'reasoning' => $this->nullableInteger(
        $usage['reasoning']
          ?? $usage['output_tokens_details']['reasoning_tokens']
          ?? NULL,
      ),
    ], static fn (?int $value): bool => $value !== NULL);
  }

  /**
   * Converts a numeric token count to an integer.
   */
  private function nullableInteger(mixed $value): ?int {
    return is_numeric($value) ? (int) $value : NULL;
  }

  /**
   * Recursively finds a numeric response value.
   */
  private function findNestedNumericValue(array $data, string $key): ?float {
    if (isset($data[$key]) && is_numeric($data[$key])) {
      return (float) $data[$key];
    }
    foreach ($data as $value) {
      if (is_array($value)) {
        $found = $this->findNestedNumericValue($value, $key);
        if ($found !== NULL) {
          return $found;
        }
      }
    }
    return NULL;
  }

  /**
   * Estimates cost through Torneo's shared pricing catalogue when available.
   */
  private function estimateCost(
    string $provider_id,
    string $operation,
    string $model_id,
    array $settings,
    array $metadata,
  ): ?float {
    if ($this->pricingCatalog === NULL
      || !method_exists($this->pricingCatalog, 'estimate')) {
      return NULL;
    }
    $provider = match (TRUE) {
      str_contains($provider_id, 'grok') => 'grok',
      str_contains($provider_id, 'openai') => 'openai',
      str_contains($provider_id, 'anthropic') => 'anthropic',
      default => $provider_id,
    };
    try {
      $cost = $this->pricingCatalog->estimate(
        $provider,
        $operation,
        $model_id,
        $settings,
        NULL,
        $metadata,
      );
      return is_numeric($cost) ? (float) $cost : NULL;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not estimate image cost: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Writes a normalized image to managed file storage.
   */
  private function saveGeneratedFile(ImageFile $image, int $session_id, int $turn_id): FileInterface {
    $settings = $this->configFactory->get('ai_image_studio.settings');
    $scheme = (string) ($settings->get('file_scheme') ?: 'private');
    $directory = trim((string) ($settings->get('file_directory') ?: 'ai-image-studio'), '/');
    $destination_directory = sprintf('%s://%s/%d', $scheme, $directory, $session_id);
    $this->fileSystem->prepareDirectory(
      $destination_directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $extension = match ($image->getMimeType()) {
      'image/jpeg' => 'jpg',
      'image/webp' => 'webp',
      'image/gif' => 'gif',
      default => 'png',
    };
    $destination = sprintf('%s/turn-%d.%s', $destination_directory, $turn_id, $extension);
    $file = $this->fileRepository->writeData(
      $image->getBinary(),
      $destination,
      FileExists::Rename,
    );
    $file->setPermanent();
    $file->save();
    return $file;
  }

}
