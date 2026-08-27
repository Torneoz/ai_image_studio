(function (Drupal, once, drupalSettings) {
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
          const previewLinks = studio.querySelectorAll(
            '[data-ai-image-studio-source-preview-link]',
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

            previewLinks.forEach((link) => {
              link.href = `#${selectedCard.id}`;
            });

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
              quality: selectedCard.dataset.aiImageStudioQuality,
              variations: selectedCard.dataset.aiImageStudioVariations,
              duration: selectedCard.dataset.aiImageStudioDuration,
              transparent_background:
                selectedCard.dataset.aiImageStudioTransparentBackground,
              auto_levels: selectedCard.dataset.aiImageStudioAutoLevels,
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

          const modelControls = studio.querySelectorAll(
            'select[name$="model"]',
          );
          const checkedValue = (name, fallback) =>
            studio.querySelector(`input[name="${name}"]:checked`)?.value || fallback;
          const activeModelControl = () => {
            const outputType = checkedValue('output_type', 'image');
            const startModeControl = studio.querySelector('input[name="start_mode"]');
            let modelName;
            if (startModeControl) {
              const startMode = checkedValue('start_mode', 'prompt');
              modelName = outputType === 'video'
                ? (startMode === 'prompt' ? 'text_video_model' : 'image_video_model')
                : (startMode === 'prompt' ? 'text_model' : 'image_model');
            }
            else {
              modelName = outputType === 'video'
                ? 'video_model'
                : (outputType === 'prompt' ? 'text_model' : 'model');
            }
            return studio.querySelector(`select[name="${modelName}"]`);
          };
          const applyFormVisibility = () => {
            const startMode = checkedValue('start_mode', 'prompt');
            const outputType = checkedValue('output_type', 'image');
            studio.querySelectorAll('[data-ai-image-studio-conditional]')
              .forEach((control) => {
                const requiredStart = control.dataset.aiImageStudioStartMode;
                const requiredOutput = control.dataset.aiImageStudioOutputType;
                const startMatches = !requiredStart
                  || requiredStart === startMode
                  || (requiredStart === 'source'
                    && ['upload', 'media'].includes(startMode));
                const visible = startMatches
                  && (!requiredOutput || requiredOutput === outputType);
                control.hidden = !visible;
                const formItem = control.closest('.js-form-item, .form-item');
                if (formItem && formItem !== control) {
                  formItem.hidden = !visible;
                }
              });
          };
          const applyModelCapabilities = () => {
            const visibleModel = activeModelControl()
              || Array.from(modelControls).find(
                (control) => control.offsetParent !== null,
              );
            const model = (visibleModel?.value || '').toLowerCase();
            const isGrok = model.includes('grok') || model.includes('xai');
            const outputType = checkedValue('output_type', 'image');
            const variationsControl = studio.querySelector(
              '[data-ai-image-studio-variations-control]',
            );
            const variations = variationsControl?.querySelector('select');
            const variationLimits =
              drupalSettings.aiImageStudio?.variationLimits || {};
            const maximumVariations = Number(
              variationLimits[visibleModel?.value] || 1,
            );
            if (variations) {
              Array.from(variations.options).forEach((option) => {
                option.disabled = Number(option.value) > maximumVariations;
              });
              if (Number(variations.value) > maximumVariations) {
                variations.value = String(maximumVariations);
              }
              const description = variationsControl.querySelector('.description');
              if (description) {
                description.textContent = maximumVariations === 1
                  ? Drupal.t('The selected model returns one image per request.')
                  : Drupal.t(
                    'The selected model supports up to @count variations per request. Each result is retained as a separate version.',
                    { '@count': maximumVariations },
                  );
              }
            }
            const quality = studio.querySelector(
              '[data-ai-image-studio-quality-control] select',
            );
            if (quality) {
              quality.disabled = isGrok && !model.includes('image-2.0');
            }
            const videoResolution = studio.querySelector(
              '[data-ai-image-studio-video-resolution]',
            );
            const fullHd = videoResolution?.querySelector(
              'option[value="1080p"]',
            );
            if (fullHd) {
              fullHd.disabled = isGrok && !model.includes('video-1.5');
              if (fullHd.disabled && videoResolution.value === '1080p') {
                videoResolution.value = '720p';
              }
            }
            const references = studio.querySelector(
              '[data-ai-image-studio-references]',
            );
            if (references) {
              const referenceVideo = outputType === 'video'
                && checkedValue('video_mode', 'animate') === 'reference';
              references.hidden = !isGrok
                || outputType === 'prompt'
                || (outputType === 'video' && !referenceVideo);
            }
            const referenceMode = studio.querySelector(
              'input[name="video_mode"][value="reference"]',
            );
            if (referenceMode) {
              referenceMode.disabled = !isGrok;
              if (referenceMode.checked && !isGrok) {
                const animateMode = studio.querySelector(
                  'input[name="video_mode"][value="animate"]',
                );
                if (animateMode) {
                  animateMode.checked = true;
                }
              }
            }
          };
          modelControls.forEach((control) => {
            control.addEventListener('change', applyModelCapabilities);
          });
          studio.querySelectorAll(
            'input[name="output_type"], input[name="start_mode"], input[name="video_mode"]',
          )
            .forEach((control) => {
              control.addEventListener('change', () => {
                applyFormVisibility();
                window.setTimeout(applyModelCapabilities, 0);
              });
            });
          applyFormVisibility();
          applyModelCapabilities();

          const referenceOptions = studio.querySelector(
            '[data-ai-image-studio-reference-options]',
          );
          const referenceOrder = studio.querySelector(
            '[data-ai-image-studio-reference-order]',
          );
          const referenceChips = studio.querySelector(
            '[data-ai-image-studio-reference-chips]',
          );
          if (referenceOptions && referenceOrder && referenceChips) {
            const prompt = studio.querySelector('textarea[name="prompt_start"]');
            let orderedIds = referenceOrder.value
              ? referenceOrder.value.split(',').filter(Boolean)
              : [];
            const renderChips = () => {
              const checked = Array.from(referenceOptions.querySelectorAll(
                'input[type="checkbox"]:checked',
              ));
              const checkedIds = checked.map((input) => input.value);
              orderedIds = orderedIds.filter((id) => checkedIds.includes(id));
              checkedIds.forEach((id) => {
                if (!orderedIds.includes(id)) {
                  orderedIds.push(id);
                }
              });
              referenceOrder.value = orderedIds.join(',');
              referenceChips.replaceChildren();
              const primaryChip = document.createElement('span');
              primaryChip.className = 'ai-image-studio-reference-chip';
              primaryChip.textContent = Drupal.t('Image 1: selected source');
              const primaryInsert = document.createElement('button');
              primaryInsert.type = 'button';
              primaryInsert.textContent = Drupal.t('Insert token');
              primaryInsert.addEventListener('click', () => {
                if (!prompt) {
                  return;
                }
                const start = prompt.selectionStart || prompt.value.length;
                prompt.setRangeText(
                  '<IMAGE_1>',
                  start,
                  prompt.selectionEnd || start,
                  'end',
                );
                prompt.focus();
              });
              if (prompt) {
                primaryChip.append(primaryInsert);
              }
              referenceChips.append(primaryChip);
              orderedIds.forEach((id, index) => {
                const input = referenceOptions.querySelector(
                  `input[value="${CSS.escape(id)}"]`,
                );
                const chip = document.createElement('span');
                chip.className = 'ai-image-studio-reference-chip';
                chip.draggable = true;
                chip.dataset.referenceId = id;
                chip.textContent = Drupal.t('Image @number: @label', {
                  '@number': index + 2,
                  '@label': input?.labels?.[0]?.textContent?.trim() || id,
                });
                const insert = document.createElement('button');
                insert.type = 'button';
                insert.textContent = Drupal.t('Insert token');
                insert.addEventListener('click', () => {
                  if (!prompt) {
                    return;
                  }
                  const token = `<IMAGE_${index + 2}>`;
                  const start = prompt.selectionStart || prompt.value.length;
                  prompt.setRangeText(token, start, prompt.selectionEnd || start, 'end');
                  prompt.focus();
                });
                if (prompt) {
                  chip.append(insert);
                }
                chip.addEventListener('dragstart', (event) => {
                  event.dataTransfer.setData('text/plain', id);
                });
                chip.addEventListener('dragover', (event) => event.preventDefault());
                chip.addEventListener('drop', (event) => {
                  event.preventDefault();
                  const moved = event.dataTransfer.getData('text/plain');
                  const from = orderedIds.indexOf(moved);
                  const to = orderedIds.indexOf(id);
                  if (from >= 0 && to >= 0 && from !== to) {
                    orderedIds.splice(to, 0, orderedIds.splice(from, 1)[0]);
                    renderChips();
                  }
                });
                referenceChips.append(chip);
              });
            };
            referenceOptions.addEventListener('change', renderChips);
            renderChips();
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

          const output = submitter.dataset.aiImageStudioOutputType
            || studio.querySelector(
              'input[name="output_type"]:checked',
            )?.value
            || 'image';
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
          }
          const processingTarget = studio.querySelector(
            '.ai-image-studio-turns',
          ) || feedback;
          if (processingTarget) {
            processingTarget.scrollIntoView({
              behavior: 'smooth',
              block: 'start',
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
})(Drupal, once, drupalSettings);
