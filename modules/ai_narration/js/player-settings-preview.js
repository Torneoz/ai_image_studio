(function (Drupal, once) {
  const defaults = {
    accent: '#24c7b9',
    waveform: '#74fff0',
    background: '#102b2d',
    text: '#e9fffc',
  };

  Drupal.behaviors.aiNarrationPlayerSettingsPreview = {
    attach(context) {
      once('ai-narration-player-settings-preview', '#ai-narration-player-settings-preview', context).forEach((preview) => {
        const player = preview.querySelector('.ai-narration-player-settings-preview__player');
        const form = preview.closest('form');
        const input = (name) => form.querySelector(`[name="${name}"]`);
        const update = () => {
          const custom = form.querySelector('[name="player_colour_mode"]:checked')?.value === 'custom';
          const value = (name, fallback) => custom && /^#[0-9a-f]{6}$/i.test(input(name)?.value || '') ? input(name).value : fallback;
          player.style.setProperty('--ai-narration-preview-accent', value('player_accent_colour', defaults.accent));
          player.style.setProperty('--ai-narration-preview-waveform', value('player_waveform_colour', defaults.waveform));
          player.style.setProperty('--ai-narration-preview-background', value('player_background_colour', defaults.background));
          player.style.setProperty('--ai-narration-preview-text', value('player_text_colour', defaults.text));
        };
        form.querySelectorAll('[name="player_colour_mode"], [name="player_accent_colour"], [name="player_waveform_colour"], [name="player_background_colour"], [name="player_text_colour"]').forEach((element) => element.addEventListener('input', update));
        update();
      });
    },
  };
}(Drupal, once));
