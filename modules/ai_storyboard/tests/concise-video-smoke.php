<?php

/**
 * @file
 * Rollback-only sequence prompt checks, without provider calls.
 */

$m = \Drupal::entityTypeManager();
$transaction = \Drupal::database()->startTransaction();
try {
  $board = $m->getStorage('ai_storyboard')->create(['title' => 'Concise fixture', 'uid' => 1, 'continuity_bible' => str_repeat('PROJECT-BIBLE-ONLY ', 500), 'character_bible' => 'CHARACTER-BIBLE-ONLY', 'audio_prompt' => 'Quiet rain.']);
  $board->save();
  $scene = $m->getStorage('ai_storyboard_scene')->create(['title' => 'Hall', 'storyboard_id' => $board->id(), 'conditions' => 'Night', 'overrides' => 'No lightning.']);
  $scene->save();
  $shot = $m->getStorage('ai_storyboard_shot')->create(['title' => 'Arrival', 'storyboard_id' => $board->id(), 'scene_id' => $scene->id(), 'action' => 'Mira opens the door.', 'dialogue' => 'Mira: Hello, 世界!', 'audio' => 'Footsteps.', 'camera_move' => 'Slow push in']);
  $shot->save();
  $before = [$board->toArray(), $scene->toArray(), $shot->toArray()];
  foreach (['animate', 'bridge'] as $mode) {
    $prompt = \Drupal::service('ai_storyboard.bulk_manager')->videoPrompt($board, $shot, ['mode' => $mode, 'prompt' => 'Move slowly.']);
    foreach (['Move slowly.', 'Mira opens the door.', 'Mira: Hello, 世界!', 'Quiet rain.', 'Footsteps.', 'Slow push in', 'No lightning.'] as $required) {
      if (!str_contains($prompt, $required)) {
        throw new RuntimeException('Essential sequence direction missing');
      }
    }
    if (str_contains($prompt, 'BIBLE-ONLY') || strlen($prompt) > 2000) {
      throw new RuntimeException('Full bibles leaked into concise prompt');
    }
    if (($mode === 'bridge') !== str_contains($prompt, 'Transition continuously')) {
      throw new RuntimeException('Incorrect sequence mode');
    }
    print "PASS: $mode preserves direction and omits full bibles.\n";
  }
  if ($before !== [$board->toArray(), $scene->toArray(), $shot->toArray()]) {
    throw new RuntimeException('Prompt composition changed source entities');
  }
  print "PASS: Source entities unchanged; no provider calls.\n";
}
finally {
  $transaction->rollBack();
  foreach (['ai_storyboard', 'ai_storyboard_scene', 'ai_storyboard_shot'] as $type) {
    $m->getStorage($type)->resetCache();
    \Drupal::service('cache_tags.invalidator')->invalidateTags($m->getDefinition($type)->getListCacheTags());
  }
}
