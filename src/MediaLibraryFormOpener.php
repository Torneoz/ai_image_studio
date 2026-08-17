<?php

declare(strict_types=1);

namespace Drupal\ai_image_studio;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryOpenerInterface;
use Drupal\media_library\MediaLibraryState;

/**
 * Returns Media Library selections to the Studio form element.
 */
final class MediaLibraryFormOpener implements MediaLibraryOpenerInterface {

  use StringTranslationTrait;

  /**
   * Constructs the Media Library form opener.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

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
    $selection_selector = sprintf('[data-ai-image-studio-media-selection="%s"]', $widget_id);
    $open_selector = sprintf('[data-ai-image-studio-media-open="%s"]', $widget_id);
    $media = $this->entityTypeManager->getStorage('media')->load(reset($selected_ids));
    $preview = $media instanceof MediaInterface
      ? [
        '#type' => 'container',
        '#attributes' => ['class' => ['media-library-item', 'media-library-item--grid']],
        'media' => $this->entityTypeManager->getViewBuilder('media')->view($media, 'media_library'),
      ]
      : ['#markup' => '<p>' . $this->t('No media item selected.') . '</p>'];

    return (new AjaxResponse())
      ->addCommand(new InvokeCommand($selector, 'val', [implode(',', $selected_ids)]))
      ->addCommand(new HtmlCommand($selection_selector, $preview))
      ->addCommand(new InvokeCommand($open_selector, 'val', [(string) $this->t('Replace media')]))
      ->addCommand(new CloseModalDialogCommand(TRUE, '#modal-media-library'));
  }

}
