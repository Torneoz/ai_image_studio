<?php

declare(strict_types=1);

namespace Drupal\ai_storyboard\Form;

use Drupal\ai_storyboard\Service\StoryboardManager;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms billable generation of one storyboard frame. */
final class GenerateShotForm extends ConfirmFormBase {

  private object $storyboard;
  private object $shot;

  public function __construct(private readonly StoryboardManager $manager) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ai_storyboard.manager'));
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'ai_storyboard_generate_shot_form';
  }

  /**
   *
   */
  public function getQuestion(): string {
    return (string) $this->t('Generate a frame for “@shot”?', ['@shot' => $this->shot->label()]);
  }

  /**
   *
   */
  public function getDescription(): string {
    return (string) $this->t('This sends the shot prompt and continuity bible to the selected image provider and may incur provider charges. Regeneration keeps the earlier Image Studio turn in its history.');
  }

  /**
   *
   */
  public function getConfirmText(): string {
    return (string) $this->t('Generate frame');
  }

  /**
   *
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.ai_storyboard.canonical', ['ai_storyboard' => $this->storyboard->id()]);
  }

  /**
   * {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state, ?object $ai_storyboard = NULL, ?object $ai_storyboard_shot = NULL): array {
    if (!$ai_storyboard_shot || (int) $ai_storyboard_shot->get('storyboard_id')->target_id !== (int) $ai_storyboard?->id()) {
      throw new NotFoundHttpException();
    }
    $this->storyboard = $ai_storyboard;
    $this->shot = $ai_storyboard_shot;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $turn = $this->manager->generateShot($this->storyboard, $this->shot);
      if ($turn->get('status')->value === 'completed') {
        $this->messenger()->addStatus($this->t('The storyboard frame has been generated.'));
      }
      else {
        $this->messenger()->addWarning($this->t('Generation finished with status: @status.', ['@status' => $turn->get('status')->value]));
      }
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Frame generation failed: @message', ['@message' => $e->getMessage()]));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
