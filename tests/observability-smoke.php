<?php

/**
 * @file
 * In-memory observability regression checks; run with drush php:script.
 */

use Drupal\ai_image_studio\Service\GenerationDiagnostics;
use Drupal\ai_image_studio\Service\GenerationObservability;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
$sink = new class extends AbstractLogger {
  public array $records = [];
  public bool $fail = FALSE;

  public function log($level, Stringable|string $message, array $context = []): void {
    if ($this->fail) {
      throw new RuntimeException('Unavailable logger');
    }
    $this->records[] = $context;
  }
};
$factory = new class($sink) implements LoggerChannelFactoryInterface {
  public function __construct(public LoggerInterface $sink) {}

  public function get($channel) {
    if ($channel !== 'ai_observability') {
      throw new RuntimeException('Wrong channel');
    }
    return $this->sink;
  }

  public function addLogger(LoggerInterface $logger, $priority = 0) {}
};
$storage = new MemoryStorage();
$settings = ['logging_enabled' => TRUE, 'log_input' => FALSE, 'log_output' => FALSE, 'log_tags' => []];
$storage->write('ai_observability.settings', $settings);
$config = new ConfigFactory($storage, \Drupal::service('event_dispatcher'), \Drupal::service('config.typed'));
$reporter = new GenerationObservability($config, \Drupal::moduleHandler(), $factory);
$check(\Drupal::moduleHandler()->moduleExists('ai_observability'), 'AI Observability is installed for integration checks');
$turn = \Drupal::entityTypeManager()->getStorage('ai_image_studio_turn')->create([
  'session_id' => 24, 'prompt' => 'Private prompt', 'status' => 'queued',
  'provider_id' => 'grok', 'model_id' => 'grok-imagine-video',
  'operation' => 'reference_to_video', 'generation_settings' => ['prompt' => 'Private configuration', 'duration' => 5],
]);
$reporter->turnSaved($turn);
$check(count($sink->records) === 1, 'Custom reference requests emit lifecycle logs');
$check(!str_contains(json_encode($sink->records), 'Private'), 'Input-disabled logging excludes prompt and configuration prompt');
$turn->setOriginal(clone $turn);
$reporter->turnSaved($turn);
$check(count($sink->records) === 1, 'Unchanged saves and repeated pending polls are suppressed');
$turn->set('status', 'processing');
$reporter->turnSaved($turn);
$check(count($sink->records) === 2, 'Processing transition is reported');
$turn->setOriginal(clone $turn);
$turn->set('provider_metadata', ['progress' => 50, 'url' => 'https://example.com/private?token=secret']);
$reporter->turnSaved($turn);
$check(count($sink->records) === 3 && !str_contains(json_encode($sink->records), 'example.com'), 'Changed progress is reported without raw provider URLs');
$response = new Response(400, [], json_encode(['error' => ['message' => 'Prompt too long; Bearer secretvalue https://example.com/?token=secret', 'code' => 'invalid_prompt']]));
$wrapped = new RuntimeException('xAI video API returned HTTP 400.', 0, new RequestException('Bad request', new Request('POST', 'https://api.x.ai/v1/videos/generations'), $response));
$diagnostic = GenerationDiagnostics::fromException($wrapped);
$check($diagnostic['http_status'] === 400 && $diagnostic['provider_code'] === 'invalid_prompt' && str_contains($diagnostic['message'], 'Prompt too long'), 'Wrapped provider validation details are recovered');
$check(!str_contains($diagnostic['message'], 'secretvalue') && !str_contains($diagnostic['message'], 'example.com'), 'Credentials and signed URLs are redacted');
$check($response->getBody()->tell() === 0, 'Diagnostic extraction preserves response stream position');
$turn->set('status', 'failed')->set('error_message', $diagnostic['message'])->set('provider_metadata', $diagnostic);
$reporter->turnSaved($turn);
$last = end($sink->records)['metadata'];
$check($last['provider_metadata']['http_status'] === 400 && !isset($last['error_message']), 'Failures expose status but gate provider prose behind input logging');
$settings['log_input'] = TRUE;
$settings['log_output'] = TRUE;
$storage->write('ai_observability.settings', $settings);
$config->reset();
$turn->set('status', 'completed')->set('video', ['target_id' => 123]);
$reporter->turnSaved($turn);
$last = end($sink->records)['metadata'];
$check($last['input'] === 'Private prompt' && (int) $last['video_file_id'] === 123, 'Input/output opt-ins expose text and file IDs, not binaries');
$count = count($sink->records);
$settings['log_tags'] = ['unrelated'];
$storage->write('ai_observability.settings', $settings);
$config->reset();
$reporter->turnSaved($turn);
$check(count($sink->records) === $count, 'Observability tag filtering is respected');
$settings['log_tags'] = [];
$settings['logging_enabled'] = FALSE;
$storage->write('ai_observability.settings', $settings);
$config->reset();
$reporter->turnSaved($turn);
$check(count($sink->records) === $count, 'Logging disabled means no lifecycle records');
$settings['logging_enabled'] = TRUE;
$storage->write('ai_observability.settings', $settings);
$config->reset();
$sink->fail = TRUE;
$reporter->turnSaved($turn);
$check(TRUE, 'Logger failure does not interrupt generation');
print "Observability checks passed; no entities saved or provider requests made.\n";
