<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\GenericType\VideoFile;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInput;
use Drupal\ai\OperationType\ImageToVideo\ImageToVideoInput;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\media\MediaInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Generates Studio turns through Drupal AI operation types.
 */
final class ImageGenerator {

  /**
   * Translates module-owned messages that may be surfaced to users.
   */
  private function translate(string $string, array $arguments = []): string {
    return (string) new TranslatableMarkup($string, $arguments);
  }

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
    private readonly QueueFactory $queueFactory,
    private readonly ClientInterface $httpClient,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly TransliterationInterface $transliteration,
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
   * Reports whether a selected option supports Grok multi-image editing.
   */
  public function supportsMultipleImages(string $model_option): bool {
    [$provider_id] = $this->parseModelOption($model_option);
    return $this->isXaiProvider($provider_id);
  }

  /**
   * Returns the maximum number of outputs supported by an image model.
   */
  public function getMaxVariations(string $model_option): int {
    try {
      [$provider_id, $model_id] = $this->parseModelOption($model_option);
    }
    catch (\InvalidArgumentException) {
      return 1;
    }
    $model = strtolower($model_id);

    if ($this->isXaiProvider($provider_id)) {
      return 10;
    }
    if (str_contains($model, 'dall-e-3')) {
      return 1;
    }
    if (str_contains($model, 'gpt-image')
      || str_contains($model, 'dall-e-2')) {
      return 10;
    }
    if (str_contains($model, 'imagen-')) {
      return 4;
    }
    // Models without a known multi-output API default to one result.
    return 1;
  }

  /**
   * Reports whether a selected option supports Grok reference-to-video.
   */
  public function supportsReferenceVideo(string $model_option): bool {
    [$provider_id, $model_id] = $this->parseModelOption($model_option);
    return $this->isXaiProvider($provider_id)
      && !str_contains(strtolower($model_id), 'video-1.5');
  }

  /**
   * Reports whether the server can render a badge into the requested media.
   */
  public function canRenderBadge(bool $is_video): bool {
    if (!function_exists('imagecreatetruecolor')) {
      return FALSE;
    }
    return !$is_video || (new ExecutableFinder())->find('ffmpeg') !== NULL;
  }

  /**
   * Reports whether generated images can be auto-levelled on this server.
   */
  public function canAutoLevels(): bool {
    return class_exists(\Imagick::class)
      && method_exists(\Imagick::class, 'autoLevelImage');
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
   * @param string $output_type
   *   Either image or video.
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
    string $output_type = 'image',
  ): object {
    $source ??= $parent && !$parent->get('image')->isEmpty()
      ? $parent->get('image')->entity
      : NULL;
    $reference_ids = array_values(array_unique(array_filter(array_map(
      'intval',
      (array) ($generation_settings['reference_file_ids'] ?? []),
    ))));
    if ($source instanceof FileInterface) {
      array_unshift($reference_ids, (int) $source->id());
      $reference_ids = array_values(array_unique($reference_ids));
    }
    $references = $this->entityTypeManager->getStorage('file')
      ->loadMultiple($reference_ids);
    $references = array_values(array_filter(array_map(
      static fn (int $id): mixed => $references[$id] ?? NULL,
      $reference_ids,
    ), static fn (mixed $file): bool => $file instanceof FileInterface));
    foreach ($references as $reference) {
      if ($reference->isTemporary()) {
        $reference->setPermanent();
        $reference->save();
      }
    }
    $source ??= $references[0] ?? NULL;
    $generation_settings = $this->resolveAutomaticGenerationSettings(
      $generation_settings,
      $source instanceof FileInterface ? $source : NULL,
    );
    $video_mode = (string) ($generation_settings['video_mode'] ?? '');
    $operation = $output_type === 'video'
      ? ($video_mode === 'reference'
        ? 'reference_to_video'
        : ($source instanceof FileInterface ? 'image_to_video' : 'text_to_video'))
      : ($source instanceof FileInterface ? 'image_to_image' : 'text_to_image');
    [$provider_id, $model_id] = $this->parseModelOption($model_option);

    $turn = $this->entityTypeManager->getStorage('ai_image_studio_turn')->create([
      'session_id' => $session->id(),
      'parent_id' => $parent?->id(),
      'source_file_id' => $source?->id(),
      'source_file_ids' => array_map(
        static fn (FileInterface $file): array => ['target_id' => $file->id()],
        $references,
      ),
      'request_group' => bin2hex(random_bytes(16)),
      'prompt' => $prompt,
      'provider_id' => $provider_id,
      'model_id' => $model_id,
      'operation' => $operation,
      'generation_settings' => $generation_settings,
      'status' => 'pending',
    ]);
    $turn->save();

    if ($output_type === 'video'
      && $this->configFactory->get('ai_image_studio.settings')
        ->get('async_video_generation') !== FALSE) {
      $turn->set('status', 'queued');
      $turn->save();
      $this->queueFactory->get('ai_image_studio_generation')
        ->createItem(['turn_id' => (int) $turn->id()]);
      return $turn;
    }

    return $this->processTurn($turn);
  }

