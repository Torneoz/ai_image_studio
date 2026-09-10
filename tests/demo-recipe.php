<?php

/**
 * @file
 * Integration check for an isolated site named AIIS-demo-test.
 *
 * Run with drush php:script after applying the storyboard demo recipe.
 */

use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;

if (\Drupal::config('system.site')->get('name') !== 'AIIS-demo-test') {
  throw new RuntimeException('Run only on a disposable site named AIIS-demo-test.');
}
$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
$manager = \Drupal::entityTypeManager();
$snapshot = static function () use ($manager): array {
  $result = [];
  foreach (['ai_image_studio_session', 'ai_storyboard', 'ai_storyboard_scene', 'ai_storyboard_shot', 'ai_image_studio_turn'] as $type) {
    $result[$type] = $manager->getStorage($type)->getQuery()->accessCheck(FALSE)->count()->execute();
  }
  return $result;
};
$before = $snapshot();
$check(array_map('intval', array_values($before)) === [1, 1, 1, 3, 0], 'Expected demo content exists without generated turns');
$board = \Drupal::service('entity.repository')->loadEntityByUuid('ai_storyboard', 'e738e437-b902-4700-87e4-7939de19ec03');
$scene = \Drupal::service('entity.repository')->loadEntityByUuid('ai_storyboard_scene', '53f56dda-c79d-41a9-b3ad-3e136cb9206e');
foreach ($manager->getStorage('ai_storyboard_shot')->loadMultiple() as $shot) {
  $check((int) $shot->get('storyboard_id')->target_id === (int) $board->id() && (int) $shot->get('scene_id')->target_id === (int) $scene->id(), 'Shot references resolve to the demo storyboard and scene');
  $check(count($shot->validate()) === 0, 'Imported shot satisfies entity constraints');
}
$prompt = \Drupal::configFactory()->getEditable('ai.ai_prompt.ai_image_studio_start__demo_kakadu_dragons');
$original = $prompt->get('prompt');
$title = $board->label();
try {
  $prompt->set('prompt', 'Editor-customized demo prompt')->save();
  $board->set('title', 'Editor-customized storyboard')->save();
  $path = \Drupal::service('extension.list.module')->getPath('ai_image_studio') . '/recipes/ai_image_studio_storyboard_demo';
  RecipeRunner::processRecipe(Recipe::createFromDirectory($path));
  $check($snapshot() === $before, 'Repeat application does not duplicate content or generate turns');
  $check(\Drupal::config($prompt->getName())->get('prompt') === 'Editor-customized demo prompt', 'Repeat application preserves edited Start prompts');
  $manager->getStorage('ai_storyboard')->resetCache();
  $check($manager->getStorage('ai_storyboard')->load($board->id())->label() === 'Editor-customized storyboard', 'Repeat application preserves edited content');
  $database = \Drupal::database();
  $check(!$database->schema()->tableExists('queue') || (int) $database->select('queue')->countQuery()->execute()->fetchField() === 0, 'Recipe queues no background generation');
  $check(\Drupal::config('ai_image_studio.settings')->get('require_ai_badges') === FALSE, 'Recipe leaves corporate defaults unchanged');
}
finally {
  $prompt->set('prompt', $original)->save();
  $board->set('title', $title)->save();
}
