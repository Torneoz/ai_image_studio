(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiImageStudioPromptSelect = {
    attach(context) {
      once('ai-image-studio-prompt-select', '.ai-image-studio-prompt-select', context)
        .forEach((select) => {
          let prompts = {};
          let editUrls = {};
          try {
            prompts = JSON.parse(select.dataset.promptTexts || '{}');
            editUrls = JSON.parse(select.dataset.promptEditUrls || '{}');
          }
          catch {
            prompts = {};
            editUrls = {};
          }

          const preview = select.parentElement.querySelector(
            '.ai-image-studio-prompt-preview',
          );
          if (!preview) {
            return;
          }
          const editLink = select.parentElement.querySelector(
            '.ai-image-studio-prompt-edit',
          );

          const updatePreview = () => {
            const prompt = prompts[select.value] || '';
            preview.textContent = prompt;
            preview.hidden = prompt === '';
            if (editLink) {
              const editUrl = editUrls[select.value] || '';
              editLink.href = editUrl;
              editLink.hidden = editUrl === '';
            }
          };

          select.addEventListener('change', updatePreview);
          updatePreview();
        });
    },
  };
})(Drupal, once);
