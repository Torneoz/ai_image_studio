<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves project-owned scenes and pinned library context for generation.
 */
final class NarrativeContext {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Finds or creates a scene, preserving existing user-authored direction.
   */
  public function ensureScene(int $project_id, int $number, array $draft = []): object {
    $storage = $this->entityTypeManager->getStorage('ai_storyboard_scene');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('storyboard_id', $project_id)->condition('scene_number', $number)->sort('id')->range(0, 1)->execute();
    $values = [
      'storyboard_id' => $project_id,
      'scene_number' => $number,
      'title' => (string) ($draft['title'] ?? 'Scene ' . $number),
      'script_excerpt' => (string) ($draft['script_excerpt'] ?? ''),
      'conditions' => (string) ($draft['conditions'] ?? ''),
      'action' => (string) ($draft['action'] ?? ''),
      'overrides' => (string) ($draft['location_bible'] ?? ''),
    ];
    if ($ids) {
      $scene = $storage->load(reset($ids));
      $changed = FALSE;
      foreach ($values as $field => $value) {
        // Do not turn an inferred location into an override of a chosen bible.
        if ($field === 'overrides' && $scene->get('source_id')->target_id) {
          continue;
        }
        if ($scene->get($field)->isEmpty() && $value !== '') {
          $scene->set($field, $value);
          $changed = TRUE;
        }
      }
      if ($changed) {
        $scene->save();
      }
      return $scene;
    }
    $scene = $storage->create($values);
    $scene->save();
    return $scene;
  }

  /**
   * Returns only a scene belonging to the shot's project.
   */
  public function scene(object $shot): ?object {
    $scene = $shot->get('scene_id')->entity;
    return $scene && $scene->get('storyboard_id')->target_id == $shot->get('storyboard_id')->target_id ? $scene : NULL;
  }

  /**
   * Returns cast present in this scene, plus the shot's selected speaker.
   */
  private function cast(object $shot): array {
    $scene = $this->scene($shot);
    $cast = [];
    foreach ($scene?->get('cast_ids')->referencedEntities() ?? [] as $member) {
      if ($member->get('storyboard_id')->target_id == $shot->get('storyboard_id')->target_id) {
        $cast[$member->id()] = $member;
      }
    }
    $speaker = $shot->get('speaker_id')->entity;
    if ($speaker && $speaker->get('storyboard_id')->target_id == $shot->get('storyboard_id')->target_id) {
      $cast[$speaker->id()] = $speaker;
    }
    return $cast;
  }

  /**
   * Builds the same narrative context for still frames and videos.
   */
  public function prompt(object $shot, bool $video = FALSE): string {
    $parts = [];
    $scene = $this->scene($shot);
    if ($scene) {
      $parts[] = 'SCENE: ' . $scene->label();
      foreach ([
        'source_label' => 'LOCATION',
        'bible_snapshot' => 'PINNED LOCATION BIBLE',
        'overrides' => 'SCENE LOCATION OVERRIDES',
        'conditions' => 'SCENE CONDITIONS',
        'action' => 'SCENE BEATS',
      ] as $field => $label) {
        $value = trim((string) $scene->get($field)->value);
        if ($value !== '') {
          $parts[] = $label . ': ' . $value;
        }
      }
    }
    foreach ($this->cast($shot) as $member) {
      $parts[] = 'CHARACTER: ' . $member->label();
      foreach (['bible_snapshot' => 'PINNED CHARACTER BIBLE', 'overrides' => 'PROJECT CHARACTER OVERRIDES'] + ($video ? ['voice_snapshot' => 'VOICE'] : []) as $field => $label) {
        $value = trim((string) $member->get($field)->value);
        if ($value !== '') {
          $parts[] = $label . ': ' . $value;
        }
      }
      if ($video && $member->id() == $shot->get('speaker_id')->target_id) {
        $parts[] = 'DIALOGUE SPEAKER: ' . $member->label() . '. Use this character’s voice for the supplied lines unless a line explicitly identifies another speaker.';
      }
    }
    if ($scene && trim((string) $scene->get('character_states')->value) !== '') {
      $parts[] = 'SCENE CHARACTER STATES: ' . $scene->get('character_states')->value;
    }
    if ($parts) {
      array_unshift($parts, 'NARRATIVE CONTINUITY: Use the pinned library facts below. Project and scene overrides are intentional exceptions; shot-specific directions further refine this context. Do not introduce unrelated characters or locations.');
    }
    return implode("\n\n", $parts);
  }

  /**
   * Returns pinned references; never reads the live library's current images.
   */
  public function references(object $shot): array {
    $entities = $this->cast($shot);
    if ($scene = $this->scene($shot)) {
      array_unshift($entities, $scene);
    }
    $files = [];
    foreach ($entities as $entity) {
      foreach ($entity->get('reference_snapshot')->referencedEntities() as $file) {
        $files[$file->id()] = $file;
      }
    }
    return array_values($files);
  }

  /**
   * Prevents a continuous video bridge across a scene cut.
   */
  public function sameScene(object $first, object $second): bool {
    if ($first->get('storyboard_id')->target_id != $second->get('storyboard_id')->target_id) {
      return FALSE;
    }
    $a = $first->get('scene_id')->target_id;
    $b = $second->get('scene_id')->target_id;
    return $a || $b ? $a && $b && $a == $b : $first->get('scene_number')->value == $second->get('scene_number')->value;
  }

}
