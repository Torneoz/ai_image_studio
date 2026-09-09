<?php

/**
 * @file
 * Rollback-only Drupal script download checks; no API requests.
 */

use Drupal\ai_storyboard\Controller\StoryboardExportController;
use Drupal\ai_storyboard\Service\ScriptExporter;
use Drupal\Core\Session\AnonymousUserSession;

$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
$manager = \Drupal::entityTypeManager();
$transaction = \Drupal::database()->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($manager->getStorage('user')->load(1));
try {
  $board = $manager->getStorage('ai_storyboard')->create(['title' => 'Export fixture', 'machine_name' => 'script_export_fixture', 'uid' => 1, 'script' => "INT. HALL - DAY\n\nMIRA\nHello."]);
  $board->save();
  $location = $manager->getStorage('ai_storyboard_location')->create(['title' => 'Export location', 'bible' => 'Pinned stone hall.']);
  $location->save();
  $scene = $manager->getStorage('ai_storyboard_scene')->create(['storyboard_id' => $board->id(), 'title' => 'Hall', 'scene_number' => 1, 'source_id' => $location->id()]);
  $scene->save();
  $shot = $manager->getStorage('ai_storyboard_shot')->create(['storyboard_id' => $board->id(), 'scene_id' => $scene->id(), 'title' => 'Arrival', 'position' => 1, 'action' => 'Generated action must not replace the saved script.']);
  $shot->save();
  $controller = StoryboardExportController::create(\Drupal::getContainer());
  foreach (array_keys(ScriptExporter::FORMATS) as $format) {
    $response = $controller->downloadScript($board, $format);
    $check($response->getStatusCode() === 200 && str_contains($response->headers->get('Content-Disposition'), 'script_export_fixture-script.' . $format), "$format downloads with a machine-name attachment filename");
    $check(str_contains($response->headers->get('Cache-Control'), 'no-store'), "$format does not cache private script content");
  }
  $json = json_decode($controller->downloadScript($board, 'json')->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  $check(count($json['snapshot']['ai_storyboard_scene']) === 1 && count($json['snapshot']['ai_storyboard_shot']) === 1, 'JSON includes owned scenes and shots without requiring generated media');
  $check($json['snapshot']['ai_storyboard_scene'][0]['bible_snapshot'][0]['value'] === 'Pinned stone hall.', 'JSON retains pinned location facts');
  $check($controller->downloadScript($board, 'txt')->getContent() === $board->get('script')->value, 'Downloads use the saved source, not generated shot text');
  $route = \Drupal::service('router.route_provider')->getRouteByName('ai_storyboard.download_script');
  $check($route->getRequirement('_entity_access') === 'ai_storyboard.view', 'Download route requires storyboard view access');
  $check(!\Drupal::service('access_manager')->checkNamedRoute('ai_storyboard.download_script', ['ai_storyboard' => $board->id(), 'format' => 'json'], new AnonymousUserSession()), 'Anonymous users cannot download the private storyboard');
}
finally {
  $transaction->rollBack();
  $switcher->switchBack();
  foreach (['ai_storyboard', 'ai_storyboard_scene', 'ai_storyboard_shot', 'ai_storyboard_location'] as $type) {
    $manager->getStorage($type)->resetCache();
    \Drupal::service('cache_tags.invalidator')->invalidateTags($manager->getDefinition($type)->getListCacheTags());
  }
}
