<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\ai_image_studio\Service\ImageGenerator;
use Drupal\ai_storyboard\Service\ScriptBreakdown;
use Drupal\ai_storyboard\Service\StoryboardManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates storyboards and presents the shot workspace. */
final class StoryboardForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ScriptBreakdown $breakdown,
    private readonly StoryboardManager $manager,
    private readonly ImageGenerator $imageGenerator,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ai_storyboard.breakdown'),
      $container->get('ai_storyboard.manager'),
      $container->get('ai_image_studio.generator'),
      $container->get('file_url_generator'),
    );
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'ai_storyboard_form';
  }

  /**
   *
   */
  public function title(object $ai_storyboard): string {
    return (string) $ai_storyboard->label();
  }

  /**
   * {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state, ?object $ai_storyboard = NULL): array {
    $form_state->set('storyboard_id', $ai_storyboard?->id());
    $form['#attached']['library'][] = 'ai_storyboard/workspace';
    $form['project'] = ['#type' => 'details', '#title' => $this->t('Project and script'), '#open' => $ai_storyboard === NULL];
    $form['project']['title'] = ['#type' => 'textfield', '#title' => $this->t('Title'), '#required' => TRUE, '#maxlength' => 255, '#default_value' => $ai_storyboard?->label()];
    $form['project']['creative_brief'] = ['#type' => 'textarea', '#title' => $this->t('Creative brief'), '#rows' => 3, '#default_value' => $ai_storyboard?->get('creative_brief')->value, '#description' => $this->t('Audience, objective, tone, runtime, platform, and production constraints.')];
    $form['project']['script'] = ['#type' => 'textarea', '#title' => $this->t('Script'), '#required' => TRUE, '#rows' => 14, '#default_value' => $ai_storyboard?->get('script')->value];
    $form['project']['visual_style'] = ['#type' => 'textfield', '#title' => $this->t('Visual style'), '#default_value' => $ai_storyboard?->get('visual_style')->value ?? 'cinematic storyboard sketch', '#maxlength' => 255];
    $form['project']['after_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('After prompt'),
      '#rows' => 3,
      '#default_value' => $ai_storyboard?->get('after_prompt')->value,
      '#description' => $this->t('Appended to every frame prompt. Use it for a consistent final instruction, such as “Turn robots into proper humanoid robots according to current market trends.”'),
    ];
    $form['project']['aspect_ratio'] = ['#type' => 'select', '#title' => $this->t('Aspect ratio'), '#options' => ['16:9' => '16:9 landscape', '9:16' => '9:16 portrait', '1:1' => '1:1 square', '4:3' => '4:3 classic', '2.39:1' => '2.39:1 anamorphic'], '#default_value' => $ai_storyboard?->get('aspect_ratio')->value ?? '16:9'];
    $chat_options = $this->breakdown->getModelOptions();
    $image_options = $this->imageGenerator->getModelOptions('text_to_image');
    $form['project']['chat_model'] = ['#type' => 'select', '#title' => $this->t('Script breakdown model'), '#options' => $chat_options, '#required' => TRUE, '#default_value' => $ai_storyboard?->get('chat_model')->value ?: (string) array_key_first($chat_options)];
    $form['project']['image_model'] = ['#type' => 'select', '#title' => $this->t('Frame generation model'), '#options' => $image_options, '#required' => TRUE, '#default_value' => $ai_storyboard?->get('image_model')->value ?: (string) array_key_first($image_options)];
    $form['project']['status'] = ['#type' => 'select', '#title' => $this->t('Status'), '#options' => ['draft' => $this->t('Draft'), 'in_review' => $this->t('In review'), 'approved' => $this->t('Approved')], '#default_value' => $ai_storyboard?->get('status')->value ?? 'draft'];
    $form['prompt_bibles'] = [
      '#type' => 'details',
      '#title' => $this->t('Continuity Prompts'),
      '#open' => FALSE,
      '#description' => $this->t('These reusable directions are sent with every frame prompt. The script breakdown can expand them; edit and save them at any time.'),
    ];
    $form['prompt_bibles']['continuity_bible'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Continuity bible'),
      '#default_value' => $ai_storyboard?->get('continuity_bible')->value,
      '#rows' => 7,
      '#description' => $this->t('Locations, props, screen direction, geography, palette, lighting, weather, and time-of-day rules.'),
    ];
    $form['prompt_bibles']['character_bible'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Character bible'),
      '#default_value' => $ai_storyboard?->get('character_bible')->value,
      '#rows' => 7,
      '#description' => $this->t('One canonical visual description per character: appearance, age, wardrobe, distinguishing features, and relationships.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['prompt_bibles']['audio_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Audio prompt'),
      '#default_value' => $ai_storyboard?->get('audio_prompt')->value,
      '#rows' => 4,
      '#description' => $this->t('Music, ambience, sound effects, dialogue, and voice direction to include in every video generation prompt.'),
    ];
    $form['actions']['save'] = ['#type' => 'submit', '#value' => $ai_storyboard ? $this->t('Save project') : $this->t('Create and break down script'), '#button_type' => 'primary', '#submit' => ['::saveProject']];
    if ($ai_storyboard) {
      $shot_count = $this->entityTypeManager->getStorage('ai_storyboard_shot')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('storyboard_id', $ai_storyboard->id())
        ->count()
        ->execute();
      $form['storyboard_actions'] = [
        '#type' => 'container',
        '#weight' => 10,
        '#attributes' => [
          'class' => ['ai-storyboard-project-actions'],
          'aria-labelledby' => 'ai-storyboard-project-actions-title',
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('Storyboard actions'),
          '#attributes' => ['id' => 'ai-storyboard-project-actions-title'],
        ],
        'description' => [
          '#markup' => '<p>' . $this->t('Generate frames, rebuild the shot plan, or export the current storyboard.') . '</p>',
        ],
        'buttons' => [
          '#type' => 'actions',
          '#attributes' => ['class' => ['ai-storyboard-project-actions__buttons']],
        ],
      ];
      $form['storyboard_actions']['buttons']['generate_all'] = [
        '#type' => 'link',
        '#title' => $this->t('Generate all frames'),
        '#url' => Url::fromRoute('ai_storyboard.generate_all', [
          'ai_storyboard' => $ai_storyboard->id(),
        ]),
        '#attributes' => ['class' => ['button', 'button--primary']],
        '#access' => $shot_count > 0
          && $this->currentUser()->hasPermission('run ai storyboard bulk generation'),
      ];
      $form['storyboard_actions']['buttons']['generate_video_sequences'] = [
        '#type' => 'link',
        '#title' => $this->t('Generate video sequences'),
        '#url' => Url::fromRoute('ai_storyboard.generate_video_sequences', [
          'ai_storyboard' => $ai_storyboard->id(),
        ]),
        '#attributes' => ['class' => ['button']],
        '#access' => $shot_count > 0
          && $this->currentUser()->hasPermission('run ai storyboard bulk generation'),
      ];
      $form['storyboard_actions']['buttons']['breakdown'] = [
        '#type' => 'submit',
        '#value' => $this->t('Rebuild shots from script'),
        '#submit' => ['::rebuildShots'],
      ];
      $form['storyboard_actions']['buttons']['download_images'] = [
        '#type' => 'link',
        '#title' => $this->t('Download images'),
        '#url' => Url::fromRoute('ai_storyboard.download_images', [
          'ai_storyboard' => $ai_storyboard->id(),
        ]),
        '#attributes' => ['class' => ['button']],
      ];
      $form['storyboard_actions']['buttons']['download_video'] = [
        '#type' => 'link',
        '#title' => $this->t('Download MP4'),
        '#url' => Url::fromRoute('ai_storyboard.download_video', [
          'ai_storyboard' => $ai_storyboard->id(),
        ]),
        '#attributes' => ['class' => ['button']],
      ];
      $delete = Link::fromTextAndUrl(
        $this->t('Delete'),
        $ai_storyboard->toUrl('delete-form'),
      )->toRenderable();
      $delete['#attributes']['class'] = ['button', 'button--danger'];
      $form['actions']['delete'] = $delete;
      $this->buildWorkspace($form, $ai_storyboard);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveProject($form, $form_state);
  }

  /**
   * Saves without replacing existing shots. */
  public function saveProject(array &$form, FormStateInterface $form_state): void {
    $board = $this->loadOrCreate($form_state);
    if ($form_state->get('storyboard_id')) {
      $this->messenger()->addStatus($this->t('The storyboard project has been saved.'));
      $form_state->setRedirect('entity.ai_storyboard.canonical', ['ai_storyboard' => $board->id()]);
      return;
    }
    $this->runBreakdown($board, $form_state);
  }

  /**
   * Saves and deliberately replaces the generated breakdown. */
  public function rebuildShots(array &$form, FormStateInterface $form_state): void {
    $this->runBreakdown($this->loadOrCreate($form_state), $form_state);
  }

  /**
   *
   */
  private function loadOrCreate(FormStateInterface $form_state): object {
    $storage = $this->entityTypeManager->getStorage('ai_storyboard');
    $board = $form_state->get('storyboard_id') ? $storage->load($form_state->get('storyboard_id')) : $storage->create(['uid' => $this->currentUser()->id()]);
    foreach (['title', 'creative_brief', 'script', 'visual_style', 'after_prompt', 'aspect_ratio', 'chat_model', 'image_model', 'status', 'continuity_bible', 'character_bible'] as $field) {
      $board->set($field, $form_state->getValue($field));
    }
    $board->set('audio_prompt', (string) $form_state->getValue('audio_prompt'));
    $board->save();
    return $board;
  }

  /**
   *
   */
  private function runBreakdown(object $board, FormStateInterface $form_state): void {
    try {
      $result = $this->breakdown->breakdown(
        (string) $board->get('script')->value,
        (string) $board->get('chat_model')->value,
        (string) $board->get('creative_brief')->value,
        (string) $board->get('continuity_bible')->value,
        (string) $board->get('character_bible')->value,
      );
      $count = $this->manager->replaceShots($board, $result);
      $this->messenger()->addStatus($this->formatPlural($count, 'Created 1 storyboard shot.', 'Created @count storyboard shots.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('entity.ai_storyboard.canonical', ['ai_storyboard' => $board->id()]);
  }

  /**
   *
   */
  private function buildWorkspace(array &$form, object $board): void {
    $shot_storage = $this->entityTypeManager->getStorage('ai_storyboard_shot');
    $ids = $shot_storage->getQuery()->accessCheck(FALSE)->condition('storyboard_id', $board->id())->sort('position')->execute();
    $shots = $shot_storage->loadMultiple($ids);
    $total = 0.0;
    $form['workspace'] = ['#type' => 'container', '#attributes' => ['class' => ['ai-storyboard-workspace']], '#weight' => 20];
    if (!$shots) {
      $form['workspace']['empty'] = ['#markup' => '<p>' . $this->t('No shots yet. Save the script, then rebuild its shot breakdown.') . '</p>'];
      return;
    }
    foreach ($shots as $shot) {
      $total += (float) $shot->get('duration')->value;
      $turn = $shot->get('studio_turn_id')->entity;
      $image = $turn?->get('image')->entity;
      $video = $shot->get('video_turn_id')->entity?->get('video')->entity;
      $frame = $image
        ? ['#theme' => 'image', '#uri' => $this->fileUrlGenerator->generateAbsoluteString($image->getFileUri()), '#alt' => $shot->label()]
        : ['#markup' => '<div class="ai-storyboard-shot__placeholder">' . $this->t('Frame not generated') . '</div>'];
      $edit_link = Link::fromTextAndUrl($this->t('Edit shot'), Url::fromRoute('entity.ai_storyboard_shot.edit_form', ['ai_storyboard' => $board->id(), 'ai_storyboard_shot' => $shot->id()]))->toRenderable();
      $edit_link['#attributes']['class'] = ['button', 'button--small'];
      $generate_link = Link::fromTextAndUrl($image ? $this->t('Regenerate frame') : $this->t('Generate frame'), Url::fromRoute('ai_storyboard.generate_shot', ['ai_storyboard' => $board->id(), 'ai_storyboard_shot' => $shot->id()]))->toRenderable();
      $generate_link['#attributes']['class'] = ['button', 'button--primary', 'button--small'];
      $form['workspace']['shot_' . $shot->id()] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-storyboard-shot']],
        'frame' => ['#type' => 'container', '#attributes' => ['class' => ['ai-storyboard-shot__frame']], 'image' => $frame],
        'body' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-storyboard-shot__body']],
          'heading' => ['#markup' => '<h3>' . $this->t('Scene @scene · Shot @shot — @title', ['@scene' => $shot->get('scene_number')->value, '@shot' => $shot->get('shot_number')->value, '@title' => $shot->label()]) . '</h3>'],
          'technical' => ['#markup' => '<p class="ai-storyboard-shot__technical">' . implode(' · ', array_filter([$shot->get('shot_size')->value, $shot->get('camera_angle')->value, $shot->get('camera_move')->value, $shot->get('lens')->value, $shot->get('duration')->value . 's'])) . '</p>'],
          'action' => ['#markup' => '<p><strong>' . $this->t('Action') . ':</strong> ' . nl2br(htmlspecialchars((string) $shot->get('action')->value)) . '</p>'],
          'dialogue' => ['#markup' => $shot->get('dialogue')->value ? '<p><strong>' . $this->t('Dialogue') . ':</strong> ' . nl2br(htmlspecialchars((string) $shot->get('dialogue')->value)) . '</p>' : ''],
          'links' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-storyboard-shot__actions']],
            'edit' => $edit_link,
            'generate' => $generate_link,
            'sequence' => [
              '#type' => 'link',
              '#title' => $this->t('Generate Keyframe Sequences'),
              '#url' => Url::fromRoute('ai_storyboard.generate_shot_sequence', [
                'ai_storyboard' => $board->id(),
                'ai_storyboard_shot' => $shot->id(),
              ]),
              '#attributes' => ['class' => ['button', 'button--small']],
              '#access' => (bool) $image && $this->currentUser()->hasPermission('run ai storyboard bulk generation'),
            ],
          ],
          'video' => $video ? [
            '#type' => 'html_tag',
            '#tag' => 'video',
            '#attributes' => [
              'src' => $this->fileUrlGenerator->generateAbsoluteString($video->getFileUri()),
              'controls' => 'controls',
              'preload' => 'metadata',
              'playsinline' => 'playsinline',
              'class' => ['ai-storyboard-shot__video'],
            ],
          ] : [],
        ],
      ];
    }
    $form['workspace']['summary'] = ['#markup' => '<p class="ai-storyboard-summary">' . $this->formatPlural(count($shots), '1 shot', '@count shots') . ' · ' . $this->t('@seconds seconds estimated runtime', ['@seconds' => round($total, 1)]) . '</p>', '#weight' => -10];
  }

}
