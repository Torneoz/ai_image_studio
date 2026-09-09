<?php

/**
 * @file
 * Runtime smoke test: drush php:script path/to/narrative-smoke.php.
 *
 * Run on a development site after updatedb. Database fixtures are rolled back;
 * no AI providers are invoked and no reference image files are written.
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\UserSession;
use Drupal\views\Views;

$manager = \Drupal::entityTypeManager();
$database = \Drupal::database();
$transaction = $database->startTransaction();
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($manager->getStorage('user')->load(1));
$tags = [];
$checks = 0;
$check = static function (bool $pass, string $message) use (&$checks): void {
  if (!$pass) {
    throw new \RuntimeException($message);
  }
  $checks++;
  print "PASS: $message\n";
};
$create = static function (string $type, array $values) use ($manager, &$tags): object {
  $entity = $manager->getStorage($type)->create($values);
  $entity->save();
  $tags = array_merge($tags, $entity->getCacheTags(), $entity->getEntityType()->getListCacheTags());
  return $entity;
};
try {
  $project = $create('ai_storyboard', ['title' => 'Narrative smoke fixture', 'uid' => 1, 'script' => 'INT. HALL - DAY']);
  $other = $create('ai_storyboard', ['title' => 'Other narrative fixture', 'uid' => 424242]);
  $location = $create('ai_storyboard_location', ['title' => 'Hall', 'bible' => 'Red stone arches.']);
  $file = $create('file', ['uid' => 1, 'filename' => 'narrative-smoke.jpg', 'uri' => 'private://narrative-smoke-' . bin2hex(random_bytes(8)) . '.jpg', 'filemime' => 'image/jpeg', 'status' => 1]);
  $character = $create('ai_storyboard_character', ['title' => 'Mira', 'bible' => 'Silver eyes.', 'voice' => 'Warm alto.', 'references' => [$file->id()]]);
  $revision = (int) $character->getRevisionId();
  $cast = $create('ai_storyboard_cast', ['title' => 'Mira in production', 'storyboard_id' => $project->id(), 'source_id' => $character->id(), 'overrides' => 'Blue coat.']);
  $scene = $create('ai_storyboard_scene', ['title' => 'Arrival', 'storyboard_id' => $project->id(), 'scene_number' => 1, 'source_id' => $location->id(), 'conditions' => 'Dawn, raining.', 'cast_ids' => [$cast->id()], 'character_states' => 'Mira is soaked.']);
  $shot = $create('ai_storyboard_shot', ['title' => 'Entrance', 'storyboard_id' => $project->id(), 'scene_id' => $scene->id(), 'speaker_id' => $cast->id(), 'position' => 1]);
  $second = $create('ai_storyboard_shot', ['title' => 'Reaction', 'storyboard_id' => $project->id(), 'scene_id' => $scene->id(), 'position' => 2]);
  $next_scene = $create('ai_storyboard_scene', ['title' => 'Outside', 'storyboard_id' => $project->id(), 'scene_number' => 2]);
  $third = $create('ai_storyboard_shot', ['title' => 'Cut away', 'storyboard_id' => $project->id(), 'scene_id' => $next_scene->id(), 'position' => 3]);
  $foreign = $create('ai_storyboard_scene', ['title' => 'Other owner scene', 'storyboard_id' => $other->id(), 'scene_number' => 1]);
  $check((int) $cast->get('source_revision')->value === $revision, 'New project cast pins the library revision');
  $character->set('bible', 'Golden eyes.')->set('voice', 'Low whisper.')->set('references', [])->save();
  $check((int) $character->getRevisionId() !== $revision, 'Library saves create new revisions');
  $check($manager->getStorage('ai_storyboard_character')->loadRevision($revision)->get('bible')->value === 'Silver eyes.', 'Historical library revision is preserved');
  $cast = $manager->getStorage('ai_storyboard_cast')->loadUnchanged($cast->id());
  $check($cast->get('bible_snapshot')->value === 'Silver eyes.', 'Library edit does not alter project snapshot');
  $cast->set('overrides', 'Green coat.')->save();
  $check($cast->get('bible_snapshot')->value === 'Silver eyes.', 'Ordinary project cast save does not refresh the snapshot');
  $cast->refreshLibrary();
  $cast->save();
  $check($cast->get('bible_snapshot')->value === 'Golden eyes.' && $cast->get('overrides')->value === 'Green coat.', 'Explicit refresh adopts latest bible and preserves overrides');
  $cast->refreshLibrary($revision);
  $cast->save();
  $check($cast->get('voice_snapshot')->value === 'Warm alto.', 'Historical version can be explicitly selected');
  foreach (['ai_storyboard_cast', 'ai_storyboard_scene', 'ai_storyboard_shot'] as $type) {
    $manager->getStorage($type)->resetCache();
  }
  $shot = $manager->getStorage('ai_storyboard_shot')->load($shot->id());
  $narrative = \Drupal::service('ai_storyboard.narrative');
  $check((int) $narrative->references($shot)[0]->id() === (int) $file->id(), 'Reference images come from the selected historical version');
  $prompt = $narrative->prompt($shot, TRUE);
  foreach (['Red stone arches.', 'Silver eyes.', 'Warm alto.', 'Green coat.', 'Dawn, raining.', 'Mira is soaked.', 'DIALOGUE SPEAKER: Mira in production'] as $text) {
    $check(str_contains($prompt, $text), 'Video narrative includes ' . $text);
  }
  $check(!str_contains($narrative->prompt($shot), 'VOICE:'), 'Still-frame context omits voice instructions');
  $check($narrative->sameScene($shot, $second), 'Bridge permits keyframes in the same Scene');
  $check(!$narrative->sameScene($second, $third), 'Bridge rejects keyframes across Scene boundaries');
  $shot->set('scene_id', $foreign->id());
  try {
    $shot->save();
    $check(FALSE, 'Cross-project shot reference rejected');
  }
  catch (\Drupal\Core\Entity\EntityStorageException $exception) {
    $check(str_contains($exception->getMessage(), 'same project'), 'Cross-project shot reference rejected');
  }
  $shot->set('scene_id', $scene->id());
  $scene->set('scene_number', 10)->save();
  $check((int) $manager->getStorage('ai_storyboard_shot')->loadUnchanged($shot->id())->get('scene_number')->value === 10, 'Renumbering a Scene updates its shots');
  $check($narrative->ensureScene((int) $project->id(), 10, ['title' => 'Do not overwrite'])->label() === 'Arrival', 'Rebuild preserves authored Scenes');
  foreach (['location', 'character', 'scene', 'cast'] as $suffix) {
    $type = 'ai_storyboard_' . $suffix;
    foreach (['collection', 'add_form', 'canonical', 'edit_form'] as $operation) {
      $route = \Drupal::service('router.route_provider')->getRouteByName('entity.' . $type . '.' . $operation);
      $check((bool) $route, "$type $operation route exists");
    }
    $entity = match ($suffix) {
      'location' => $location, 'character' => $character, 'scene' => $scene, 'cast' => $cast,
    };
    $form = \Drupal::service('entity.form_builder')->getForm($entity, 'edit');
    $check(isset($form['title']), "$type edit form builds");
    $list = $manager->getListBuilder($type)->render();
    \Drupal::service('renderer')->renderInIsolation($list);
    $check(isset($list['table']), "$type library/list page renders");
    $route_name = 'entity.' . $type . '.canonical';
    $route = \Drupal::service('router.route_provider')->getRouteByName($route_name);
    $match = new \Drupal\Core\Routing\RouteMatch($route_name, $route, [$type => $entity]);
    $controller = \Drupal\ai_storyboard\Controller\NarrativeController::create(\Drupal::getContainer());
    $page = $controller->view($match);
    \Drupal::service('renderer')->renderInIsolation($page);
    $check(isset($page['edit']), "$type canonical page renders");
    $view_id = 'ai_storyboard_' . match ($suffix) {
      'location' => 'locations', 'character' => 'characters', 'scene' => 'scenes', 'cast' => 'cast',
    };
    $view = Views::getView($view_id);
    $view->setDisplay('page_1');
    $view->execute();
    $render = $view->render();
    \Drupal::service('renderer')->renderInIsolation($render);
    $check(count($view->result) > 0, "$view_id executes and renders rows");
    $view->destroy();
  }
  $edit = $manager->getFormObject('ai_storyboard_cast', 'edit')->setEntity($cast);
  $state = new \Drupal\Core\Form\FormState();
  $state->setValues([
    'op' => 'Save',
    'title' => [['value' => 'Mira saved through form']],
    'storyboard_id' => [['target_id' => $project->label() . ' (' . $project->id() . ')']],
    'source_id' => [['target_id' => $character->label() . ' (' . $character->id() . ')']],
    'overrides' => [['value' => 'Black coat.']],
    'refresh_library' => 1,
  ]);
  \Drupal::formBuilder()->submitForm($edit, $state);
  $check(!$state->hasAnyErrors(), 'Project character edit form validates and submits');
  $saved = $manager->getStorage('ai_storyboard_cast')->loadUnchanged($cast->id());
  $check($saved->label() === 'Mira saved through form' && $saved->get('bible_snapshot')->value === 'Golden eyes.', 'Edit form saves the refreshed library version');
  $add = $manager->getFormObject('ai_storyboard_scene', 'add')->setEntity($manager->getStorage('ai_storyboard_scene')->create());
  $state = new \Drupal\Core\Form\FormState();
  $state->setValues([
    'op' => 'Save',
    'title' => [['value' => 'Form-created Scene']],
    'storyboard_id' => [['target_id' => $project->label() . ' (' . $project->id() . ')']],
    'scene_number' => [['value' => 20]],
    'source_id' => [['target_id' => $location->label() . ' (' . $location->id() . ')']],
  ]);
  \Drupal::formBuilder()->submitForm($add, $state);
  $check(!$state->hasAnyErrors() && !$add->getEntity()->isNew(), 'Scene add form validates and saves');
  $tags = array_merge($tags, $add->getEntity()->getCacheTags());
  $session = $create('ai_image_studio_session', ['title' => 'Narrative test session', 'uid' => 1]);
  $project->set('studio_session_id', $session->id())->save();
  $turn = $create('ai_image_studio_turn', ['session_id' => $session->id(), 'prompt' => 'Test only', 'status' => 'completed', 'image' => $file->id()]);
  $second->set('studio_turn_id', $turn->id())->save();
  $third->set('studio_turn_id', $turn->id())->save();
  $bulk = \Drupal::service('ai_storyboard.bulk_manager');
  $job_count = (int) $database->select('ai_image_studio_vbo_job', 'j')->countQuery()->execute()->fetchField();
  try {
    $bulk->enqueueVideoSequences($project, 1, ['mode' => 'bridge', 'model' => 'narrative_test__disabled', 'duration' => 1, 'resolution' => '480p'], (int) $second->id());
    $check(FALSE, 'Bulk bridge rejects a scene cut before creating a job');
  }
  catch (\LogicException $exception) {
    $check(str_contains($exception->getMessage(), 'same scene'), 'Bulk bridge rejects a scene cut before creating a job');
  }
  $second->set('studio_turn_id', NULL)->save();
  $shot->set('studio_turn_id', $turn->id())->save();
  $second->set('scene_id', $next_scene->id())->save();
  $third->set('scene_id', $scene->id())->save();
  try {
    $bulk->enqueueVideoSequences($project, 1, ['mode' => 'bridge', 'model' => 'narrative_test__disabled', 'duration' => 1, 'resolution' => '480p']);
    $check(FALSE, 'Missing keyframes cannot hide an intervening scene cut');
  }
  catch (\LogicException $exception) {
    $check(str_contains($exception->getMessage(), 'same scene'), 'Missing keyframes cannot hide an intervening scene cut');
  }
  $check($job_count === (int) $database->select('ai_image_studio_vbo_job', 'j')->countQuery()->execute()->fetchField(), 'Rejected bridges create no bulk jobs');
  foreach (['locations', 'characters', 'scenes', 'cast'] as $suffix) {
    $plugin = \Drupal::service('plugin.manager.menu.link')->getDefinition('ai_storyboard.view_' . $suffix);
    $check($plugin['parent'] === 'ai_image_studio.views', 'Demo ' . $suffix . ' View appears under Views');
  }
  $account = new class(['uid' => 424242]) extends UserSession {
    public function hasPermission($permission): bool {
      return $permission === 'access ai storyboard';
    }
  };
  $switcher->switchTo($account);
  try {
    $check($location->access('view', $account), 'Storyboard users may read the shared library');
    $check(!$location->access('update', $account), 'Storyboard users may not edit the shared library');
    $check(!$scene->access('view', $account), 'Other owners cannot view private Scenes');
    $ids = $manager->getStorage('ai_storyboard_scene')->getQuery()->accessCheck(TRUE)->execute();
    $check(isset($ids[$foreign->id()]) && !isset($ids[$scene->id()]), 'Scene entity queries enforce project ownership');
    $view = Views::getView('ai_storyboard_scenes');
    $view->setDisplay('page_1');
    $view->execute();
    $ids = array_map(static fn(object $row): int => (int) $row->_entity->id(), $view->result);
    $check(in_array((int) $foreign->id(), $ids, TRUE) && !in_array((int) $scene->id(), $ids, TRUE), 'Scene View enforces project ownership');
    $view->destroy();
  }
  finally {
    $switcher->switchBack();
  }
  print "Completed $checks checks. No AI generation requested.\n";
}
finally {
  $transaction->rollBack();
  $switcher->switchBack();
  Cache::invalidateTags(array_unique($tags));
  foreach (['file', 'ai_image_studio_session', 'ai_image_studio_turn', 'ai_storyboard', 'ai_storyboard_shot', 'ai_storyboard_scene', 'ai_storyboard_cast', 'ai_storyboard_location', 'ai_storyboard_character'] as $type) {
    $manager->getStorage($type)->resetCache();
  }
}
