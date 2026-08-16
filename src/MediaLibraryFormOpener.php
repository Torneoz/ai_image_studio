<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Session\AccountInterface;
use Drupal\media_library\MediaLibraryOpenerInterface;
use Drupal\media_library\MediaLibraryState;

/**
 * Returns Media Library selections to the Studio form element.
 */
final class MediaLibraryFormOpener implements MediaLibraryOpenerInterface {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(MediaLibraryState $state, AccountInterface $account): AccessResult {
    $parameters = $state->getOpenerParameters();
    return AccessResult::allowedIf(!empty($parameters['widget_id']))
      ->addCacheContexts(['url.query_args']);
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectionResponse(MediaLibraryState $state, array $selected_ids): AjaxResponse {
    $widget_id = (string) ($state->getOpenerParameters()['widget_id'] ?? '');
    $selector = sprintf('[data-ai-image-studio-media-value="%s"]', $widget_id);
    $update_selector = sprintf('[data-ai-image-studio-media-update="%s"]', $widget_id);

    return (new AjaxResponse())
      ->addCommand(new InvokeCommand($selector, 'val', [implode(',', $selected_ids)]))
      ->addCommand(new InvokeCommand($update_selector, 'trigger', ['mousedown']))
      ->addCommand(new CloseModalDialogCommand(TRUE, '#modal-media-library'));
  }

}