  /**
   * Processes a pending or queued generation turn.
   */
  public function processTurn(object $turn): object {
    $session = $turn->get('session_id')->entity;
    if ($session === NULL) {
      throw new \RuntimeException($this->translate('The image session is unavailable.'));
    }
    $sources = array_values(array_filter(array_map(
      static fn (mixed $item): mixed => $item->entity,
      iterator_to_array($turn->get('source_file_ids')),
    ), static fn (mixed $file): bool => $file instanceof FileInterface));
    $source = $sources[0] ?? $turn->get('source_file_id')->entity;
    $operation = (string) $turn->get('operation')->value;
    $output_type = in_array($operation, [
      'text_to_video',
      'image_to_video',
      'reference_to_video',
    ], TRUE)
      ? 'video'
      : 'image';
    $prompt = (string) $turn->get('prompt')->value;
    $provider_id = (string) $turn->get('provider_id')->value;
    $model_id = (string) $turn->get('model_id')->value;
    $generation_settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
    if ($turn->get('provider_request_id')->isEmpty()) {
      $turn->set('attempt_count', (int) $turn->get('attempt_count')->value + 1);
    }
    $turn->set('status', 'processing');
    $turn->set('error_message', NULL);
    $turn->save();
    $started_at = hrtime(TRUE);

    try {
      if ($operation === 'reference_to_video' && $this->isXaiProvider($provider_id)) {
        return $this->processXaiReferenceVideo(
          $turn,
          $session,
          $sources,
          $prompt,
          $model_id,
          $generation_settings,
          $started_at,
        );
      }
      if (in_array($operation, ['text_to_image', 'image_to_image'], TRUE)
        && $this->isXaiProvider($provider_id)
        && ((int) ($generation_settings['variations'] ?? 1) > 1
          || count($sources) > 1)) {
        return $this->processXaiImageRequest(
          $turn,
          $session,
          $sources,
          $prompt,
          $provider_id,
          $model_id,
          $generation_settings,
          $started_at,
        );
      }
      $provider = $this->providerManager->createInstance($provider_id);
      $provider->setConfiguration($this->normalizeGenerationSettings(
        $provider_id,
        $operation,
        $model_id,
        $generation_settings,
      ));
      if (in_array($operation, ['image_to_image', 'image_to_video'], TRUE)) {
        $binary = file_get_contents($source->getFileUri());
        if ($binary === FALSE) {
          throw new \RuntimeException($this->translate(
            'The source image could not be read.',
          ));
        }
        $image = new ImageFile($binary, $source->getMimeType(), $source->getFilename());
        if ($operation === 'image_to_video') {
          $output = $provider->imageToVideo(
            new ImageToVideoInput($image),
            $model_id,
            ['ai_image_studio'],
          );
        }
        else {
          $input = new ImageToImageInput($image);
          $input->setPrompt($prompt);
          $output = $provider->imageToImage($input, $model_id, ['ai_image_studio']);
        }
      }
      elseif ($operation === 'text_to_video') {
        $output = $provider->textToVideo(
          $prompt,
          $model_id,
          ['ai_image_studio'],
        );
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

      $outputs = array_values(array_filter(
        (array) $output->getNormalized(),
        static fn (mixed $item): bool => $output_type === 'video'
          ? $item instanceof VideoFile && $item->getBinary() !== ''
          : $item instanceof ImageFile && $item->getBinary() !== '',
      ));
      if ($outputs === []) {
        throw new \RuntimeException($this->translate(
          $output_type === 'video'
            ? 'The provider returned no generated video.'
            : 'The provider returned no generated image.',
        ));
      }

      $provider_metadata = array_filter([
        'actual_model' => $this->findNestedScalarValue($raw_output, 'model')
        ?? $this->findNestedScalarValue($metadata, 'model'),
        'request_id' => $this->findNestedScalarValue($raw_output, 'request_id')
        ?? $this->findNestedScalarValue($metadata, 'request_id'),
        'revised_prompt' => $this->findNestedScalarValue($raw_output, 'revised_prompt'),
        'respect_moderation' => $this->findNestedScalarValue($raw_output, 'respect_moderation')
        ?? $this->findNestedScalarValue($metadata, 'respect_moderation'),
        'output_count' => count($outputs),
      ], static fn (mixed $value): bool => $value !== NULL && $value !== '');
      $cost_per_output = $estimated_cost === NULL
        ? NULL
        : $estimated_cost / count($outputs);

      foreach ($outputs as $index => $generated) {
        $result_turn = $index === 0
          ? $turn
          : $this->createVariationTurn($turn);
        $file = $output_type === 'video'
          ? $this->saveGeneratedVideo(
            $generated,
            $session,
            (int) $result_turn->id(),
          )
          : $this->saveGeneratedFile(
            $generated,
            $session,
            (int) $result_turn->id(),
            $generation_settings,
          );
        $result_turn->set($output_type, ['target_id' => $file->id()]);
        $result_turn->set('duration_ms', $duration_ms);
        $result_turn->set('estimated_cost', $cost_per_output);
        $result_turn->set('token_usage', $index === 0 ? $token_usage : []);
        $result_turn->set('provider_metadata', $provider_metadata);
        $result_turn->set('cost_source', $reported_cost !== NULL
          ? 'reported'
          : ($estimated_cost !== NULL ? 'estimated' : 'unavailable'));
        $result_turn->set('status', 'completed');
        $result_turn->save();
      }
      $session->setChangedTime($this->time->getRequestTime());
      $session->save();
    }
    catch (\Throwable $exception) {
      $message = mb_substr($exception->getMessage(), 0, 4000);
      if (!$turn->get('provider_request_id')->isEmpty()) {
        $turn->set('attempt_count', (int) $turn->get('attempt_count')->value + 1);
      }
      $turn->set('duration_ms', (int) round((hrtime(TRUE) - $started_at) / 1_000_000));
      $turn->set('status', 'failed');
      $turn->set('error_message', $message);
      $turn->save();
      $this->logger->error('Media generation failed for session @session: @message', [
        '@session' => $session->id(),
        '@message' => $message,
      ]);
    }

    return $turn;
  }

  /**
   * Sends a native Grok image request and persists every returned variation.
   */
  private function processXaiImageRequest(
    object $turn,
    object $session,
    array $sources,
    string $prompt,
    string $provider_id,
    string $model_id,
    array $settings,
    int $started_at,
  ): object {
    $is_edit = $sources !== [];
    $image_references = array_map(
      fn (FileInterface $file): array => $this->xaiImageReference($file),
      array_slice($sources, 0, 3),
    );
    $aspect_ratio = (string) ($settings['aspect_ratio'] ?? 'auto');
    if ($is_edit && $aspect_ratio === 'auto') {
      $aspect_ratio = '';
    }
    $variation_count = max(1, min(
      $this->getMaxVariations($provider_id . '__' . $model_id),
      (int) ($settings['variations'] ?? 1),
    ));
    $payload = array_filter([
      'model' => $model_id,
      'prompt' => $prompt,
      'image' => $is_edit && count($sources) === 1
        ? $image_references[0]
        : NULL,
      'images' => count($sources) > 1
        ? $image_references
        : NULL,
      'aspect_ratio' => $aspect_ratio,
      'resolution' => $settings['resolution'] ?? NULL,
      'quality' => str_contains(strtolower($model_id), 'image-2.0')
        ? ($settings['quality'] ?? NULL)
        : NULL,
      'response_format' => 'url',
      'n' => $variation_count,
      'storage_options' => ['filename' => sprintf('studio-turn-%d.png', $turn->id())],
    ], static fn (mixed $value): bool => $value !== NULL && $value !== '');
    $response = $this->xaiRequest(
      $provider_id,
      'POST',
      $is_edit ? 'images/edits' : 'images/generations',
      $payload,
    );
    $items = array_values((array) ($response['data'] ?? []));
    if ($items === []) {
      throw new \RuntimeException($this->translate('Grok returned no generated image.'));
    }

    $reported_cost = $this->findReportedCost($response);
    $estimated_cost = $reported_cost ?? $this->estimateCost(
      $provider_id,
      $is_edit ? 'image_to_image' : 'text_to_image',
      $model_id,
      $settings,
      $response,
    );
    foreach ($items as $index => $item) {
      $binary = !empty($item['b64_json'])
        ? base64_decode((string) $item['b64_json'], TRUE)
        : $this->downloadProviderAsset((string) ($item['url'] ?? ''));
      if (!is_string($binary) || $binary === '') {
        throw new \RuntimeException($this->translate('The generated image could not be downloaded.'));
      }
      $result_turn = $index === 0 ? $turn : $this->createVariationTurn($turn);
      $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary) ?: 'image/png';
      $file = $this->saveGeneratedFile(
        new ImageFile($binary, $mime, sprintf('grok-image-%d.png', $result_turn->id())),
        $session,
        (int) $result_turn->id(),
        $settings,
      );
      $result_turn->set('image', ['target_id' => $file->id()]);
      $result_turn->set('duration_ms', (int) round((hrtime(TRUE) - $started_at) / 1_000_000));
      $result_turn->set('estimated_cost', $estimated_cost === NULL
        ? NULL
        : $estimated_cost / count($items));
      $result_turn->set('cost_source', $reported_cost !== NULL
        ? 'reported'
        : ($estimated_cost !== NULL ? 'estimated' : 'unavailable'));
      $result_turn->set('provider_metadata', [
        'request_id' => $response['request_id'] ?? NULL,
        'file_id' => $item['file_id'] ?? $item['file_output']['file_id'] ?? NULL,
        'requested_output_count' => $variation_count,
        'output_count' => count($items),
        'input_file_ids' => array_map(
          static fn (FileInterface $file): int => (int) $file->id(),
          $sources,
        ),
        'input_count' => count($sources),
        'usage' => $response['usage'] ?? [],
      ]);
      $result_turn->set('status', 'completed');
      $result_turn->save();
    }
    $session->setChangedTime($this->time->getRequestTime());
    $session->save();
    return $turn;
  }

