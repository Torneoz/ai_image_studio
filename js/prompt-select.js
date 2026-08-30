(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiImageStudioPromptSelect = {
    attach(context) {
      once('ai-image-studio-prompt-select', '.ai-image-studio-prompt-select', context)
        .forEach((select) => {
          let prompts = {};
          try {
            prompts = JSON.parse(select.dataset.promptTexts || '{}');
          }
          catch {
            prompts = {};
          }

          const preview = select.parentElement.querySelector(
            '.ai-image-studio-prompt-preview',
          );
          if (!preview) {
            return;
          }

          const updatePreview = () => {
            const prompt = prompts[select.value] || '';
            preview.textContent = prompt;
            preview.hidden = prompt === '';
          };

          select.addEventListener('change', updatePreview);
          updatePreview();
        });
    },
  };
})(Drupal, once);
