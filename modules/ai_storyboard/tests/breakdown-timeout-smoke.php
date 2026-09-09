<?php

/**
 * @file
 * Verifies Storyboard transport timeouts using a fake HTTP response.
 *
 * Run with drush php:script on a development site with Grok configured.
 * All newly constructed provider HTTP clients use an in-memory handler.
 * No generation requests or configuration writes are made.
 */

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Http\ClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;

$container = \Drupal::getContainer();
$original_factory = $container->get('http_client_factory');
$global_timeout = \Drupal::config('ai.settings')->get('request_timeout');
$factory = new class extends ClientFactory {
  public array $requests = [];
  public bool $emptyResult = FALSE;

  public function __construct() {}

  public function fromOptions(array $config = []) {
    $config['handler'] = function ($request, array $options) {
      $this->requests[] = [
        'timeout' => $options['timeout'],
        'connect_timeout' => $options['connect_timeout'] ?? NULL,
        'payload' => json_decode((string) $request->getBody(), TRUE),
      ];
      return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'chatcmpl-storyboard-test',
        'object' => 'chat.completion',
        'created' => 1,
        'model' => 'grok-4.6',
        'choices' => [[
          'index' => 0,
          'finish_reason' => 'stop',
          'message' => [
            'role' => 'assistant',
            'content' => json_encode([
              'continuity_bible' => $this->emptyResult ? '' : 'Stone hall.',
              'character_bible' => $this->emptyResult ? '' : 'Mira wears blue.',
              'scenes' => [],
              'shots' => $this->emptyResult ? [] : [['title' => 'Test shot']],
            ]),
          ],
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
      ])));
    };
    return new Client($config);
  }
};
$check = static function (bool $passed, string $message): void {
  if (!$passed) {
    throw new \RuntimeException($message);
  }
  print 'PASS: ' . $message . "\n";
};
$container->set('http_client_factory', $factory);
try {
  $service = $container->get('ai_storyboard.breakdown');
  $expected = $service->getRequestTimeout();
  $result = $service->breakdown('INT. HALL — Mira enters.', 'grok__grok-4.6');
  $request = end($factory->requests);
  $check($request && $request['timeout'] === $expected, 'Storyboard timeout reaches the actual Grok chat transport');
  $check($request['connect_timeout'] === 30, 'Connection timeout remains bounded');
  $check(!isset($request['payload']['http_client_options']) && !isset($request['payload']['timeout']), 'Transport settings do not leak into the API payload');
  $check($result['continuity_bible'] === 'Stone hall.' && count($result['shots']) === 1, 'Structured breakdown response is still parsed');
  $override = new class implements ConfigFactoryOverrideInterface {
    public int $timeout = 120;

    public function loadOverrides($names) {
      return in_array('ai_storyboard.settings', $names, TRUE) ? ['ai_storyboard.settings' => ['breakdown_timeout' => $this->timeout]] : [];
    }

    public function getCacheSuffix() {
      return 'storyboard_timeout_smoke';
    }

    public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
      return NULL;
    }

    public function getCacheableMetadata($name) {
      return new CacheableMetadata();
    }
  };
  $config_factory = $container->get('config.factory');
  $config_factory->addOverride($override);
  $config_factory->reset('ai_storyboard.settings');
  $service->breakdown('INT. HALL — Mira enters.', 'grok__grok-4.6');
  $request = end($factory->requests);
  $check($request['timeout'] === 120, 'Configured timeout replaces the default on subsequent breakdowns');
  foreach ([1 => 30, 999 => 480] as $value => $bounded) {
    $override->timeout = $value;
    $config_factory->reset('ai_storyboard.settings');
    $check($service->getRequestTimeout() === $bounded, 'Timeout is bounded to ' . $bounded . ' seconds');
  }
  $ordinary = $container->get('ai.provider')->loadProviderFromSimpleOption('grok__grok-4.6');
  $ordinary->chat(new \Drupal\ai\OperationType\Chat\ChatInput([new \Drupal\ai\OperationType\Chat\ChatMessage('user', 'Test only')]), 'grok-4.6');
  $request = end($factory->requests);
  $check($request['timeout'] === (int) ($global_timeout ?: 60), 'Ordinary AI calls keep their global timeout');
  $check(\Drupal::config('ai.settings')->get('request_timeout') === $global_timeout, 'Global AI configuration is unchanged');
  $factory->emptyResult = TRUE;
  $result = $service->breakdown('Unchanged script', 'grok__grok-4.6', '', 'Keep this location bible.', 'Keep this character bible.', ['shots' => [['id' => 42, 'title' => 'Existing shot']]]);
  $check($result['shots'] === [] && $result['continuity_bible'] === 'Keep this location bible.' && $result['character_bible'] === 'Keep this character bible.', 'No-change responses preserve nonblank existing bibles');
  $request = end($factory->requests);
  $check(str_contains(json_encode($request['payload']), 'EXISTING PRODUCTION RECORDS') && str_contains(json_encode($request['payload']), 'Existing shot'), 'Provider receives current record identities and content');
  try {
    $service->breakdown('Brand new script', 'grok__grok-4.6');
    $check(FALSE, 'Empty first breakdown must fail');
  }
  catch (\UnexpectedValueException $exception) {
    $check(TRUE, 'Empty initial breakdown still fails safely');
  }
}
finally {
  $container->set('http_client_factory', $original_factory);
}
