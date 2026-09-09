<?php

/**
 * @file
 * Standalone, no-API video prompt budgeting regression checks.
 */

require_once __DIR__ . '/../src/Service/VideoPromptBudget.php';

use Drupal\ai_image_studio\Service\VideoPromptBudget;

$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
$core = "AUDIO DIRECTION: Quiet violin.\n\nSHOT ACTION: Mira opens the door.\n\nSPOKEN DIALOGUE / VOICE-OVER: Hello, world!\nSpeak these lines verbatim.\n\nCAMERA MOVEMENT: Slow push in.\n\nVOICE: Warm and low.\n\nSCENE LOCATION OVERRIDES: No flags or lightning.\n\nAnimate this keyframe continuously.";
$long = "PROJECT CONTINUITY: " . str_repeat('The stone walls have weathered details. ', 100) . "\n\nPROJECT CHARACTERS: " . str_repeat('Mira wears a blue coat. ', 100) . "\n\nPINNED LOCATION BIBLE: A stone hall.\n\n" . $core;
$result = VideoPromptBudget::fit($long);
$check(mb_strlen($result) <= 4096, 'Oversized storyboard fits provider limit');
$check(str_contains($result, $core), 'Action, dialogue, audio, camera, voice and overrides remain verbatim');
$check(str_contains($result, 'PROJECT CHARACTERS:') && str_contains($result, 'PINNED LOCATION BIBLE:'), 'Context budget is shared across sections');
$check(VideoPromptBudget::fit($core) === $core, 'Short prompts remain byte-for-byte unchanged');
$check(VideoPromptBudget::fit(str_repeat('界', 4096)) === str_repeat('界', 4096), 'Exactly 4096 Unicode characters are accepted');
$unicode = VideoPromptBudget::fit("PROJECT CONTINUITY: " . str_repeat('静かな場所。 ', 1500) . "\n\n" . $core);
$check(mb_check_encoding($unicode, 'UTF-8') && mb_strlen($unicode) <= 4096, 'Multibyte context stays valid UTF-8');
try {
  VideoPromptBudget::fit('SPOKEN DIALOGUE / VOICE-OVER: ' . str_repeat('x', 5000));
  $check(FALSE, 'Oversized dialogue must not be truncated');
}
catch (LengthException $exception) {
  $check(str_contains($exception->getMessage(), 'No API request'), 'Oversized essential direction fails locally with actionable guidance');
}
$result = VideoPromptBudget::fit("PROJECT CONTINUITY: " . str_repeat('x', 5000) . "\n\n" . $core);
$check($result === $core, 'An oversized descriptive sentence is omitted, never sliced');
$check(VideoPromptBudget::fit($long) === VideoPromptBudget::fit($long), 'Retries produce a deterministic effective prompt');
