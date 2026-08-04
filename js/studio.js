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
          const history = studio.querySelector('.ai-image-studio-turns');
          const orderControl = studio.querySelector(
            '[data-ai-image-studio-history-order]',
          );
          const cards = history
            ? Array.from(history.querySelectorAll('[data-ai-image-studio-turn]'))
            : [];

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

            const inherited = {
              model: selectedCard.dataset.aiImageStudioModel,
              aspect_ratio: selectedCard.dataset.aiImageStudioAspectRatio,
              resolution: selectedCard.dataset.aiImageStudioResolution,
              duration: selectedCard.dataset.aiImageStudioDuration,
              transparent_background:
                selectedCard.dataset.aiImageStudioTransparentBackground,
            };
            Object.entries(inherited).forEach(([name, value]) => {
              if (value === undefined || value === '') {
                return;
              }
              const control = studio.querySelector(`[name="${name}"]`);
              if (!control) {
                return;
              }
              if (control.type === 'checkbox') {
                control.checked = value === '1';
              }
              else if (
                control.tagName !== 'SELECT' ||
                Array.from(control.options).some((option) => option.value === value)
              ) {
                control.value = value;
              }
              control.dispatchEvent(new Event('change', { bubbles: true }));
            });
          };

          choices.forEach((choice) => {
            choice.addEventListener('change', () => {
              if (choice.checked) {
                selectSource(choice);
              }
            });
          });

          if (history && orderControl) {
            const applyHistoryOrder = () => {
              const orderedCards = orderControl.value === 'newest'
                ? [...cards].reverse()
                : cards;
              orderedCards.forEach((card) => history.append(card));
            };
            orderControl.addEventListener('change', applyHistoryOrder);
            applyHistoryOrder();
          }
        });
    },
  };

  Drupal.behaviors.aiImageStudioGenerationFeedback = {
    attach(context) {
      once(
        'ai-image-studio-generation-feedback',
        '.ai-image-studio-layout',
        context,
      ).forEach((studio) => {
        studio.addEventListener('submit', (event) => {
          const submitter = event.submitter;
          if (!submitter || !submitter.matches(
            '[data-ai-image-studio-generate]',
          )) {
            return;
          }
          if (studio.classList.contains('is-generating')) {
            event.preventDefault();
            return;
          }

          const output = studio.querySelector(
            'input[name="output_type"]:checked',
          )?.value || 'image';
          const isVideo = output === 'video';
          const feedback = studio.querySelector(
            '[data-ai-image-studio-generation-feedback]',
          );
          const title = studio.querySelector(
            '[data-ai-image-studio-generation-title]',
          );
          const message = studio.querySelector(
            '[data-ai-image-studio-generation-message]',
          );
          const busyLabel = isVideo
            ? submitter.dataset.generatingVideoLabel
            : submitter.dataset.generatingImageLabel;

          studio.setAttribute('aria-busy', 'true');
          studio.classList.add('is-generating');
          studio.querySelectorAll('[data-ai-image-studio-generate]')
            .forEach((button) => {
              button.setAttribute('aria-disabled', 'true');
            });
          if (title) {
            title.textContent = isVideo
              ? Drupal.t('Generating video…')
              : Drupal.t('Generating image…');
          }
          if (message) {
            message.textContent = isVideo
              ? Drupal.t(
                'Video generation can take several minutes. Keep this page open while the provider completes the request.',
              )
              : Drupal.t(
                'The provider is creating your image. Keep this page open until the request completes.',
              );
          }
          if (feedback) {
            feedback.hidden = false;
            feedback.scrollIntoView({
              behavior: 'smooth',
              block: 'nearest',
            });
          }

          // Wait until the browser has serialized the clicked submit button.
          // Disabling it or changing its value synchronously can prevent
          // Drupal Form API from identifying the triggering element.
          window.setTimeout(() => {
            studio.querySelectorAll('[data-ai-image-studio-generate]')
              .forEach((button) => {
                button.disabled = true;
              });
            if (busyLabel) {
              submitter.value = busyLabel;
            }
          }, 0);
        });
      });
    },
  };
})(Drupal, once);