  /**
   * Submits or polls a native asynchronous Grok reference-video request.
   */
  private function processXaiReferenceVideo(
    object $turn,
    object $session,
    array $sources,
    string $prompt,
    string $model_id,
    array $settings,
    int $started_at,
  ): object {
    $provider_id = (string) $turn->get('provider_id')->value;
    $request_id = (string) $turn->get('provider_request_id')->value;
    if ($request_id === '') {
      $response = $this->xaiRequest($provider_id, 'POST', 'videos/generations', array_filter([
        'model' => $model_id,
        'prompt' => $prompt,
        'reference_images' => array_map(
          fn (FileInterface $file): array => $this->xaiImageReference($file),
          array_slice($sources, 0, 7),
        ),
        'duration' => min(10, (int) ($settings['duration'] ?? 5)),
        'aspect_ratio' => ($settings['aspect_ratio'] ?? 'auto') === 'auto'
          ? NULL
          : $settings['aspect_ratio'],
        'resolution' => $settings['resolution'] ?? '720p',
      ], static fn (mixed $value): bool => $value !== NULL && $value !== ''));
      $request_id = (string) ($response['request_id'] ?? '');
      if ($request_id === '') {
        throw new \RuntimeException($this->translate('Grok did not return a video request ID.'));
      }
      $turn->set('provider_request_id', $request_id);
      $turn->set('provider_metadata', [
        'request_id' => $request_id,
        'provider_status' => 'pending',
        'input_file_ids' => array_map(
          static fn (FileInterface $file): int => (int) $file->id(),
          $sources,
        ),
        'input_count' => count($sources),
      ]);
      $turn->set('status', 'processing');
      $turn->save();
      return $turn;
    }

    $response = $this->xaiRequest($provider_id, 'GET', 'videos/' . rawurlencode($request_id));
    $status = (string) ($response['status'] ?? 'pending');
    if ($status === 'pending') {
      $metadata = (array) ($turn->get('provider_metadata')->first()?->getValue() ?? []);
      $metadata['provider_status'] = 'pending';
      $metadata['progress'] = $response['progress'] ?? NULL;
      $turn->set('provider_metadata', $metadata);
      $turn->set('status', 'processing');
      $turn->save();
      return $turn;
    }
    if (in_array($status, ['failed', 'expired'], TRUE)) {
      $message = (string) ($response['error']['message'] ?? $response['error'] ?? 'No provider diagnostic was returned.');
      $turn->set('status', $status);
      $turn->set('error_message', $message);
      $turn->set('provider_metadata', $response + [
        'request_id' => $request_id,
        'provider_status' => $status,
      ]);
      $turn->save();
      return $turn;
    }
    if ($status !== 'done' || empty($response['video']['url'])) {
      throw new \RuntimeException($this->translate('Grok returned an unknown video status: @status', [
        '@status' => $status,
      ]));
    }

    $binary = $this->downloadProviderAsset((string) $response['video']['url']);
    $file = $this->saveGeneratedVideo(
      new VideoFile($binary, 'video/mp4', sprintf('grok-video-%d.mp4', $turn->id())),
      $session,
      (int) $turn->id(),
    );
    $cost = $this->findReportedCost($response);
    $turn->set('video', ['target_id' => $file->id()]);
    $turn->set('duration_ms', (int) round((hrtime(TRUE) - $started_at) / 1_000_000));
    $turn->set('estimated_cost', $cost);
    $turn->set('cost_source', $cost === NULL ? 'unavailable' : 'reported');
    $turn->set('provider_metadata', $response + ['request_id' => $request_id]);
    $turn->set('status', 'completed');
    $turn->save();
    $session->setChangedTime($this->time->getRequestTime());
    $session->save();
    return $turn;
  }

