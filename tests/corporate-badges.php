<?php

/**
 * @file
 * In-memory corporate badge regression checks; run with drush php:script.
 */

use Drupal\ai_image_studio\Form\SettingsForm;
use Drupal\ai_image_studio\Form\StudioForm;
use Drupal\ai_image_studio\Service\BadgePolicy;
use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\UserSession;
use Symfony\Component\EventDispatcher\EventDispatcher;

$check = static function (bool $pass, string $message): void {
  if (!$pass) {
    throw new RuntimeException($message);
  }
  print "PASS: $message\n";
};
$config = \Drupal::config('ai_image_studio.settings')->getRawData();
$config['require_ai_badges'] = FALSE;
$config['default_show_ai_badge'] = TRUE;
$check(BadgePolicy::apply([], $config)['show_ai_badge'] === TRUE, 'Badges default to enabled');
$check(BadgePolicy::apply(['show_ai_badge' => FALSE], $config)['show_ai_badge'] === FALSE, 'Editors may remove badges when corporate policy is off');
$controls = BadgePolicy::requestControls($config);
$check(!isset($controls['show_ai_badge']['#disabled']), 'Unlocked request controls are editable');
$config['require_ai_badges'] = TRUE;
foreach ([FALSE, TRUE] as $video) {
  $result = BadgePolicy::apply(['show_ai_badge' => FALSE, 'ai_badge_text' => '', 'ai_badge_class' => 'hide', 'duration' => 8], $config, $video);
  $check($result['show_ai_badge'] === TRUE && $result['ai_badge_text'] !== '' && $result['ai_badge_class'] === $config['default_ai_badge_class'] && $result['duration'] === 8, 'Locked requests reject badge removal and hidden/blank badge overrides, retaining other settings');
}
$controls = BadgePolicy::requestControls($config);
$check($controls['#open'] === FALSE && $controls['show_ai_badge']['#disabled'] && $controls['show_ai_badge']['#default_value'], 'Locked fieldset remains collapsed with a checked disabled checkbox');
$check((string) $controls['#description'] === 'Badge removal is not possible', 'Locked request displays the requested explanation');

$storage = new MemoryStorage();
$storage->write('ai_image_studio.settings', $config);
$dispatcher = new EventDispatcher();
$factory = new ConfigFactory($storage, $dispatcher, \Drupal::service('config.typed'));
$dispatcher->addSubscriber($factory);
$generator = clone \Drupal::service('ai_image_studio.generator');
// The production service is readonly; create a test instance with its other dependencies.
$reflection = new ReflectionClass(ImageGenerator::class);
$arguments = [];
foreach ($reflection->getConstructor()->getParameters() as $parameter) {
  $arguments[] = $parameter->getName() === 'configFactory'
    ? $factory
    : $reflection->getProperty($parameter->getName())->getValue($generator);
}
$generator = $reflection->newInstanceArgs($arguments);
$check($generator->badgesRequired() && $generator->badgeSettings(['show_ai_badge' => FALSE])['show_ai_badge'], 'Shared generator enforces corporate configuration');
$studio = StudioForm::create(\Drupal::getContainer());
(new ReflectionProperty(StudioForm::class, 'generator'))->setValue($studio, $generator);
foreach (['generationControls', 'videoControls'] as $method) {
  $controls = (new ReflectionMethod(StudioForm::class, $method))->invoke($studio, ['show_ai_badge' => FALSE, 'ai_badge_text' => '']);
  foreach ($controls['badges'] as $key => $control) {
    if (!str_starts_with($key, '#')) {
      $check(!empty($control['#disabled']), "$method: $key is disabled");
    }
  }
}

$account_proxy = \Drupal::currentUser();
$original_account = $account_proxy->getAccount();
try {
  foreach ([FALSE, TRUE] as $allowed) {
    $account_proxy->setAccount(new class($allowed) extends UserSession {
      public function __construct(private bool $allowed) {}

      public function hasPermission($permission): bool {
        return $permission === 'edit image studio corporate' && $this->allowed;
      }
    });
    $settings_form = new SettingsForm($factory, \Drupal::service('config.typed'), \Drupal::service('entity_type.bundle.info'), \Drupal::service('entity_field.manager'), $generator);
    $state = new FormState();
    $form = $settings_form->buildForm([], $state);
    $check($form['corporate']['#access'] === $allowed, 'Corporate fieldset access follows the dedicated permission');
    $state->setValues($config + ['require_ai_badges' => FALSE]);
    $state->setValue('require_ai_badges', FALSE);
    $settings_form->submitForm($form, $state);
    $check((bool) $factory->get('ai_image_studio.settings')->get('require_ai_badges') === !$allowed, $allowed ? 'Authorized user can turn off the corporate requirement' : 'Forged submission cannot change the corporate requirement');
  }
}
finally {
  $account_proxy->setAccount($original_account);
}
