(function (Drupal, once) {
  Drupal.behaviors.aiNarrationAudioShare = {
    attach(context) {
      once('ai-narration-audio-share', '[data-ai-narration-share-url]', context).forEach((button) => {
        button.addEventListener('click', async () => {
          const url = button.dataset.aiNarrationShareUrl;
          const title = button.dataset.aiNarrationShareTitle || 'Generated narration audio';
          const shareData = {
            title,
            text: title,
            url,
          };
          try {
            if (navigator.share) {
              await navigator.share(shareData);
              return;
            }
          }
          catch (error) {
            // A user cancelling the native share sheet is not an error.
            if (error && error.name === 'AbortError') {
              return;
            }
          }
          try {
            await navigator.clipboard.writeText(url);
            Drupal.announce(Drupal.t('Audio link copied to the clipboard.'));
          }
          catch (error) {
            Drupal.announce(Drupal.t('Sharing is unavailable in this browser.'));
          }
        });
      });
    },
  };
}(Drupal, once));
