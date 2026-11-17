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
      'elevenlabs' => $this->getElevenLabsVoiceOptions($modelOption),
      default => [],
    };
  }

  /**
   * Loads the authenticated ElevenLabs voice catalogue for the chosen model.
   *
   * ElevenLabs expects its provider setting in the form "Name :: voice_id";
   * preserving that value lets its Drupal provider extract the API voice ID.
   */
  private function getElevenLabsVoiceOptions(string $modelOption): array {
    try {
      if (!\Drupal::hasService('elevenlabs.api')) {
        return [];
      }
      $voices = \Drupal::service('elevenlabs.api')->getVoices()['voices'] ?? [];
      $options = [];
      foreach ($voices as $voice) {
        if (!empty($voice['name']) && !empty($voice['voice_id'])) {
          $value = $voice['name'] . ' :: ' . $voice['voice_id'];
          $options[$value] = $value;
        }
      }
      natcasesort($options);
      return $options;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not load ElevenLabs voices: @message', ['@message' => $exception->getMessage()]);
      return [];
    }
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
      'elevenlabs' => 5000,
      default => NULL,
    };
  }

  /**
   * Synthesizes text and saves the first returned audio stream as a file.
   */
  public function generate(string $text, string $modelOption, string $voice = '', string $format = '', array $parameters = []): FileInterface {
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
        // The ElevenLabs Drupal provider reads these from its configuration,
        // rather than from the model argument supplied to textToSpeech().
        'elevenlabs' => $voice !== '' ? ['model' => $modelId, 'voice' => $voice] : [],
        default => [],
      };
      if ($providerId === 'elevenlabs' && $voice === '') {
        throw new \InvalidArgumentException('Select an ElevenLabs voice before generating narration.');
      }
      if ($voice !== '' && !isset($this->getVoiceOptions($modelOption)[$voice])) {
        throw new \InvalidArgumentException('The selected voice is not available for this provider.');
      }
      if ($format !== '' && !isset($this->getFormatOptions($modelOption)[$format])) {
        throw new \InvalidArgumentException('The selected audio format is not available for this provider.');
      }
      if ($providerId === 'openai' && $format !== '') {
        $configuration['response_format'] = $format;
      }
      $configuration += $this->validateParameters($providerId, $parameters);
      $provider = $this->providerManager->createInstance($providerId, $configuration);
      // Drupal AI provider plugins keep request-level settings separately from
      // their construction options. Configure the instance explicitly so
      // providers such as ElevenLabs receive the selected model and voice.
      $provider->setConfiguration($configuration);
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
   * Validates and maps request-level controls to provider configuration.
   */
  private function validateParameters(string $providerId, array $parameters): array {
    return match ($providerId) {
      'openai' => $this->openAiParameters($parameters),
      'gemini' => ['languageCode' => trim((string) ($parameters['language'] ?? ''))],
      'grok' => $this->grokParameters($parameters),
      'elevenlabs' => $this->elevenLabsParameters($parameters),
      default => [],
    };
  }

  private function openAiParameters(array $parameters): array {
    $speed = (float) ($parameters['speed'] ?? 1);
    if ($speed < 0.25 || $speed > 4) {
      throw new \InvalidArgumentException('OpenAI speech speed must be between 0.25 and 4.');
    }
    return ['speed' => $speed];
  }

  private function grokParameters(array $parameters): array {
    $speed = (float) ($parameters['speed'] ?? 1);
    if ($speed < 0.7 || $speed > 1.5) {
      throw new \InvalidArgumentException('Grok speech speed must be between 0.7 and 1.5.');
    }
    return [
      'language' => trim((string) ($parameters['language'] ?? 'auto')) ?: 'auto',
      'speed' => $speed,
      'text_normalization' => !empty($parameters['text_normalization']),
    ];
  }

  /**
   * Maps controls supported by the installed ElevenLabs Drupal provider.
   */
  private function elevenLabsParameters(array $parameters): array {
    $settings = [];
    foreach (['speed', 'stability', 'similarity_boost', 'style'] as $name) {
      if (($parameters[$name] ?? NULL) !== NULL && ($parameters[$name] ?? '') !== '') {
        $settings[$name] = (float) $parameters[$name];
      }
    }
    if (($settings['speed'] ?? 1) < 0.7 || ($settings['speed'] ?? 1) > 1.2) {
      throw new \InvalidArgumentException('ElevenLabs speech speed must be between 0.7 and 1.2.');
    }
    foreach (['stability', 'similarity_boost', 'style'] as $name) {
      if (isset($settings[$name]) && ($settings[$name] < 0 || $settings[$name] > 1)) {
        throw new \InvalidArgumentException("ElevenLabs $name must be between 0 and 1.");
      }
    }
    foreach (['use_speaker_boost', 'apply_language_text_normalization', 'use_pvc_as_ivc'] as $name) {
      if (array_key_exists($name, $parameters)) {
        $settings[$name] = (bool) $parameters[$name];
      }
    }
    foreach (['language_code', 'apply_text_normalization', 'previous_text', 'next_text'] as $name) {
      if (($value = trim((string) ($parameters[$name] ?? ''))) !== '') {
        $settings[$name] = $value;
      }
    }
    if (isset($settings['apply_text_normalization']) && !in_array($settings['apply_text_normalization'], ['auto', 'on', 'off'], TRUE)) {
      throw new \InvalidArgumentException('ElevenLabs text normalisation must be auto, on, or off.');
    }
    if (($parameters['seed'] ?? '') !== '') {
      $seed = (int) $parameters['seed'];
      if ($seed < 0 || $seed > 4294967295) {
        throw new \InvalidArgumentException('ElevenLabs seed must be between 0 and 4294967295.');
      }
      $settings['seed'] = $seed;
    }
    foreach (['pronunciation_dictionary_locators', 'previous_request_ids', 'next_request_ids'] as $name) {
      if (!empty($parameters[$name])) {
        $settings[$name] = $parameters[$name];
      }
    }
    return $settings;
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
