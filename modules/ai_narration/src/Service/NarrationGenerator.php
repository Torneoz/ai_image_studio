<?php

declare(strict_types=1);

namespace Drupal\ai_narration\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechInput;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates managed audio files from provider-neutral TTS responses.
 */
final class NarrationGenerator {

  public function __construct(
    private readonly AiProviderPluginManager $providerManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gets available provider/voice selections for the narration form.
   *
   * @return array<string, string>
   *   Options keyed as provider__model.
   */
  public function getModelOptions(): array {
    return $this->providerManager->getSimpleProviderModelOptions('text_to_speech', FALSE);
  }

  /**
   * Gets voices supported by providers whose voice is request-configurable.
   *
   * @return array<string, string>
   *   Voice IDs and labels.
   */
  public function getVoiceOptions(string $modelOption): array {
    $provider = explode('__', $modelOption, 2)[0] ?? '';
    return match ($provider) {
      'openai' => array_combine(
        ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse'],
        ['Alloy', 'Ash', 'Ballad', 'Coral', 'Echo', 'Fable', 'Nova', 'Onyx', 'Sage', 'Shimmer', 'Verse'],
      ),
      'gemini' => array_combine(
        ['Kore', 'Puck', 'Charon', 'Fenrir', 'Aoede', 'Leda', 'Orus', 'Zephyr'],
        ['Kore', 'Puck', 'Charon', 'Fenrir', 'Aoede', 'Leda', 'Orus', 'Zephyr'],
      ),
      default => [],
    };
  }

  /**
   * Gets output formats that the selected provider can return faithfully.
   *
   * @return array<string, string>
   *   Format IDs and labels.
   */
  public function getFormatOptions(string $modelOption): array {
    $provider = explode('__', $modelOption, 2)[0] ?? '';
    return match ($provider) {
      'openai' => ['mp3' => 'MP3', 'opus' => 'Opus', 'aac' => 'AAC', 'flac' => 'FLAC', 'wav' => 'WAV', 'pcm' => 'PCM'],
      'gemini' => ['wav' => 'WAV'],
      default => [],
    };
  }

  /**
   * Returns the maximum safe text request size for the selected model.
   *
   * OpenAI publishes a 4,096-character input limit for its TTS models.
   * Gemini exposes token-based limits rather than a stable character limit,
   * so its value is the application's conservative request safeguard.
   */
  public function getInputCharacterLimit(string $modelOption): ?int {
    $provider = explode('__', $modelOption, 2)[0] ?? '';
    return match ($provider) {
      'openai' => 4096,
      'gemini' => 10000,
      default => NULL,
    };
  }

  /**
   * Synthesizes text and saves the first returned audio stream as a file.
   */
  public function generate(string $text, string $modelOption, string $voice = '', string $format = ''): FileInterface {
    $text = trim($text);
    if ($text === '') {
      throw new \InvalidArgumentException('Narration text cannot be empty.');
    }
    [$providerId, $modelId] = $this->parseModelOption($modelOption);
    if (!array_key_exists($modelOption, $this->getModelOptions())) {
      throw new \InvalidArgumentException('The selected text-to-speech voice is no longer available.');
    }

    try {
      $configuration = match ($providerId) {
        'openai' => $voice !== '' ? ['voice' => $voice] : [],
        'gemini' => $voice !== '' ? ['voiceName' => $voice] : [],
        default => [],
      };
      if ($voice !== '' && !isset($this->getVoiceOptions($modelOption)[$voice])) {
        throw new \InvalidArgumentException('The selected voice is not available for this provider.');
      }
      if ($format !== '' && !isset($this->getFormatOptions($modelOption)[$format])) {
        throw new \InvalidArgumentException('The selected audio format is not available for this provider.');
      }
      if ($providerId === 'openai' && $format !== '') {
        $configuration['response_format'] = $format;
      }
      $provider = $this->providerManager->createInstance($providerId, $configuration);
      if (!$provider->isUsable()) {
        throw new \RuntimeException('The selected text-to-speech provider is not available.');
      }
      $audio = $provider->textToSpeech(new TextToSpeechInput($text), $modelId, ['ai_narration'])->getNormalized()[0] ?? NULL;
      if ($audio === NULL || $audio->getBinary() === '') {
        throw new \UnexpectedValueException('The text-to-speech provider returned no audio.');
      }
      [$mimeType, $filename] = $format === '' ? [$audio->getMimeType(), $audio->getFilename()] : $this->formatFileMetadata($format);
      return $this->saveAudio($audio->getBinary(), $mimeType, $filename);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Narration generation failed: @message', ['@message' => $exception->getMessage()]);
      throw new \RuntimeException('Narration could not be generated. ' . $exception->getMessage(), 0, $exception);
    }
  }

  /**
   * Validates and splits Drupal AI's provider__model option format.
   *
   * @return array{string, string}
   *   Provider ID and model ID.
   */
  private function parseModelOption(string $modelOption): array {
    $parts = explode('__', $modelOption, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw new \InvalidArgumentException('Select an available text-to-speech voice.');
    }
    return $parts;
  }

  /**
   * Persists generated bytes as permanent, managed public audio.
   */
  private function saveAudio(string $binary, string $mimeType, string $providerFilename): FileInterface {
    $directory = 'public://ai-narration';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('The narration audio directory could not be created.');
    }
    $extension = match ($mimeType) {
      'audio/mpeg' => 'mp3',
      'audio/wav', 'audio/x-wav' => 'wav',
      'audio/ogg' => 'ogg',
      'audio/mp4', 'audio/aac' => 'm4a',
      default => pathinfo($providerFilename, PATHINFO_EXTENSION) ?: 'audio',
    };
    $uri = sprintf('%s/narration-%s.%s', $directory, date('Ymd-His'), $extension);
    $file = $this->fileRepository->writeData($binary, $uri, FileExists::Rename);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Returns accurate persisted metadata for a requested output format.
   *
   * @return array{string, string}
   *   MIME type and filename.
   */
  private function formatFileMetadata(string $format): array {
    return match ($format) {
      'opus' => ['audio/ogg', 'narration.opus'],
      'aac' => ['audio/aac', 'narration.aac'],
      'flac' => ['audio/flac', 'narration.flac'],
      'wav' => ['audio/wav', 'narration.wav'],
      'pcm' => ['audio/L16', 'narration.pcm'],
      default => ['audio/mpeg', 'narration.mp3'],
    };
  }

}
