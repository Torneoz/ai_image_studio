(function (Drupal, once) {
  Drupal.behaviors.aiNarrationCharacterCounter = {
    attach(context) {
      once('ai-narration-character-counter', '[data-ai-narration-text]', context).forEach((textarea) => {
        const counter = document.querySelector('[data-ai-narration-character-count]');
        if (!counter) {
          return;
        }
        const update = () => {
          counter.textContent = String(Array.from(textarea.value).length);
        };
        textarea.addEventListener('input', update);
        update();
      });
    },
  };
}(Drupal, once));
