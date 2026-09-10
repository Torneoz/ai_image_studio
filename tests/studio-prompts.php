<?php

/**
 * @file
 * Standalone regression checks for reusable prompts and regeneration.
 */

use Drupal\ai_image_studio\Form\StudioForm;
use Drupal\ai_image_studio\Service\PromptResolver;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\TypedConfigManager;
use Drupal\Core\Form\FormState;
use Symfony\Component\EventDispatcher\EventDispatcher;

$loader = require __DIR__ . '/../vendor/autoload.php';
foreach (['file', 'user'] as $module) {
  $loader->addPsr4('Drupal\\' . $module . '\\', __DIR__ . '/../vendor/drupal/core/modules/' . $module . '/src');
}
require_once __DIR__ . '/../src/Service/PromptResolver.php';
require_once __DIR__ . '/../src/Form/StudioForm.php';

$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
$storage = new MemoryStorage();
foreach ([
  'start' => [PromptResolver::START_PROMPT_TYPE, 'A lighthouse'],
  'style' => [PromptResolver::STYLE_PROMPT_TYPE, 'Watercolour'],
  'new_style' => [PromptResolver::STYLE_PROMPT_TYPE, 'Pencil sketch'],
  'after' => [PromptResolver::PROMPT_TYPE, 'High detail'],
] as $id => [$type, $prompt]) {
  $storage->write('ai.ai_prompt.' . $id, ['type' => $type, 'prompt' => $prompt]);
}
// Reading immutable configuration does not require schema discovery.
$typed = (new ReflectionClass(TypedConfigManager::class))->newInstanceWithoutConstructor();
$resolver = new PromptResolver(new ConfigFactory($storage, new EventDispatcher(), $typed));
$parts = $resolver->parts('At sunset', 'after', 'style', 'start');
$original = "A lighthouse\n\nAt sunset\n\nWatercolour\n\nHigh detail";
$check($resolver->join($parts) === $original, 'Reusable start, additional instructions, style and after prompt retain their order');
$check($resolver->compose('At sunset', ['table' => 'after'], ['table' => 'style'], ['table' => 'start']) === $original, 'Nested AJAX selector values resolve correctly');
$check($resolver->compose('Free text', '', '', 'style') === 'Free text', 'Start selector rejects prompts of another type');
$check($resolver->compose('Free text', '') === 'Free text', 'Existing free-text callers remain compatible');
$updated = $resolver->regenerateParts($original, $parts, 'new_style');
$expected = "A lighthouse\n\nAt sunset\n\nPencil sketch\n\nHigh detail";
$check($resolver->join($updated) === $expected, 'Regeneration replaces the old style without losing subject or finishing instructions');
$check($resolver->regenerateParts($expected, $updated, 'new_style') === $updated, 'Repeated regeneration does not accumulate style instructions');
$check($resolver->regenerateParts($original, $parts, '') === $parts, 'No style selection preserves the original prompt');
$check($resolver->regenerateParts($original, $parts, ['table' => '']) === $parts, 'Empty nested selector preserves the original style');
$check($resolver->regenerateParts($original, $parts, 'missing') === $parts, 'Unavailable styles do not erase existing instructions');
$check($resolver->join($resolver->regenerateParts('Legacy prompt', [], 'new_style')) === "Legacy prompt\n\nPencil sketch", 'Legacy turns retain their full prompt when adding a style');
$check($resolver->join($resolver->regenerateParts('Edited prompt', $parts, '')) === 'Edited prompt', 'Stale stored parts cannot overwrite an edited prompt');

