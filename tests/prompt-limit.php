<?php

/**
 * @file
 * Standalone checks for configured/model-specific submission limits.
 */

require_once __DIR__ . '/../src/Service/PromptLimit.php';
require_once __DIR__ . '/../src/Service/VideoPromptBudget.php';

use Drupal\ai_image_studio\Service\PromptLimit;

$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
foreach (['text_to_video', 'image_to_video', 'reference_to_video'] as $operation) {
  $check(PromptLimit::resolve(10000, TRUE, 'grok-imagine-video', $operation) === 4096, "$operation uses the confirmed model cap");
  $check(PromptLimit::resolve(2000, TRUE, 'grok-imagine-video', $operation) === 2000, "$operation honors a lower configured ceiling");
}
foreach (['grok-imagine-video-1.5', 'grok-imagine-video-future', 'another-model'] as $model) {
  $limit = PromptLimit::resolve(10000, TRUE, $model, 'image_to_video');
  $prompt = str_repeat('界', 9000);
  $check($limit === 10000 && PromptLimit::prepare($prompt, $limit, 'image_to_video') === $prompt, "$model retains larger prompts without an inferred model cap");
}
$check(PromptLimit::resolve(100000, TRUE, 'grok-imagine-image', 'text_to_image') === 100000, 'Large configured image limits are honored');
$check(PromptLimit::resolve(10000, FALSE, 'grok-imagine-video', 'image_to_video') === 10000, 'Another provider does not inherit the xAI cap');
$check(PromptLimit::resolve(10000, TRUE, 'grok-imagine-video', 'text_to_image') === 10000, 'An unrelated operation does not inherit the video cap');
$check(PromptLimit::resolve(0, FALSE, 'model', 'text_to_image') === 4000, 'Unset configuration retains the existing default');
foreach (['text_to_image', 'image_to_image'] as $operation) {
  try {
    PromptLimit::prepare(str_repeat('x', 1001), 1000, $operation);
    $check(FALSE, 'Oversized image prompt must be rejected');
  }
  catch (LengthException $exception) {
    $check(str_contains($exception->getMessage(), 'No API request'), "$operation fails locally above the configured limit");
  }
}
$prompt = 'PROJECT CONTINUITY: ' . str_repeat('Stone walls. ', 500) . "\n\nSHOT ACTION: Walk.\n\nSPOKEN DIALOGUE / VOICE-OVER: Hello.";
$result = PromptLimit::prepare($prompt, 1000, 'image_to_video');
$check(mb_strlen($result) <= 1000 && str_contains($result, 'VOICE-OVER: Hello.'), 'Video budgeting uses the configured ceiling and preserves dialogue');