  /**
   * Builds a file_id reference when known, otherwise an inline data URI.
   */
  private function xaiImageReference(FileInterface $file): array {
    $turn_ids = $this->entityTypeManager->getStorage('ai_image_studio_turn')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('image', $file->id())
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    if ($turn_ids !== []) {
      $source_turn = $this->entityTypeManager->getStorage('ai_image_studio_turn')
        ->load(reset($turn_ids));
      $metadata = (array) ($source_turn?->get('provider_metadata')->first()?->getValue() ?? []);
      if (!empty($metadata['file_id'])) {
        return ['file_id' => (string) $metadata['file_id']];
      }
    }
    $binary = file_get_contents($file->getFileUri());
    if ($binary === FALSE) {
      throw new \RuntimeException($this->translate('A reference image could not be read.'));
    }
    return [
      'url' => sprintf(
      'data:%s;base64,%s',
      $file->getMimeType() ?: 'image/png',
      base64_encode($binary),
      ),
    ];
  }

  /**
   * Sends an authenticated request to the xAI REST API.
   */
  private function xaiRequest(
    string $provider_id,
    string $method,
    string $path,
    array $payload = [],
  ): array {
    $provider = $this->providerManager->createInstance($provider_id);
    $key_id = (string) $provider->getConfig()->get('api_key');
    $api_key = $this->keyRepository->getKey($key_id)?->getKeyValue();
    if (!$api_key) {
      throw new \RuntimeException($this->translate('The configured xAI API key could not be loaded.'));
    }
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Accept' => 'application/json',
      ],
      'timeout' => 60,
    ];
    if ($payload !== []) {
      $options['json'] = $payload;
    }
    $response = $this->httpClient->request(
      $method,
      'https://api.x.ai/v1/' . ltrim($path, '/'),
      $options,
    );
    $decoded = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Downloads a temporary provider asset immediately.
   */
  private function downloadProviderAsset(string $url): string {
    if ($url === '' || !str_starts_with($url, 'https://')) {
      throw new \RuntimeException($this->translate('The provider returned an invalid asset URL.'));
    }
    $response = $this->httpClient->request('GET', $url, ['timeout' => 120]);
    $binary = (string) $response->getBody();
    if ($binary === '') {
      throw new \RuntimeException($this->translate('The provider asset was empty.'));
    }
    return $binary;
  }

  /**
   * Creates a sibling turn for an additional normalized provider output.
   */
  private function createVariationTurn(object $turn): object {
    $values = [];
    foreach ([
      'session_id',
      'parent_id',
      'source_file_id',
      'source_file_ids',
      'request_group',
      'prompt',
      'provider_id',
      'model_id',
      'operation',
      'generation_settings',
      'attempt_count',
    ] as $field) {
      if (!$turn->get($field)->isEmpty()) {
        $values[$field] = $turn->get($field)->getValue();
      }
    }
    $values['status'] = 'processing';
    $variation = $this->entityTypeManager
      ->getStorage('ai_image_studio_turn')
      ->create($values);
    $variation->save();
    return $variation;
  }

  /**
   * Publishes a completed turn to the configured Media bundle.
   */
  public function publish(
    object $turn,
    string $name,
    string $alt = '',
    bool $render_badge = FALSE,
  ): MediaInterface {
    $is_video = !$turn->get('video')->isEmpty();
    if ($turn->get('status')->value !== 'completed'
      || ($turn->get('image')->isEmpty() && !$is_video)) {
      throw new \LogicException($this->translate(
        'Only completed Studio turns can be published.',
      ));
    }
    if (!$turn->get('media_id')->isEmpty()) {
      $media = $turn->get('media_id')->entity;
      if ($media instanceof MediaInterface) {
        return $media;
      }
    }

    $settings = $this->configFactory->get('ai_image_studio.settings');
    $bundle = (string) $settings->get(
      $is_video ? 'video_media_bundle' : 'media_bundle',
    );
    $field = (string) $settings->get(
      $is_video ? 'video_media_source_field' : 'media_source_field',
    );
    $file = $turn->get($is_video ? 'video' : 'image')->entity;
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException($this->translate(
        'The generated file is unavailable.',
      ));
    }
    if ($render_badge) {
      $generation_settings = (array) ($turn->get('generation_settings')->first()?->getValue() ?? []);
      $badge_text = trim((string) ($generation_settings['ai_badge_text'] ?? 'AI Image'));
      $file = $is_video
        ? $this->createBadgedVideo($file, $badge_text, (int) $turn->id())
        : $this->createBadgedImage($file, $badge_text, (int) $turn->id());
    }

    $source_value = ['target_id' => $file->id()];
    if (!$is_video) {
      $source_value['alt'] = $alt;
    }
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => $bundle,
      'name' => $name,
      $field => $source_value,
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
      throw new \InvalidArgumentException($this->translate(
        'Select a configured AI provider and model.',
      ));
    }
    return $parts;
  }

  /**
   * Normalizes Studio controls for the selected provider.
   */
  private function normalizeGenerationSettings(
    string $provider_id,
    string $operation,
    string $model_id,
    array $settings,
  ): array {
    if (str_contains($provider_id, 'openai')
      && in_array($operation, ['text_to_image', 'image_to_image'], TRUE)) {
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
        'output_format' => (string) ($settings['file_type'] ?? 'png'),
        'n' => max(1, min(
          $this->getMaxVariations($provider_id . '__' . $model_id),
          (int) ($settings['variations'] ?? 1),
        )),
      ];
    }

    // Do not send unknown configuration keys to providers that have not
    // declared a compatible mapping because some APIs reject them.
    if (!$this->isXaiProvider($provider_id)) {
      return [];
    }

    if (in_array($operation, [
      'text_to_video',
      'image_to_video',
      'reference_to_video',
    ], TRUE)) {
      $normalized = array_filter([
        'duration' => (int) ($settings['duration'] ?? 5),
        'aspect_ratio' => (string) ($settings['aspect_ratio'] ?? ''),
        'resolution' => (string) ($settings['resolution'] ?? ''),
        'prompt' => in_array($operation, ['image_to_video', 'reference_to_video'], TRUE)
          ? (string) ($settings['prompt'] ?? '')
          : '',
      ], static fn (mixed $value): bool => $value !== '');
      if (($normalized['aspect_ratio'] ?? '') === 'auto') {
        unset($normalized['aspect_ratio']);
      }
      if (($normalized['resolution'] ?? '') === '1080p'
        && !str_contains(strtolower($model_id), 'video-1.5')) {
        $normalized['resolution'] = '720p';
      }
      return $normalized;
    }

    $normalized = array_filter([
      'aspect_ratio' => (string) ($settings['aspect_ratio'] ?? ''),
      'resolution' => (string) ($settings['resolution'] ?? ''),
      'quality' => (string) ($settings['quality'] ?? ''),
      'n' => max(1, min(
        $this->getMaxVariations($provider_id . '__' . $model_id),
        (int) ($settings['variations'] ?? 1),
      )),
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
    if (!str_contains(strtolower($model_id), 'image-2.0')) {
      unset($normalized['quality']);
    }

    return $normalized;
  }

  /**
   * Resolves automatic output controls from a source image when possible.
   */
  private function resolveAutomaticGenerationSettings(
    array $settings,
    ?FileInterface $source,
  ): array {
    $ratio = (string) ($settings['aspect_ratio'] ?? 'auto');
    $resolution = (string) ($settings['resolution'] ?? 'auto');
    $dimensions = $source ? @getimagesize($source->getFileUri()) : FALSE;
    $width = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : 0;
    $height = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : 0;

    if ($ratio === 'auto' && $width > 0 && $height > 0) {
      $settings['aspect_ratio'] = $this->closestAspectRatio($width, $height);
    }
    if ($resolution === 'auto') {
      $settings['resolution'] = $width > 0 && $height > 0
        ? $this->closestResolution($width, $height)
        : '1k';
    }
    return $settings;
  }

  /**
   * Finds the closest provider-supported aspect ratio.
   */
  private function closestAspectRatio(int $width, int $height): string {
    $ratios = [
      '1:1' => 1,
      '16:9' => 16 / 9,
      '9:16' => 9 / 16,
      '4:3' => 4 / 3,
      '3:4' => 3 / 4,
      '3:2' => 3 / 2,
      '2:3' => 2 / 3,
      '2:1' => 2,
      '1:2' => 1 / 2,
      '19.5:9' => 19.5 / 9,
      '9:19.5' => 9 / 19.5,
      '20:9' => 20 / 9,
      '9:20' => 9 / 20,
    ];
    $actual = $width / $height;
    $closest = '1:1';
    $smallest_difference = PHP_FLOAT_MAX;
    foreach ($ratios as $label => $candidate) {
      $difference = abs(log($actual / $candidate));
      if ($difference < $smallest_difference) {
        $closest = $label;
        $smallest_difference = $difference;
      }
    }
    return $closest;
  }

  /**
   * Maps source dimensions to the closest supported resolution tier.
   */
  private function closestResolution(int $width, int $height): string {
    return max($width, $height) >= 1536 ? '2k' : '1k';
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
   * Recursively finds a scalar response value suitable for persisted metadata.
   */
  private function findNestedScalarValue(array $data, string $key): mixed {
    if (array_key_exists($key, $data) && is_scalar($data[$key])) {
      return $data[$key];
    }
    foreach ($data as $value) {
      if (is_array($value)) {
        $found = $this->findNestedScalarValue($value, $key);
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
      $this->isXaiProvider($provider_id) => 'grok',
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
   * Identifies xAI/Grok provider plugin IDs without assuming one exact ID.
   */
  private function isXaiProvider(string $provider_id): bool {
    $provider_id = strtolower($provider_id);
    return str_contains($provider_id, 'grok')
      || str_contains($provider_id, 'xai')
      || str_contains($provider_id, 'x_ai');
  }

  /**
   * Writes a normalized image to managed file storage.
   */
  private function saveGeneratedFile(
    ImageFile $image,
    object $session,
    int $turn_id,
    array $generation_settings = [],
  ): FileInterface {
    if (!empty($generation_settings['auto_levels'])) {
      $image = $this->autoLevelImage($image);
    }
    $settings = $this->configFactory->get('ai_image_studio.settings');
    $scheme = (string) ($settings->get('file_scheme') ?: 'private');
    $directory = trim((string) ($settings->get('file_directory') ?: 'ai-image-studio'), '/');
    $destination_directory = sprintf(
      '%s://%s/%s',
      $scheme,
      $directory,
      $this->safeSessionName($session),
    );
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

  /**
   * Expands the generated image's RGB channels to their available tonal range.
   */
  private function autoLevelImage(ImageFile $image): ImageFile {
    if (!$this->canAutoLevels()) {
      throw new \RuntimeException($this->translate(
        'Auto levels requires the PHP Imagick extension.',
      ));
    }

    try {
      $processed = new \Imagick();
      $processed->readImageBlob($image->getBinary());
      $processed->autoLevelImage(
        \Imagick::CHANNEL_RED
        | \Imagick::CHANNEL_GREEN
        | \Imagick::CHANNEL_BLUE,
      );
      $binary = $processed->getImagesBlob();
      $processed->clear();
      $processed->destroy();
    }
    catch (\ImagickException $exception) {
      throw new \RuntimeException($this->translate(
        'The generated image could not be auto-levelled: @message',
        ['@message' => $exception->getMessage()],
      ), 0, $exception);
    }

    if ($binary === '') {
      throw new \RuntimeException($this->translate(
        'The auto-levelled image could not be encoded.',
      ));
    }
    return new ImageFile(
      $binary,
      $image->getMimeType(),
      $image->getFilename(),
    );
  }

  /**
   * Writes a normalized video to managed file storage.
   */
  private function saveGeneratedVideo(
    VideoFile $video,
    object $session,
    int $turn_id,
  ): FileInterface {
    $settings = $this->configFactory->get('ai_image_studio.settings');
    $scheme = (string) ($settings->get('file_scheme') ?: 'private');
    $directory = trim((string) ($settings->get('file_directory') ?: 'ai-image-studio'), '/');
    $destination_directory = sprintf(
      '%s://%s/%s',
      $scheme,
      $directory,
      $this->safeSessionName($session),
    );
    $this->fileSystem->prepareDirectory(
      $destination_directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
    $destination = sprintf('%s/turn-%d.mp4', $destination_directory, $turn_id);
    $file = $this->fileRepository->writeData(
      $video->getBinary(),
      $destination,
      FileExists::Rename,
    );
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Returns a filesystem-safe directory name derived from the session title.
   */
  private function safeSessionName(object $session): string {
    $name = strtolower($this->transliteration->transliterate(
      trim((string) $session->label()),
      'en',
    ));
    $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', $name), '-');
    return mb_substr($name !== '' ? $name : 'session-' . $session->id(), 0, 100);
  }

  /**
   * Creates a managed image derivative with a permanent badge overlay.
   */
  private function createBadgedImage(
    FileInterface $source,
    string $text,
    int $turn_id,
  ): FileInterface {
    if (!function_exists('imagecreatefromstring')) {
      throw new \RuntimeException($this->translate(
        'Rendering a badge into an image requires the PHP GD extension.',
      ));
    }
    $binary = file_get_contents($source->getFileUri());
    $image = $binary === FALSE ? FALSE : @imagecreatefromstring($binary);
    if ($image === FALSE) {
      throw new \RuntimeException($this->translate(
        'The generated image could not be opened for badge rendering.',
      ));
    }
    $badge = $this->createBadgeImage($text);
    $margin = max(12, (int) round(imagesx($image) * 0.015));
    imagealphablending($image, TRUE);
    imagecopy(
      $image,
      $badge,
      max(0, imagesx($image) - imagesx($badge) - $margin),
      max(0, imagesy($image) - imagesy($badge) - $margin),
      0,
      0,
      imagesx($badge),
      imagesy($badge),
    );

    ob_start();
    $mime_type = $source->getMimeType();
    $written = match ($mime_type) {
      'image/jpeg' => imagejpeg($image, NULL, 92),
      'image/webp' => function_exists('imagewebp') && imagewebp($image, NULL, 92),
      'image/gif' => imagegif($image),
      default => imagepng($image, NULL, 6),
    };
    $output = ob_get_clean();
    if (!$written || !is_string($output) || $output === '') {
      throw new \RuntimeException($this->translate(
        'The badged image could not be encoded.',
      ));
    }
    return $this->saveMediaDerivative($source, $output, $turn_id);
  }

  /**
   * Creates a managed video derivative with a permanent badge overlay.
   */
  private function createBadgedVideo(
    FileInterface $source,
    string $text,
    int $turn_id,
  ): FileInterface {
    if (!function_exists('imagecreatetruecolor')) {
      throw new \RuntimeException($this->translate(
        'Rendering a badge into a video requires the PHP GD extension.',
      ));
    }
    $source_path = $this->fileSystem->realpath($source->getFileUri());
    if ($source_path === FALSE) {
      throw new \RuntimeException($this->translate(
        'The generated video could not be opened for badge rendering.',
      ));
    }
    $temporary_directory = $this->fileSystem->getTempDirectory();
    $badge_path = tempnam($temporary_directory, 'ai-image-badge-');
    $output_path = tempnam($temporary_directory, 'ai-image-video-');
    if ($badge_path === FALSE || $output_path === FALSE) {
      throw new \RuntimeException($this->translate(
        'Temporary files for video badge rendering could not be created.',
      ));
    }
    $badge = $this->createBadgeImage($text);
    imagepng($badge, $badge_path, 6);
    try {
      $process = new Process([
        'ffmpeg', '-y', '-i', $source_path, '-i', $badge_path,
        '-filter_complex', 'overlay=W-w-24:H-h-24',
        '-codec:a', 'copy', '-movflags', '+faststart',
        '-f', 'mp4', $output_path,
      ]);
      $process->setTimeout(300);
      $process->run();
      if (!$process->isSuccessful()) {
        throw new \RuntimeException($this->translate(
          'FFmpeg could not render the badge into the video: @message',
          ['@message' => trim($process->getErrorOutput())],
        ));
      }
      $output = file_get_contents($output_path);
      if ($output === FALSE || $output === '') {
        throw new \RuntimeException($this->translate(
          'FFmpeg returned an empty badged video.',
        ));
      }
      return $this->saveMediaDerivative($source, $output, $turn_id);
    }
    finally {
      @unlink($badge_path);
      @unlink($output_path);
    }
  }

  /**
   * Creates a compact transparent PNG badge using GD's bundled font.
   */
  private function createBadgeImage(string $text): \GdImage {
    $text = trim($text) ?: 'AI Image';
    $text = mb_substr($text, 0, 80);
    if (function_exists('iconv')) {
      $converted = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
      $text = $converted === FALSE || $converted === '' ? 'AI Image' : $converted;
    }
    $font = 5;
    $padding_x = 12;
    $padding_y = 8;
    $width = imagefontwidth($font) * strlen($text) + ($padding_x * 2);
    $height = imagefontheight($font) + ($padding_y * 2);
    $badge = imagecreatetruecolor($width, $height);
    imagealphablending($badge, FALSE);
    imagesavealpha($badge, TRUE);
    $transparent = imagecolorallocatealpha($badge, 0, 0, 0, 127);
    imagefill($badge, 0, 0, $transparent);
    imagealphablending($badge, TRUE);
    $background = imagecolorallocatealpha($badge, 0, 0, 0, 35);
    $foreground = imagecolorallocate($badge, 255, 255, 255);
    imagefilledrectangle($badge, 0, 0, $width - 1, $height - 1, $background);
    imagestring($badge, $font, $padding_x, $padding_y, $text, $foreground);
    return $badge;
  }

  /**
   * Saves a permanent Media-only derivative beside the Studio source file.
   */
  private function saveMediaDerivative(
    FileInterface $source,
    string $binary,
    int $turn_id,
  ): FileInterface {
    $source_directory = dirname($source->getFileUri());
    $extension = pathinfo($source->getFilename(), PATHINFO_EXTENSION);
    $destination = sprintf(
      '%s/turn-%d-media-badged.%s',
      $source_directory,
      $turn_id,
      $extension,
    );
    $file = $this->fileRepository->writeData(
      $binary,
      $destination,
      FileExists::Rename,
    );
    $file->setPermanent();
    $file->save();
    return $file;
  }

}
