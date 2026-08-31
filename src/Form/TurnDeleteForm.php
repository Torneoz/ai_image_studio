<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Url;

/**
 * Confirms deletion of a Studio turn.
 */
final class TurnDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Delete this turn?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('This removes the generated result from the Studio session. Published Media is not deleted. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->sessionUrl();
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl(): Url {
    return $this->sessionUrl();
  }

  /**
   * Returns the parent session URL.
   */
  private function sessionUrl(): Url {
    return Url::fromRoute('entity.ai_image_studio_session.canonical', [
      'ai_image_studio_session' => $this->entity->get('session_id')->target_id,
    ]);
  }

}
