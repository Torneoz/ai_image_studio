<?php

/**
 * @file
 * Checks remembered selectors through Drupal's complete Form API build.
 *
 * Run with drush php:script on a site with a Studio session and saved prompts.
 * This uses an in-memory preference store and does not submit generation.
 */

use Drupal\ai_image_studio\Form\StudioForm;
use Drupal\ai_image_studio\Service\PromptResolver;
use Drupal\Core\Form\FormState;
use Drupal\Core\TempStore\PrivateTempStore;

$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
$account = \Drupal::currentUser()->getAccount();
\Drupal::currentUser()->setAccount(\Drupal::entityTypeManager()->getStorage('user')->load(1));
try {
  $form = StudioForm::create(\Drupal::getContainer());
  $sessions = \Drupal::entityTypeManager()->getStorage('ai_image_studio_session');
  $ids = $sessions->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 30)->execute();
  $source = NULL;
  foreach ($sessions->loadMultiple($ids) as $candidate) {
    $count = (new ReflectionMethod(StudioForm::class, 'countTurns'))->invoke($form, (int) $candidate->id());
    $source = (new ReflectionMethod(StudioForm::class, 'latestCompletedTurn'))->invoke($form, (int) $candidate->id());
    if ($source && $count < (int) (\Drupal::config('ai_image_studio.settings')->get('max_turns') ?: 25)) {
      $session = $candidate;
      break;
    }
  }
  if (!isset($session)) {
    throw new RuntimeException('A session with a completed source and room for another turn is required.');
  }
  $saved = ['source_turn_id' => (int) $source->id(), 'output_type' => 'video'];
  foreach (['start_prompt' => PromptResolver::START_PROMPT_TYPE, 'style_prompt' => PromptResolver::STYLE_PROMPT_TYPE, 'prompt' => PromptResolver::PROMPT_TYPE] as $name => $type) {
    $ids = \Drupal::entityTypeManager()->getStorage('ai_prompt')->getQuery()->accessCheck(FALSE)->condition('type', $type)->range(0, 1)->execute();
    if (!$ids) {
      throw new RuntimeException('A saved prompt of type ' . $type . ' is required.');
    }
    $saved[$name] = reset($ids);
  }
  $store = new class($saved) extends PrivateTempStore {
    public function __construct(private array $saved) {}

    public function get($key) {
      return $this->saved;
    }
  };
  (new ReflectionProperty(StudioForm::class, 'requestSelections'))->setValue($form, $store);
  $state = new FormState();
  $state->addBuildInfo('args', [$session]);
  $built = \Drupal::formBuilder()->buildForm($form, $state);
  foreach (['start_prompt', 'style_prompt', 'prompt'] as $name) {
    $actual = $built['refine'][$name]['table']['#value'] ?? NULL;
    if ($actual !== $saved[$name]) {
      print json_encode(["name" => $name, "expected" => $saved[$name], "actual" => $actual, "default" => $built["refine"][$name]["#default_value"] ?? NULL, "state" => $state->getValue($name)]) . PHP_EOL;
    }
    $check($actual === $saved[$name], "$name survives Drupal's full form processing");
  }
  $check((int) $state->getValue('source_turn_id') === $saved['source_turn_id'], 'Processed form retains the source image');
  $check($built['refine']['output_type']['#value'] === 'video', 'Processed form retains image-to-video mode');
  $check(str_contains(json_encode($built['refine']['prompt']['table']['#states']['disabled']), 'video'), 'After prompt selector has the video-disabled state');
  $check(empty($built['refine']['style_prompt']['table']['#disabled']), 'Style remains available for video');
  $videos = \Drupal::entityTypeManager()->getStorage('ai_image_studio_turn');
  $ids = $videos->getQuery()->accessCheck(FALSE)->condition('status', 'completed')->exists('video.target_id')->sort('id', 'DESC')->range(0, 1)->execute();
  if ($ids) {
    $video = $videos->load(reset($ids));
    $video_session = $sessions->load($video->get('session_id')->target_id);
    $request = \Drupal::request();
    $original_query = $request->query->all();
    try {
      $request->query->set('regenerate_video', $video->id());
      $state = new FormState();
      $state->addBuildInfo('args', [$video_session]);
      $built = \Drupal::formBuilder()->buildForm($form, $state);
      $check(!empty($built['video_regeneration']['prompt']['table']['#disabled']), 'Video regeneration After prompt is disabled server-side');
      foreach (['start_prompt', 'style_prompt', 'prompt'] as $name) {
        $check(($built['video_regeneration'][$name]['table']['#value'] ?? NULL) === $saved[$name], "Video regeneration: $name survives full form processing");
      }
    }
    finally {
      $request->query->replace($original_query);
    }
  }
  // Submitted empty selections must override defaults on AJAX refreshes.
  $element = [
    '#id' => 'test-prompt', '#parents' => ['prompt'],
    '#default_value' => $saved['prompt'],
    'table' => [
      '#default_value' => '',
      '#options' => [$saved['prompt'] => ['prompt_label' => 'Test', 'prompt' => 'Test']],
    ],
  ];
  $state = new FormState();
  $state->setProcessInput(TRUE);
  $complete = [];
  $element = \Drupal\ai_image_studio\Element\PromptSelect::processSelect($element, $state, $complete);
  $check($element['table']['#default_value'] === '', 'An explicitly cleared AJAX selection is not replaced by a remembered default');

}
finally {
  \Drupal::currentUser()->setAccount($account);
}