$form = (new ReflectionClass(StudioForm::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(StudioForm::class, 'promptResolver'))->setValue($form, $resolver);
$state = new FormState();
$state->setValues(['start_prompt' => 'start', 'prompt_start' => 'At sunset', 'style_prompt' => 'style', 'prompt' => 'after']);
$method = new ReflectionMethod(StudioForm::class, 'generationPrompt');
$check($method->invoke($form, $state) === $original, 'Studio generation uses the reusable start selection');
$check($state->get('generation_prompt_parts') === $parts, 'Studio retains resolved prompt parts for subsequent regeneration');
$turn = new class($original, $parts) {
  public function __construct(private string $prompt, private array $parts) {}

  public function get(string $field): object {
    if ($field === 'session_id') {
      return (object) ['target_id' => 1];
    }
    if ($field === 'status') {
      return (object) ['value' => 'completed'];
    }
    if ($field === 'image') {
      return new class {
        public object $entity;

        public function __construct() {
          $this->entity = (new ReflectionClass(Drupal\file\Entity\File::class))->newInstanceWithoutConstructor();
        }

        public function isEmpty(): bool {
          return FALSE;
        }
      };
    }
    if ($field === 'prompt') {
      return (object) ['value' => $this->prompt];
    }
    return new class($this->parts) {
      public function __construct(private array $parts) {}

      public function first(): static {
        return $this;
      }

      public function getValue(): array {
        return ['prompt_parts' => $this->parts];
      }
    };
  }
};
$video = new ReflectionMethod(StudioForm::class, 'videoPromptParts');
$check($resolver->join($video->invoke($form, $turn, ['style_prompt' => 'new_style'])) === "A lighthouse\n\nAt sunset\n\nPencil sketch", 'Video style-only changes preserve the original subject');
$check($resolver->join($video->invoke($form, $turn, [])) === "A lighthouse\n\nAt sunset\n\nWatercolour", 'Video settings-only regeneration preserves subject and style but removes After prompt');
$check($resolver->join($video->invoke($form, $turn, ['start_prompt' => 'start', 'style_prompt' => 'new_style'])) === "A lighthouse\n\nPencil sketch", 'Video replacement accepts a reusable start prompt');

$manager = new class($turn) extends Drupal\Core\Entity\EntityTypeManager {
  public function __construct(private object $turn) {}

  public function getStorage($entity_type_id) {
    return new class($this->turn) {
      public function __construct(private object $turn) {}

      public function load($id): ?object {
        return $id === 2 ? $this->turn : NULL;
      }
    };
  }
};
(new ReflectionProperty(StudioForm::class, 'entityTypeManager'))->setValue($form, $manager);
$state->set('session_id', 1);
$state->setValues(['regenerate_with_new_settings' => TRUE, 'source_turn_id' => 2, 'style_prompt' => 'new_style', 'prompt_start' => 'Ignored draft']);
$check($method->invoke($form, $state) === $expected, 'Regenerate with new settings applies the selected style and reuses source instructions');
$state->setValue('style_prompt', '');
$check($method->invoke($form, $state) === $original, 'Settings-only image regeneration keeps the complete original prompt');
$state->setValue('source_turn_id', 99);
$check($method->invoke($form, $state) === '', 'Missing regeneration sources fail prompt validation');

$selections = new class extends Drupal\Core\TempStore\PrivateTempStore {
  public array $saved = [];

  public function __construct() {}

  public function get($key) {
    return $this->saved[$key] ?? NULL;
  }

  public function set($key, $value) {
    $this->saved[$key] = $value;
  }
};
(new ReflectionProperty(StudioForm::class, 'requestSelections'))->setValue($form, $selections);
$remember = new ReflectionMethod(StudioForm::class, 'rememberRequestSelections');
$restore = new ReflectionMethod(StudioForm::class, 'restoreRequestSelections');
$remember->invoke($form, 1, ['source_turn_id' => 2, 'start_prompt' => 'start', 'style_prompt' => ['table' => 'style'], 'prompt' => 'after', 'output_type' => 'video', 'prompt_start' => 'One-off change']);
$fresh = new FormState();
$restore->invoke($form, $fresh, 1);
$check($fresh->getValue('source_turn_id') === 2 && $fresh->getValue('output_type') === 'video', 'Reset retains the chosen source image and image-to-video mode');
$check($fresh->getValue('start_prompt') === 'start' && $fresh->getValue('style_prompt') === 'style' && $fresh->getValue('prompt') === 'after', 'Reset restores all three reusable prompt selectors');
$check(!$fresh->hasValue('prompt_start'), 'One-off additional instructions are not repeated automatically');
$ajax = new FormState();
$ajax->setProcessInput(TRUE)->setValue('style_prompt', 'new_style');
$restore->invoke($form, $ajax, 1);
$check($ajax->getValue('style_prompt') === 'new_style' && !$ajax->hasValue('prompt'), 'AJAX submissions are never overwritten by remembered defaults');
$remember->invoke($form, 1, ['style_prompt' => 'new_style']);
$fresh = new FormState();
$restore->invoke($form, $fresh, 1);
$check($fresh->getValue('prompt') === 'after' && $fresh->getValue('start_prompt') === 'start', 'Regeneration preserves disabled start and after selections');
$remember->invoke($form, 1, ['style_prompt' => '', 'prompt' => ['table' => '']]);
$fresh = new FormState();
$restore->invoke($form, $fresh, 1);
$check($fresh->getValue('style_prompt') === '' && $fresh->getValue('prompt') === '', 'Explicitly clearing selectors is remembered');
$other = new FormState();
$restore->invoke($form, $other, 2);
$check($other->getValues() === [], 'Selections do not leak into another Studio session');
$remember->invoke($form, 1, ['style_prompt' => 'style', 'prompt' => 'after'], 9);
$fresh = new FormState();
$restore->invoke($form, $fresh, 1, 9);
$check($fresh->getValue(['video_regeneration', 'style_prompt']) === 'style' && $fresh->getValue(['video_regeneration', 'prompt']) === 'after', 'Video regeneration restores its nested prompt selectors');
$remember->invoke($form, 1, ['style_prompt' => 'missing']);
$fresh = new FormState();
$restore->invoke($form, $fresh, 1);
$check($fresh->getValue('style_prompt') === '', 'Deleted prompt selections are safely cleared');

$check($resolver->join($video->invoke($form, $turn, ['prompt' => 'after'])) === "A lighthouse\n\nAt sunset\n\nWatercolour", 'Video regeneration ignores a submitted After prompt and retains subject and style');

$state = new FormState();
$state->setValues(['output_type' => 'video', 'start_prompt' => 'start', 'prompt_start' => 'At sunset', 'style_prompt' => 'style', 'prompt' => 'after']);
$check($method->invoke($form, $state) === "A lighthouse\n\nAt sunset\n\nWatercolour", 'New video requests ignore After prompt while retaining Start, additional instructions and Style');
$state->setValue('output_type', 'image');
$check($method->invoke($form, $state) === $original, 'Switching back to image generation still applies After prompt');
$state->set('session_id', 1);
$state->setValues(['output_type' => 'video', 'regenerate_with_new_settings' => TRUE, 'source_turn_id' => 2, 'style_prompt' => 'new_style', 'prompt' => 'after']);
$check($method->invoke($form, $state) === "A lighthouse\n\nAt sunset\n\nPencil sketch", 'Regenerate with settings removes the inherited image After prompt from video');
$legacy = $resolver->regenerateParts("Subject\n\nHigh detail", [], 'style');
$check($resolver->join($resolver->withoutAfterPrompt($legacy, TRUE)) === "Subject\n\nWatercolour", 'Legacy video regeneration removes an exact known finishing-prompt suffix');
$check($resolver->join($resolver->withoutAfterPrompt(['start' => 'High detail mountains', 'style' => '', 'after' => ''], TRUE)) === 'High detail mountains', 'Legacy cleanup preserves text that is not a complete finishing-prompt suffix');
