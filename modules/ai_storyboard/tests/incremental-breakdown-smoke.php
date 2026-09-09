<?php

/**
 * @file
 * Rollback-only merge regression test; run with drush php:script.
 */

$manager = \Drupal::entityTypeManager();
$transaction = \Drupal::database()->startTransaction();
$tags = [];
$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new \RuntimeException($message);
  }
  print "PASS: $message\n";
};
$create = static function (string $type, array $values) use ($manager, &$tags): object {
  $entity = $manager->getStorage($type)->create($values);
  $entity->save();
  $tags = array_merge($tags, $entity->getCacheTags(), $entity->getEntityType()->getListCacheTags());
  return $entity;
};
try {
  $board = $create('ai_storyboard', ['title' => 'Incremental fixture', 'uid' => 1, 'continuity_bible' => 'Stone hall.', 'character_bible' => 'Mira wears blue.']);
  $scene = $create('ai_storyboard_scene', ['storyboard_id' => $board->id(), 'scene_number' => 1, 'title' => 'Arrival', 'action' => 'Mira enters.', 'conditions' => 'Night']);
  $session = $create('ai_image_studio_session', ['title' => 'Merge fixture', 'uid' => 1]);
  $turn = $create('ai_image_studio_turn', ['session_id' => $session->id(), 'prompt' => 'Fixture only', 'status' => 'completed']);
  $shot = $create('ai_storyboard_shot', ['storyboard_id' => $board->id(), 'scene_id' => $scene->id(), 'position' => 1, 'shot_number' => 1, 'title' => 'Entrance', 'action' => 'Mira enters.', 'dialogue' => 'Hello?', 'audio' => 'Footsteps', 'image_prompt' => 'Blue coat.', 'duration' => 3, 'status' => 'approved', 'studio_turn_id' => $turn->id(), 'video_turn_id' => $turn->id()]);
  $omitted = $create('ai_storyboard_shot', ['storyboard_id' => $board->id(), 'scene_id' => $scene->id(), 'position' => 2, 'shot_number' => 2, 'title' => 'Reaction', 'status' => 'approved']);
  $merger = \Drupal::service('ai_storyboard.breakdown_merger');
  $before = $merger->context((int) $board->id());
  $stats = $merger->merge($board, [
    'continuity_bible' => ' ', 'character_bible' => NULL,
    'scenes' => [['existing_scene_id' => (int) $scene->id(), 'scene_number' => 1, 'title' => '', 'conditions' => ' ', 'action' => '']],
    'shots' => [['existing_shot_id' => (int) $shot->id(), 'title' => '', 'action' => ' ', 'dialogue' => NULL, 'audio' => '', 'image_prompt' => '', 'duration' => 0]],
  ]);
  $check($before === $merger->context((int) $board->id()), 'Blank values leave all scene/shot content, IDs and approvals unchanged');
  $check($stats === ['created' => 0, 'updated' => 0, 'unchanged' => 1, 'retained' => 1], 'Unchanged and omitted records are counted correctly');
  $check($board->get('continuity_bible')->value === 'Stone hall.' && $board->get('character_bible')->value === 'Mira wears blue.', 'Blank bibles preserve existing bibles');
  $stats = $merger->merge($board, ['shots' => [
    ['existing_shot_id' => (int) $shot->id(), 'title' => 'Entrance', 'action' => 'Mira enters.', 'duration' => 3.0],
    ['existing_shot_id' => 0, 'existing_scene_id' => (int) $scene->id(), 'title' => 'New beat', 'action' => 'Door opens.', 'position' => 3],
  ]]);
  $check($stats['created'] === 1 && $stats['updated'] === 0 && $stats['unchanged'] === 1, 'Appending creates only the new shot');
  $storage = $manager->getStorage('ai_storyboard_shot');
  $saved = $storage->loadUnchanged($shot->id());
  $check($saved->get('status')->value === 'approved' && $saved->get('video_turn_id')->target_id == $turn->id(), 'Unchanged shots retain approval and media');
  $stats = $merger->merge($board, ['shots' => [['existing_shot_id' => (int) $shot->id(), 'action' => 'Mira runs inside.', 'dialogue' => ' ']]]);
  $saved = $storage->loadUnchanged($shot->id());
  $check($stats['updated'] === 1 && $saved->get('action')->value === 'Mira runs inside.', 'Changed shot is updated in place');
  $check($saved->get('dialogue')->value === 'Hello?' && $saved->get('audio')->value === 'Footsteps', 'Blank and omitted fields survive partial updates');
  $check($saved->get('status')->value === 'draft' && $saved->get('studio_turn_id')->target_id == $turn->id() && $saved->get('video_turn_id')->target_id == $turn->id(), 'Changed shots keep media and return to draft');
  $check($storage->loadUnchanged($omitted->id())->get('status')->value === 'approved', 'Omitted shots are not deleted or modified');
  $stats = $merger->merge($board, ['shots' => [['title' => 'Entrance', 'action' => 'Mira runs inside.']]]);
  $check($stats['created'] === 0 && $stats['unchanged'] === 1, 'Legacy responses match a unique title');
  $before = $merger->context((int) $board->id());
  try {
    $merger->merge($board, ['shots' => [['existing_shot_id' => (int) $shot->id(), 'action' => 'Must roll back'], ['existing_shot_id' => PHP_INT_MAX, 'title' => 'Invalid']]]);
    $check(FALSE, 'Unknown IDs must fail');
  }
  catch (\UnexpectedValueException $exception) {
    $check($before === $merger->context((int) $board->id()), 'Invalid IDs roll back the whole merge');
  }
  $saved = $storage->loadUnchanged($shot->id());
  $saved->set('dialogue', 'Edited during request')->save();
  $check($before !== $merger->context((int) $board->id()), 'Concurrent child edits are detectable');
  $stats = $merger->merge($board, ['scenes' => [['existing_scene_id' => (int) $scene->id(), 'scene_number' => 1, 'conditions' => 'Morning']]]);
  $check($manager->getStorage('ai_storyboard_scene')->loadUnchanged($scene->id())->get('conditions')->value === 'Morning', 'Changed scene fields update in place');
  $check($storage->loadUnchanged($omitted->id())->get('status')->value === 'draft', 'Scene changes mark affected existing shots for review');
}
finally {
  $transaction->rollBack();
  \Drupal\Core\Cache\Cache::invalidateTags(array_unique($tags));
  foreach (['ai_storyboard', 'ai_storyboard_scene', 'ai_storyboard_shot', 'ai_image_studio_session', 'ai_image_studio_turn'] as $type) {
    $manager->getStorage($type)->resetCache();
  }
}
