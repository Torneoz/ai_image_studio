(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiImageStudioSource = {
    attach(context) {
      once('ai-image-studio-source', '.ai-image-studio-layout', context)
        .forEach((studio) => {
          const choices = studio.querySelectorAll(
            '.ai-image-studio-source-choice',
          );
          const previewTitle = studio.querySelector(
            '[data-ai-image-studio-source-preview-title]',
          );
          const previewImage = studio.querySelector(
            '[data-ai-image-studio-source-preview-image]',
          );

          const selectSource = (choice) => {
            const selectedCard = choice.closest(
              '[data-ai-image-studio-turn]',
            );
            if (!selectedCard) {
              return;
            }

            studio.querySelectorAll('[data-ai-image-studio-turn]')
              .forEach((card) => {
                card.classList.toggle(
                  'is-refinement-source',
                  card === selectedCard,
                );
              });

            if (previewTitle) {
              previewTitle.textContent =
                selectedCard.dataset.aiImageStudioSourceTitle || '';
            }

            const selectedImage = selectedCard.querySelector(
              '.ai-image-studio-turn__image',
            );
            if (previewImage && selectedImage) {
              previewImage.src = selectedImage.src;
              previewImage.alt = selectedImage.alt;
            }
          };

          choices.forEach((choice) => {
            choice.addEventListener('change', () => {
              if (choice.checked) {
                selectSource(choice);
              }
            });
          });
        });
    },
  };
})(Drupal, once);
