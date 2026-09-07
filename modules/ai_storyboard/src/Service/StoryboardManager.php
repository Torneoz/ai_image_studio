<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Service;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_image_studio\Service\SessionMachineName;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Persists shot breakdowns and delegates frame generation to Image Studio. */
final class StoryboardManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ImageGenerator $imageGenerator,
    private readonly SessionMachineName $machineName,
  ) {}

  /**
   * Replaces a board's shots with an AI breakdown. */
  public function replaceShots(object $storyboard, array $breakdown): int {
    $storage = $this->entityTypeManager->getStorage('ai_storyboard_shot');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('storyboard_id', $storyboard->id())->execute();
    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
    $storyboard->set('continuity_bible', (string) ($breakdown['continuity_bible'] ?? ''));
    $storyboard->save();
    foreach (array_values($breakdown['shots']) as $index => $shot) {
      $storage->create([
        'storyboard_id' => $storyboard->id(),
        'position' => $index + 1,
        'scene_number' => max(1, (int) ($shot['scene_number'] ?? 1)),
        'shot_number' => max(1, (int) ($shot['shot_number'] ?? ($index + 1))),
        'title' => (string) ($shot['title'] ?? 'Untitled shot'),
        'action' => (string) ($shot['action'] ?? ''),
        'dialogue' => (string) ($shot['dialogue'] ?? ''),
        'audio' => (string) ($shot['audio'] ?? ''),
        'shot_size' => (string) ($shot['shot_size'] ?? ''),
        'camera_angle' => (string) ($shot['camera_angle'] ?? ''),
        'camera_move' => (string) ($shot['camera_move'] ?? ''),
        'lens' => (string) ($shot['lens'] ?? ''),
        'lighting' => (string) ($shot['lighting'] ?? ''),
        'duration' => max(0.1, (float) ($shot['duration'] ?? 3)),
        'image_prompt' => (string) ($shot['image_prompt'] ?? ''),
        'continuity_notes' => (string) ($shot['continuity_notes'] ?? ''),
      ])->save();
    }
    return count($breakdown['shots']);
  }

  /**
   * Generates or regenerates one shot and returns the Image Studio turn. */
  public function generateShot(object $storyboard, object $shot): object {
    $session = $storyboard->get('studio_session_id')->entity;
    if (!$session) {
      $session = $this->entityTypeManager->getStorage('ai_image_studio_session')->create([
        'title' => 'Storyboard: ' . $storyboard->label(),
        'machine_name' => $this->machineName->generate('storyboard-' . $storyboard->id() . '-' . $storyboard->label()),
        'uid' => $storyboard->getOwnerId(),
        'status' => 'active',
      ]);
      $session->save();
      $storyboard->set('studio_session_id', $session->id())->save();
    }
    $prompt = implode("\n\n", array_filter([
      'Create one production storyboard frame. No text, lettering, borders, split panels, or captions.',
      'VISUAL STYLE: ' . $storyboard->get('visual_style')->value,
      'ASPECT RATIO: ' . $storyboard->get('aspect_ratio')->value,
      'CONTINUITY BIBLE: ' . $storyboard->get('continuity_bible')->value,
      'THIS SHOT: ' . $shot->get('image_prompt')->value,
      'COMPOSITION: ' . implode(', ', array_filter([$shot->get('shot_size')->value, $shot->get('camera_angle')->value, $shot->get('lens')->value, $shot->get('lighting')->value])),
      'SHOT CONTINUITY: ' . $shot->get('continuity_notes')->value,
    ]));
    $turn = $this->imageGenerator->generate(
      $session,
      $prompt,
      (string) $storyboard->get('image_model')->value,
      NULL,
      NULL,
      ['aspect_ratio' => (string) $storyboard->get('aspect_ratio')->value, 'variations' => 1],
    );
    $shot->set('studio_turn_id', $turn->id());
    $shot->set('status', $turn->get('status')->value === 'completed' ? 'generated' : 'draft');
    $shot->save();
    return $turn;
  }

}
