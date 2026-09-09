<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;

/**
 * Merges AI suggestions without blanking or deleting production content.
 */
final class BreakdownMerger {

  private const FIELDS = [
    'title', 'action', 'dialogue', 'audio', 'shot_size', 'camera_angle',
    'camera_move', 'lens', 'lighting', 'duration', 'image_prompt', 'continuity_notes',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $manager,
    private readonly Connection $database,
  ) {}

  /**
   * Reloads project records to detect edits made during a provider request.
   */
  private function records(string $type, int $project): array {
    $storage = $this->manager->getStorage($type);
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('storyboard_id', $project)->sort('id')->execute();
    $storage->resetCache($ids);
    return $storage->loadMultiple($ids);
  }

  /**
   * Supplies stable identities and current content to the breakdown model.
   */
  public function context(int $project): array {
    $result = ['scenes' => [], 'shots' => []];
    foreach (['scenes' => 'ai_storyboard_scene', 'shots' => 'ai_storyboard_shot'] as $key => $type) {
      foreach ($this->records($type, $project) as $entity) {
        $row = [
          'id' => (int) $entity->id(),
          'record_hash' => hash('sha256', json_encode($entity->toArray(), JSON_THROW_ON_ERROR)),
        ];
        $fields = $key === 'shots'
          ? array_merge(self::FIELDS, ['position', 'scene_number', 'shot_number'])
          : ['title', 'scene_number', 'script_excerpt', 'conditions', 'action', 'overrides'];
        foreach ($fields as $field) {
          $row[$field] = $entity->get($field)->value;
        }
        $row[$key === 'shots' ? 'scene_id' : 'library_location_id'] = $entity->get($key === 'shots' ? 'scene_id' : 'source_id')->target_id;
        $result[$key][] = $row;
      }
    }
    return $result;
  }

  /**
   * Matches scoped IDs, with unique titles as a legacy fallback.
   */
  private function match(array $draft, string $key, array $records, array $used): ?object {
    $id = (int) ($draft[$key] ?? 0);
    if ($id) {
      if (!isset($records[$id]) || isset($used[$id])) {
        throw new \UnexpectedValueException('Unknown or repeated existing record ID in breakdown.');
      }
      return $records[$id];
    }
    if (array_key_exists($key, $draft)) {
      return NULL;
    }
    $title = mb_strtolower(trim((string) ($draft['title'] ?? '')));
    $matches = array_filter($records, static fn(object $entity): bool => !isset($used[$entity->id()]) && $title !== '' && mb_strtolower(trim((string) $entity->label())) === $title);
    return count($matches) === 1 ? reset($matches) : NULL;
  }

  /**
   * Ignores blank, invalid and unchanged values.
   */
  private function assign(object $entity, string $field, mixed $value): bool {
    if (!is_scalar($value) || trim((string) $value) === '') {
      return FALSE;
    }
    if ($field === 'duration') {
      if (!is_numeric($value) || (float) $value <= 0) {
        return FALSE;
      }
      $value = number_format((float) $value, 2, '.', '');
    }
    if ((string) $entity->get($field)->value === (string) $value) {
      return FALSE;
    }
    $entity->set($field, $value);
    return TRUE;
  }

  /**
   * Retains omitted records and media; changed shot content returns to draft.
   */
  public function merge(object $project, array $data): array {
    $transaction = $this->database->startTransaction();
    try {
      return $this->apply($project, $data);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      foreach (['ai_storyboard', 'ai_storyboard_scene', 'ai_storyboard_shot'] as $type) {
        $this->manager->getStorage($type)->resetCache();
      }
      throw $exception;
    }
  }

