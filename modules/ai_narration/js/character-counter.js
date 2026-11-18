(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.aiNarrationCharacterCounter = {
    attach(context) {
      once('ai-narration-character-counter', '[data-ai-narration-text]', context).forEach((textarea) => {
        const update = () => {
          const counter = document.querySelector('[data-ai-narration-character-count]');
          if (counter) {
            counter.textContent = String(Array.from(textarea.value).length);
          }
        };
        textarea.addEventListener('input', update);
        update();
      });
      once('ai-narration-model-limit', '[data-ai-narration-model-select]', context).forEach((select) => {
        const updateLimit = () => {
          const limit = drupalSettings.aiNarration?.modelCharacterLimits?.[select.value];
          const label = document.querySelector('[data-ai-narration-limit-label]');
          if (label) {
            label.textContent = limit === null || limit === undefined
              ? 'No application limit is available for this provider.'
              : `Model limit: ${Number(limit).toLocaleString()} characters`;
          }
        };
        select.addEventListener('change', updateLimit);
        updateLimit();
      });
      once('ai-narration-single-player', '.ai-narration-result audio', context).forEach((player) => {
        player.addEventListener('play', () => {
          document.querySelectorAll('.ai-narration-result audio').forEach((other) => {
            if (other !== player && !other.paused) {
              other.pause();
            }
          });
        });
      });
    },
  };
}(Drupal, drupalSettings, once));
