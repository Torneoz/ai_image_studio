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
    $breakdown_status = $ai_storyboard
      ? \Drupal::keyValue('ai_storyboard.breakdown')->get($ai_storyboard->id(), [])
      : [];
    $pending = in_array($breakdown_status['status'] ?? '', ['queued', 'processing'], TRUE);
    if ($breakdown_status) {
      $form['breakdown_progress'] = [
        '#weight' => -100,
        '#type' => 'item',
        '#title' => $this->t('Script breakdown'),
        '#plain_text' => $breakdown_status['message'] ?? $breakdown_status['status'],
      ];
    }
    $form['#attached']['library'][] = 'ai_storyboard/workspace';
    $form['project'] = ['#type' => 'details', '#title' => $this->t('Project and script'), '#open' => $ai_storyboard === NULL];
    $form['project']['title'] = ['#type' => 'textfield', '#title' => $this->t('Title'), '#required' => TRUE, '#maxlength' => 255, '#default_value' => $ai_storyboard?->label()];
    $form['project']['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#default_value' => $ai_storyboard?->get('machine_name')->value,
      '#maxlength' => 100,
      '#required' => TRUE,
      '#machine_name' => [
        'source' => ['project', 'title'],
        'exists' => [$this, 'machineNameExists'],
      ],
      '#description' => $this->t('A unique project identifier using lowercase letters, numbers, and underscores.'),
    ];
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
      $form['script_exports'] = [
        '#type' => 'details', '#title' => $this->t('Export'),
        '#open' => FALSE, '#weight' => 90,
      ];
      $form['script_exports']['description'] = ['#markup' => '<p>' . $this->t('Exports the saved script. Save edits first. JSON also includes project, scene, shot and pinned character data (file references only). Other formats recognize basic screenplay structure; unstructured prose remains action text. Revisions, pagination and advanced Fountain markup are not converted.') . '</p>'];
      foreach (\Drupal\ai_storyboard\Service\ScriptExporter::FORMATS as $format => $label) {
        $form['script_exports'][$format] = [
          '#type' => 'link', '#title' => $label,
          '#url' => Url::fromRoute('ai_storyboard.download_script', ['ai_storyboard' => $ai_storyboard->id(), 'format' => $format]),
          '#attributes' => ['class' => ['button']],
        ];
      }
      $form['narrative'] = ['#type' => 'details', '#title' => $this->t('Scenes and characters'), '#open' => FALSE, '#weight' => 9];
      $form['narrative']['description'] = ['#markup' => '<p>' . $this->t('Reuse shared Locations and Characters by pinning a library version. Scene conditions and project character overrides apply only to this production. Library changes are adopted explicitly, not automatically.') . '</p>'];
      foreach (['ai_storyboard_scene' => 'Scenes', 'ai_storyboard_cast' => 'Project characters'] as $type => $label) {
        $storage = $this->entityTypeManager->getStorage($type);
        $ids = $storage->getQuery()->accessCheck(TRUE)->condition('storyboard_id', $ai_storyboard->id())->sort($type === 'ai_storyboard_scene' ? 'scene_number' : 'title')->execute();
        $items = [];
        foreach ($storage->loadMultiple($ids) as $record) {
          $items[] = $record->toLink()->toRenderable();
        }
        $form['narrative'][$type] = ['#type' => 'container'];
        $form['narrative'][$type]['list'] = ['#theme' => 'item_list', '#title' => $this->t($label), '#items' => $items, '#empty' => $this->t('None yet.')];
        $form['narrative'][$type]['add'] = ['#type' => 'link', '#title' => $type === 'ai_storyboard_scene' ? $this->t('Add scene') : $this->t('Add project character'), '#url' => Url::fromRoute('entity.' . $type . '.add_form', [], ['query' => ['project' => $ai_storyboard->id()]]), '#attributes' => ['class' => ['button']]];
      }
      foreach (['ai_storyboard_location' => 'Location library', 'ai_storyboard_character' => 'Character library'] as $type => $label) {
        $form['narrative'][$type] = ['#type' => 'link', '#title' => $this->t($label), '#url' => Url::fromRoute('entity.' . $type . '.collection'), '#attributes' => ['class' => ['button']]];
      }
      $this->buildWorkspace($form, $ai_storyboard);
    }
    if ($pending) {
      foreach (['project', 'prompt_bibles', 'actions', 'storyboard_actions'] as $section) {
        if (isset($form[$section])) {
          $form[$section]['#disabled'] = TRUE;
        }
      }
      $form['#attached']['html_head'][] = [
        ['#tag' => 'meta', '#attributes' => ['http-equiv' => 'refresh', 'content' => '10']],
        'ai_storyboard_breakdown_refresh',
      ];
    }
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveProject($form, $form_state);
  }

  /**
   * Prevents stale forms from overwriting an active breakdown.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $id = $form_state->get('storyboard_id');
    $status = $id ? \Drupal::keyValue('ai_storyboard.breakdown')->get($id, []) : [];
    if (in_array($status['status'] ?? '', ['queued', 'processing'], TRUE)) {
      $form_state->setErrorByName('script', $this->t('Wait for the active script breakdown to finish before saving changes.'));
    }
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
    $board->set('machine_name', (string) $form_state->getValue('machine_name'));
    $board->save();
    return $board;
  }

  /**
   * Checks machine-name uniqueness, excluding the current storyboard.
   */
  public function machineNameExists(string $value, array $element, FormStateInterface $form_state): bool {
    $query = $this->entityTypeManager->getStorage('ai_storyboard')->getQuery()
      ->accessCheck(FALSE)->condition('machine_name', $value);
    if ($form_state->get('storyboard_id')) {
      $query->condition('id', $form_state->get('storyboard_id'), '<>');
    }
    return (bool) $query->count()->execute();
  }

  /**
   *
   */
  private function runBreakdown(object $board, FormStateInterface $form_state): void {
    $state = \Drupal::keyValue('ai_storyboard.breakdown');
    $status = $state->get($board->id(), []);
    if (!in_array($status['status'] ?? '', ['queued', 'processing'], TRUE)) {
      $state->set($board->id(), [
        'status' => 'queued',
        'message' => (string) $this->t('Queued: generating shots and continuity bibles. This page refreshes automatically.'),
      ]);
      \Drupal::queue('ai_storyboard_breakdown')->createItem(['storyboard_id' => (int) $board->id()]);
      \Drupal::service('ai_image_studio.observability')->record('breakdown.queued', [
        'storyboard_id' => (int) $board->id(),
        'status' => 'queued',
        'model' => (string) $board->get('chat_model')->value,
      ], ['ai_image_studio', 'ai_storyboard']);
    }
    $this->messenger()->addStatus($this->t('Script breakdown queued. Your project has been saved.'));
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
    $form['workspace'] = ['#type' => 'details', '#title' => $this->t('Results'), '#open' => TRUE, '#attributes' => ['class' => ['ai-storyboard-workspace']], '#weight' => 20];
    if (!$shots) {
      $form['workspace']['empty'] = ['#markup' => '<p>' . $this->t('No shots yet. Save the script, then rebuild its shot breakdown.') . '</p>'];
      return;
    }
    foreach ($shots as $shot) {
      $total += (float) $shot->get('duration')->value;
      $turn = $shot->get('studio_turn_id')->entity;
      $image = $turn?->get('image')->entity;
      $video_turn = $shot->get('video_turn_id')->entity;
      $video = $video_turn?->get('video')->entity;
      $poster = $video_turn?->get('source_file_id')->entity ?? $image;
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
          'review_media' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->t('Draft — existing media has been retained. Review or regenerate it before exporting.'),
            '#access' => ($image || $video) && $shot->get('status')->value === 'draft',
          ],
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
              'poster' => $poster ? $this->fileUrlGenerator->generateAbsoluteString($poster->getFileUri()) : NULL,
              'controls' => 'controls',
              'preload' => 'metadata',
              'playsinline' => 'playsinline',
              'class' => ['ai-storyboard-shot__video'],
            ],
          ] : [],
        ],
      ];
      $card = &$form['workspace']['shot_' . $shot->id()];
      $body = $card['body'];
      $card = [
        '#type' => 'details',
        '#title' => $this->t('Scene @scene · Shot @shot — @title', ['@scene' => $shot->get('scene_number')->value, '@shot' => $shot->get('shot_number')->value, '@title' => $shot->label()]),
        '#open' => TRUE,
        '#attributes' => ['class' => ['ai-storyboard-shot']],
        'header' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-storyboard-shot__header']],
          'specs' => ['#type' => 'container', '#attributes' => ['class' => ['ai-storyboard-shot__specs']]],
        ],
        'media' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-storyboard-shot__media']],
          'frame' => [
            '#type' => 'container', '#attributes' => ['class' => ['ai-storyboard-shot__asset']],
            'label' => ['#markup' => '<div class="ai-storyboard-shot__media-label">' . $this->t('Keyframe') . '</div>'],
            'image' => $frame,
          ],
        ],
        'body' => [
          '#type' => 'container', '#attributes' => ['class' => ['ai-storyboard-shot__body']],
          'review_media' => $body['review_media'],
          'action' => $body['action'], 'dialogue' => $body['dialogue'],
        ],
        'actions' => $body['links'],
      ];
      foreach (['shot_size', 'camera_angle', 'camera_move', 'lens', 'duration'] as $field) {
        $value = (string) $shot->get($field)->value;
        if ($value !== '') {
          $card['header']['specs'][$field] = [
            '#type' => 'html_tag', '#tag' => 'span',
            '#value' => htmlspecialchars($value . ($field === 'duration' ? 's' : '')),
          ];
        }
      }
      if ($video) {
        $card['media']['video'] = [
          '#type' => 'container', '#attributes' => ['class' => ['ai-storyboard-shot__asset']],
          'label' => ['#markup' => '<div class="ai-storyboard-shot__media-label">' . $this->t('Video sequence') . '</div>'],
          'player' => $body['video'],
        ];
      }
      unset($card);
    }
    $form['workspace']['#title'] = $this->t('Results — @shots · @seconds seconds estimated runtime', ['@shots' => $this->formatPlural(count($shots), '1 shot', '@count shots'), '@seconds' => round($total, 1)]);
  }

}