  /**
   * Applies validated suggestions inside the merge transaction.
   */
  private function apply(object $project, array $data): array {
    $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'retained' => 0];
    $scenes = $this->records('ai_storyboard_scene', (int) $project->id());
    $shots = $this->records('ai_storyboard_shot', (int) $project->id());
    $scene_storage = $this->manager->getStorage('ai_storyboard_scene');
    $shot_storage = $this->manager->getStorage('ai_storyboard_shot');
    $used_scenes = $used_shots = $scene_map = $changed_scenes = [];
    $next_scene = $next_position = 0;
    foreach ($scenes as $scene) {
      $next_scene = max($next_scene, (int) $scene->get('scene_number')->value);
    }
    foreach ($shots as $shot) {
      $next_position = max($next_position, (int) $shot->get('position')->value);
    }
    foreach ($data['scenes'] ?? [] as $draft) {
      $scene = $this->match($draft, 'existing_scene_id', $scenes, $used_scenes);
      if (!$scene) {
        $scene = $scene_storage->create([
          'storyboard_id' => $project->id(),
          'scene_number' => ++$next_scene,
          'title' => 'Scene ' . $next_scene,
        ]);
      }
      $changed = $scene->isNew();
      $existing_scene = !$scene->isNew();
      foreach (['title', 'script_excerpt', 'conditions', 'action'] as $field) {
        $changed = $this->assign($scene, $field, $draft[$field] ?? NULL) || $changed;
      }
      if (!$scene->get('source_id')->target_id) {
        $changed = $this->assign($scene, 'overrides', $draft['location_bible'] ?? NULL) || $changed;
      }
      if ($changed) {
        $scene->save();
        if ($existing_scene) {
          $changed_scenes[$scene->id()] = TRUE;
        }
      }
      $used_scenes[$scene->id()] = TRUE;
      $number = (int) ($draft['scene_number'] ?? $scene->get('scene_number')->value);
      if ($number < 1 || isset($scene_map[$number])) {
        throw new \UnexpectedValueException('Invalid or repeated scene number in breakdown.');
      }
      $scene_map[$number] = $scene;
      $scenes[$scene->id()] = $scene;
    }
    foreach ($data['shots'] ?? [] as $draft) {
      $shot = $this->match($draft, 'existing_shot_id', $shots, $used_shots);
      $new = !$shot;
      if ($new) {
        $shot = $shot_storage->create([
          'storyboard_id' => $project->id(),
          'position' => ++$next_position,
          'title' => 'Untitled shot',
          'shot_number' => max(1, (int) ($draft['shot_number'] ?? 1)),
        ]);
      }
      $changed = $new;
      $content_changed = isset($changed_scenes[$shot->get('scene_id')->target_id]);
      foreach (self::FIELDS as $field) {
        $content_changed = $this->assign($shot, $field, $draft[$field] ?? NULL) || $content_changed;
      }
      $scene_id = (int) ($draft['existing_scene_id'] ?? 0);
      if ($scene_id && !isset($scenes[$scene_id])) {
        throw new \UnexpectedValueException('The shot references a scene outside this project.');
      }
      $scene = $scene_id ? $scenes[$scene_id] : ($scene_map[(int) ($draft['scene_number'] ?? 0)] ?? NULL);
      if (!$scene && $new) {
        foreach ($scenes as $candidate) {
          if ($candidate->get('scene_number')->value == ($draft['scene_number'] ?? 1)) {
            $scene = $candidate;
            break;
          }
        }
        if (!$scene) {
          $scene = $scene_storage->create([
            'storyboard_id' => $project->id(),
            'scene_number' => ++$next_scene,
            'title' => 'Scene ' . $next_scene,
          ]);
          $scene->save();
          $scenes[$scene->id()] = $scene;
        }
      }
      if ($scene && $shot->get('scene_id')->target_id != $scene->id()) {
        $shot->set('scene_id', $scene->id());
        $content_changed = TRUE;
      }
      foreach (['position', 'shot_number'] as $field) {
        if (isset($draft[$field]) && is_numeric($draft[$field]) && (int) $draft[$field] > 0) {
          $changed = $this->assign($shot, $field, (int) $draft[$field]) || $changed;
        }
      }
      if ($content_changed && !$new) {
        $shot->set('status', 'draft');
      }
      if ($changed || $content_changed) {
        $shot->save();
        $stats[$new ? 'created' : 'updated']++;
      }
      else {
        $stats['unchanged']++;
      }
      $used_shots[$shot->id()] = TRUE;
    }
    foreach (array_diff_key($shots, $used_shots) as $shot) {
      if (isset($changed_scenes[$shot->get('scene_id')->target_id]) && $shot->get('status')->value !== 'draft') {
        $shot->set('status', 'draft')->save();
        $stats['updated']++;
        $used_shots[$shot->id()] = TRUE;
      }
    }
    $stats['retained'] = count(array_diff_key($shots, $used_shots));
    $changed = FALSE;
    foreach (['continuity_bible', 'character_bible'] as $field) {
      $changed = $this->assign($project, $field, $data[$field] ?? NULL) || $changed;
    }
    if ($changed) {
      $project->save();
    }
    return $stats;
  }

}
