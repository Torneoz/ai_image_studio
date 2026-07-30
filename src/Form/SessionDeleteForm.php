<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Url;

/**
 * Confirms deletion of a Studio session.
 */
final class SessionDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.ai_image_studio_session.collection');
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl(): Url {
    return Url::fromRoute('entity.ai_image_studio_session.collection');
  }

}
