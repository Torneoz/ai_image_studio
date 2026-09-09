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
    private readonly NarrativeContext $narrative,
    private readonly BreakdownMerger $merger,
  ) {}

  /**
   * Merges without deleting shots; retained for callers of the original API.
   */
  public function replaceShots(object $storyboard, array $breakdown): int {
    $stats = $this->mergeBreakdown($storyboard, $breakdown);
    return $stats['created'] + $stats['updated'];
  }

  /**
   * Applies incremental suggestions and reports affected shots.
   */
  public function mergeBreakdown(object $storyboard, array $breakdown): array {
    return $this->merger->merge($storyboard, $breakdown);
  }

  /**
   * Prepares a session using the project machine name for generated assets.
   */
  public function prepareSession(object $storyboard): object {
    $session = $storyboard->get('studio_session_id')->entity;
    $name = (string) ($storyboard->get('machine_name')->value ?: $storyboard->label());
    if (!$session) {
      $session = $this->entityTypeManager->getStorage('ai_image_studio_session')->create([
        'title' => 'Storyboard: ' . $storyboard->label(),
        'machine_name' => $this->machineName->generate($name),
        'uid' => $storyboard->getOwnerId(),
        'status' => 'active',
      ]);
      $session->save();
      $storyboard->set('studio_session_id', $session->id())->save();
    }
    else {
      $name = $this->machineName->generate($name, (int) $session->id());
      if ($session->get('machine_name')->value !== $name) {
        $session->set('machine_name', $name)->save();
      }
    }
    return $session;
  }

  /**
   * Generates or regenerates one shot and returns the Image Studio turn.
   */
  public function generateShot(object $storyboard, object $shot): object {
    $session = $this->prepareSession($storyboard);
    $after_prompt = trim((string) $storyboard->get('after_prompt')->value);
    $prompt = implode("\n\n", array_filter([
      'Create one production storyboard frame. No text, lettering, borders, split panels, or captions.',
      'VISUAL STYLE: ' . $storyboard->get('visual_style')->value,
      'ASPECT RATIO: ' . $storyboard->get('aspect_ratio')->value,
      'CONTINUITY BIBLE: ' . $storyboard->get('continuity_bible')->value,
      'CHARACTER BIBLE: ' . $storyboard->get('character_bible')->value,
      $this->narrative->prompt($shot),
      'THIS SHOT: ' . $shot->get('image_prompt')->value,
      'COMPOSITION: ' . implode(', ', array_filter([$shot->get('shot_size')->value, $shot->get('camera_angle')->value, $shot->get('lens')->value, $shot->get('lighting')->value])),
      'SHOT CONTINUITY: ' . $shot->get('continuity_notes')->value,
      $after_prompt !== '' ? 'FINAL INSTRUCTION: ' . $after_prompt : '',
    ]));
    $references = $this->narrative->references($shot);
    $settings = ['aspect_ratio' => (string) $storyboard->get('aspect_ratio')->value, 'variations' => 1];
    $supports_references = $this->imageGenerator->supportsMultipleImages((string) $storyboard->get('image_model')->value);
    if ($references && $supports_references) {
      $settings['reference_file_ids'] = array_map(static fn(object $file): int => (int) $file->id(), $references);
    }
    $turn = $this->imageGenerator->generate(
      $session,
      $prompt,
      (string) $storyboard->get('image_model')->value,
      NULL,
      $supports_references ? ($references[0] ?? NULL) : NULL,
      $settings,
    );
    $shot->set('studio_turn_id', $turn->id());
    $shot->set('status', $turn->get('status')->value === 'completed' ? 'generated' : 'draft');
    $shot->save();
    return $turn;
  }

}
