<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Form;

use Drupal\ai_image_studio\Service\SessionReplayManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-renders a session's logical requests in a new session.
 */
final class SessionReplayForm extends FormBase {

  /**
   * The session being replayed.
   */
  private ?object $sourceSession = NULL;

  /**
   * Constructs the replay form.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SessionReplayManager $replayManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ai_image_studio.session_replay'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_image_studio_session_replay';
  }

  /**
   * Returns the replay form title.
   */
  public function title(object $ai_image_studio_session): string {
    return (string) $this->t('Re-render @title', ['@title' => $ai_image_studio_session->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?object $ai_image_studio_session = NULL): array {
    $this->sourceSession = $ai_image_studio_session;
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Creates a new session and replays each original request in creation order. Chained requests use the newly generated result from the replay.') . '</p>',
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New session title'),
      '#default_value' => $this->t('@title — re-render', ['@title' => $ai_image_studio_session?->label()]),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['use_default_models'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use the currently configured default model for each operation'),
      '#description' => $this->t('Otherwise each request uses its original provider and model.'),
    ];
    $form['overrides'] = [
      '#type' => 'details',
      '#title' => $this->t('Optional setting overrides'),
      '#description' => $this->t('Leave a value unchanged to preserve the setting captured with each original request.'),
      '#tree' => TRUE,
      '#open' => TRUE,
    ];
    $form['overrides']['aspect_ratio'] = [
      '#type' => 'select',
      '#title' => $this->t('Aspect ratio'),
      '#options' => [
        '' => $this->t('- Preserve original -'),
        'auto' => $this->t('Automatic'),
        '1:1' => '1:1',
        '16:9' => '16:9',
        '9:16' => '9:16',
        '4:3' => '4:3',
        '3:4' => '3:4',
        '3:2' => '3:2',
        '2:3' => '2:3',
      ],
    ];
    $form['overrides']['image_resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Image resolution'),
      '#options' => [
        '' => $this->t('- Preserve original -'),
        'auto' => $this->t('Automatic'),
        '1k' => $this->t('1K'),
        '2k' => $this->t('2K'),
      ],
    ];
    $form['overrides']['video_resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Video resolution'),
      '#options' => [
        '' => $this->t('- Preserve original -'),
        '480p' => $this->t('480p'),
        '720p' => $this->t('720p'),
        '1080p' => $this->t('1080p'),
      ],
    ];
    $form['overrides']['quality'] = [
      '#type' => 'select',
      '#title' => $this->t('Image quality'),
      '#options' => [
        '' => $this->t('- Preserve original -'),
        'medium' => $this->t('Medium'),
        'low' => $this->t('Low'),
      ],
    ];
    $form['overrides']['variations'] = [
      '#type' => 'select',
      '#title' => $this->t('Image variations'),
      '#options' => ['' => $this->t('- Preserve original -')] + array_combine(range(1, 10), range(1, 10)),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start re-render'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $source = $this->sourceSession;
    if ($source === NULL || !$source->access('update')) {
      $this->messenger()->addError($this->t('The source session is unavailable.'));
      return;
    }
    $target = $this->entityTypeManager->getStorage('ai_image_studio_session')->create([
      'title' => trim((string) $form_state->getValue('title')),
      'uid' => $this->currentUser()->id(),
      'status' => 'active',
    ]);
    $target->save();
    $values = (array) $form_state->getValue('overrides');
    $overrides = [
      'aspect_ratio' => $values['aspect_ratio'] ?? '',
      'quality' => $values['quality'] ?? '',
      'variations' => $values['variations'] ?? '',
      'image_resolution' => $values['image_resolution'] ?? '',
      'video_resolution' => $values['video_resolution'] ?? '',
    ];
    $this->replayManager->start($source, $target, $overrides, (bool) $form_state->getValue('use_default_models'));
    $this->messenger()->addStatus($this->t('The re-render was started in a new session.'));
    $form_state->setRedirect('entity.ai_image_studio_session.canonical', ['ai_image_studio_session' => $target->id()]);
  }

}
