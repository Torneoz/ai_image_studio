(function (Drupal, drupalSettings, once) {
  const narrationSettings = drupalSettings.aiNarration || {};
  const defaultDisplayStyle = narrationSettings.playerDisplayStyle === 'bars' ? 'bars' : 'waveform';
  const displayStyle = (audio) => audio.dataset.aiNarrationDisplayStyle === 'bars' ? 'bars' : defaultDisplayStyle;
  const validColour = (value, fallback) => /^#[0-9a-f]{6}$/i.test(value || '') ? value : fallback;
  const appearance = (audio) => {
    const values = audio.dataset;
    const custom = (values.aiNarrationPlayerColourMode || narrationSettings.playerColourMode) === 'custom';
    const height = /^([1-4](?:\.5)?|4\.5)rem$/.test(values.aiNarrationPlayerHeight || narrationSettings.playerHeight || '') ? (values.aiNarrationPlayerHeight || narrationSettings.playerHeight) : '4rem';
    return {
      custom,
      accent: custom ? validColour(values.aiNarrationPlayerAccentColour || narrationSettings.playerAccentColour, '#24c7b9') : '#24c7b9',
      waveform: custom ? validColour(values.aiNarrationPlayerWaveformColour || narrationSettings.playerWaveformColour, '#74fff0') : '#74fff0',
      unplayedWaveform: custom ? validColour(values.aiNarrationPlayerUnplayedWaveformColour || narrationSettings.playerUnplayedWaveformColour, '#4c898a') : '#4c898a',
      speedControl: custom ? validColour(values.aiNarrationPlayerSpeedControlColour || narrationSettings.playerSpeedControlColour, '#e9fffc') : '#e9fffc',
      background: custom ? validColour(values.aiNarrationPlayerBackgroundColour || narrationSettings.playerBackgroundColour, '#102b2d') : '#102b2d',
      text: custom ? validColour(values.aiNarrationPlayerTextColour || narrationSettings.playerTextColour, '#e9fffc') : '#e9fffc',
      height,
    };
  };

  const applyAppearance = (audio) => {
    const wrapper = audio.closest('.ai-narration-enhanced-player');
    if (!wrapper) {
      return;
    }
    const values = appearance(audio);
    wrapper.style.setProperty('--ai-narration-player-accent', values.accent);
    wrapper.style.setProperty('--ai-narration-player-accent-light', values.waveform);
    wrapper.style.setProperty('--ai-narration-player-background', values.background);
    wrapper.style.setProperty('--ai-narration-player-text', values.text);
    wrapper.style.setProperty('--ai-narration-player-speed-control', values.speedControl);
    wrapper.style.setProperty('--ai-narration-player-height', values.height);
    const heightValue = Number.parseFloat(values.height);
    wrapper.style.setProperty('--ai-narration-player-play-size', `${Math.max(1.2, heightValue - 1.1)}rem`);
    wrapper.style.setProperty('--ai-narration-player-waveform-height', `${Math.max(0.75, heightValue - 1.5)}rem`);
    wrapper.style.setProperty('--ai-narration-player-font-size', `${Math.max(0.65, Math.min(1, heightValue * 0.22))}rem`);
  };

  const formatTime = (seconds) => {
    if (!Number.isFinite(seconds) || seconds < 0) {
      return '0:00';
    }
    const minutes = Math.floor(seconds / 60);
    const remainder = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${minutes}:${remainder}`;
  };

  const playerMode = (audio) => {
    const form = audio.closest('form');
    const preference = form?.querySelector('[data-ai-narration-player-mode]')?.value || '';
    return preference || drupalSettings.aiNarration?.playerModeDefault || 'native';
  };

  const pauseOthers = (audio) => {
    document.querySelectorAll('.ai-narration-result audio').forEach((other) => {
      if (other !== audio && !other.paused) {
        other.pause();
      }
    });
  };

  const drawWaveform = (audio) => {
    const canvas = audio.closest('.ai-narration-enhanced-player')?.querySelector('.ai-narration-enhanced-player__waveform');
    const buffer = audio._aiNarrationWaveform;
    const playerAppearance = appearance(audio);
    const style = displayStyle(audio);
    if (!canvas || (style === 'waveform' && !buffer)) {
      return;
    }
    const width = Math.max(1, Math.round(canvas.clientWidth));
    const height = Math.max(1, Math.round(canvas.clientHeight));
    const ratio = window.devicePixelRatio || 1;
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    const context = canvas.getContext('2d');
    context.scale(ratio, ratio);
    context.clearRect(0, 0, width, height);
    const progress = audio.duration ? audio.currentTime / audio.duration : 0;
    const playedWidth = width * progress;
    if (style === 'bars') {
      const bars = 48;
      const gap = 2;
      const barWidth = Math.max(1, (width - ((bars - 1) * gap)) / bars);
      for (let index = 0; index < bars; index++) {
        const barHeight = height * (0.25 + (((index * 13 + index * index * 3) % 65) / 100));
        const x = index * (barWidth + gap);
        context.fillStyle = x <= playedWidth ? playerAppearance.waveform : playerAppearance.unplayedWaveform;
        context.fillRect(x, (height - barHeight) / 2, barWidth, barHeight);
      }
      return;
    }
    const source = buffer.getChannelData(0);
    const samplesPerBar = Math.max(1, Math.floor(source.length / width));
    for (let x = 0; x < width; x++) {
      const offset = x * samplesPerBar;
      let peak = 0;
      for (let index = 0; index < samplesPerBar && offset + index < source.length; index++) {
        peak = Math.max(peak, Math.abs(source[offset + index]));
      }
      // Apply perceptual gain so quieter narration remains legible without
      // changing the source audio or the player’s actual playback level.
      const barHeight = Math.max(3, Math.min(height, Math.pow(peak, 0.42) * height));
      // Keep unplayed audio visibly distinct from the dark panel, while the
      // played section remains the strongest visual cue.
      context.fillStyle = x <= playedWidth ? playerAppearance.waveform : playerAppearance.unplayedWaveform;
      context.fillRect(x, (height - barHeight) / 2, 1, barHeight);
    }
  };

  const loadWaveform = async (audio) => {
    if (displayStyle(audio) === 'bars') {
      drawWaveform(audio);
      return;
    }
    const source = audio.currentSrc || audio.src;
    if (audio._aiNarrationWaveform || audio._aiNarrationWaveformLoading || !source) {
      return;
    }
    audio._aiNarrationWaveformLoading = true;
    try {
      const response = await fetch(source);
      if (!response.ok) {
        throw new Error(`Could not load waveform audio (${response.status}).`);
      }
      const audioContext = new (window.AudioContext || window.webkitAudioContext)();
      audio._aiNarrationWaveform = await audioContext.decodeAudioData(await response.arrayBuffer());
      await audioContext.close();
      drawWaveform(audio);
      const resizeObserver = new ResizeObserver(() => drawWaveform(audio));
      resizeObserver.observe(audio.closest('.ai-narration-enhanced-player').querySelector('.ai-narration-enhanced-player__track'));
    }
    catch (error) {
      audio.closest('.ai-narration-enhanced-player')?.classList.add('waveform-unavailable');
    }
    finally {
      audio._aiNarrationWaveformLoading = false;
    }
  };

  const renderSegmentMarkers = (audio) => {
    const track = audio.closest('.ai-narration-enhanced-player')?.querySelector('.ai-narration-enhanced-player__track');
    if (!track || !audio.duration) {
      return;
    }
    let markers = [];
    try {
      markers = JSON.parse(audio.dataset.aiNarrationSegmentMarkers || '[]');
    }
    catch (error) {
      return;
    }
    track.querySelector('.ai-narration-enhanced-player__markers')?.remove();
    if (!Array.isArray(markers) || markers.length < 2) {
      return;
    }
    const container = document.createElement('div');
    container.className = 'ai-narration-enhanced-player__markers';
    let elapsed = 0;
    markers.slice(0, -1).forEach((marker, index) => {
      elapsed += Number(marker.duration) || 0;
      const boundary = document.createElement('span');
      boundary.className = 'ai-narration-enhanced-player__marker';
      boundary.style.left = `${Math.min(100, (elapsed / audio.duration) * 100)}%`;
      boundary.title = marker.label || `Segment ${index + 1}`;
      boundary.setAttribute('aria-label', boundary.title);
      container.appendChild(boundary);
    });
    track.appendChild(container);
  };

  const refreshMode = (audio) => {
    const enhanced = playerMode(audio) === 'enhanced';
    const wrapper = audio.closest('.ai-narration-enhanced-player');
    if (wrapper) {
      wrapper.classList.toggle('is-active', enhanced);
      applyAppearance(audio);
    }
    audio.classList.toggle('ai-narration-native-player', !enhanced);
    if (enhanced) {
      loadWaveform(audio);
    }
  };

  const enhance = (audio) => {
    if (audio.closest('.ai-narration-enhanced-player')) {
      refreshMode(audio);
      return;
    }
    const wrapper = document.createElement('div');
    wrapper.className = 'ai-narration-enhanced-player';
    audio.parentNode.insertBefore(wrapper, audio);
    wrapper.appendChild(audio);

    const controls = document.createElement('div');
    controls.className = 'ai-narration-enhanced-player__controls';
    controls.innerHTML = '<button type="button" class="ai-narration-enhanced-player__play" aria-label="Play audio">▶</button><span class="ai-narration-enhanced-player__time">0:00</span><div class="ai-narration-enhanced-player__track" role="presentation"><canvas class="ai-narration-enhanced-player__waveform" aria-hidden="true"></canvas><input class="ai-narration-enhanced-player__seek" type="range" min="0" max="100" value="0" step="0.1" aria-label="Audio progress"></div><span class="ai-narration-enhanced-player__remaining">−0:00</span><span class="ai-narration-enhanced-player__duration">0:00</span><label class="ai-narration-enhanced-player__speed-label">Speed <select class="ai-narration-enhanced-player__speed" aria-label="Playback speed"><option value="0.75">0.75×</option><option value="1" selected>1×</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option></select></label>';
    wrapper.appendChild(controls);
    const play = controls.querySelector('.ai-narration-enhanced-player__play');
    const seek = controls.querySelector('.ai-narration-enhanced-player__seek');
    const time = controls.querySelector('.ai-narration-enhanced-player__time');
    const remaining = controls.querySelector('.ai-narration-enhanced-player__remaining');
    const duration = controls.querySelector('.ai-narration-enhanced-player__duration');
    const speed = controls.querySelector('.ai-narration-enhanced-player__speed');
    const updateProgress = () => {
      const progress = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
      seek.value = String(progress);
      drawWaveform(audio);
    };

    play.addEventListener('click', () => audio.paused ? audio.play() : audio.pause());
    audio.addEventListener('play', () => {
      pauseOthers(audio);
      wrapper.classList.add('is-playing');
      play.textContent = '❚❚';
      play.setAttribute('aria-label', 'Pause audio');
    });
    audio.addEventListener('pause', () => {
      wrapper.classList.remove('is-playing');
      play.textContent = '▶';
      play.setAttribute('aria-label', 'Play audio');
    });
    audio.addEventListener('loadedmetadata', () => {
      duration.textContent = formatTime(audio.duration);
      const previewPosition = Number(audio.dataset.aiNarrationPreviewPosition);
      if (previewPosition > 0 && previewPosition < 1 && audio.currentTime === 0 && audio.duration) {
        audio.currentTime = audio.duration * previewPosition;
      }
      remaining.textContent = `−${formatTime(audio.duration - audio.currentTime)}`;
      renderSegmentMarkers(audio);
    });
    audio.addEventListener('loadedmetadata', () => {
      loadWaveform(audio);
      drawWaveform(audio);
    });
    audio.addEventListener('timeupdate', () => {
      time.textContent = formatTime(audio.currentTime);
      remaining.textContent = `−${formatTime(audio.duration - audio.currentTime)}`;
      updateProgress();
    });
    audio.addEventListener('ended', () => updateProgress());
    seek.addEventListener('input', () => {
      if (audio.duration) {
        audio.currentTime = (Number(seek.value) / 100) * audio.duration;
        updateProgress();
      }
    });
    speed.addEventListener('change', () => audio.playbackRate = Number(speed.value));
    audio.addEventListener('aiNarrationAppearanceChange', () => {
      applyAppearance(audio);
      drawWaveform(audio);
    });
    updateProgress();
    refreshMode(audio);
  };

  Drupal.behaviors.aiNarrationEnhancedPlayer = {
    attach(context) {
      once('ai-narration-enhanced-player', '.ai-narration-result audio', context).forEach(enhance);
      once('ai-narration-player-mode', '[data-ai-narration-player-mode]', context).forEach((select) => {
        select.addEventListener('change', () => document.querySelectorAll('.ai-narration-result audio').forEach(refreshMode));
      });
      const previewAttributes = {
        player_colour_mode: 'aiNarrationPlayerColourMode',
        player_accent_colour: 'aiNarrationPlayerAccentColour',
        player_waveform_colour: 'aiNarrationPlayerWaveformColour',
        player_unplayed_waveform_colour: 'aiNarrationPlayerUnplayedWaveformColour',
        player_speed_control_colour: 'aiNarrationPlayerSpeedControlColour',
        player_background_colour: 'aiNarrationPlayerBackgroundColour',
        player_text_colour: 'aiNarrationPlayerTextColour',
        player_height: 'aiNarrationPlayerHeight',
      };
      Object.entries(previewAttributes).forEach(([name, attribute]) => {
        once(`ai-narration-live-preview-${name}`, `[name="${name}"]`, context).forEach((input) => {
          const update = () => {
            const previewAudio = document.querySelector('#ai-narration-player-settings-preview audio');
            if (!previewAudio) {
              return;
            }
            if (input.type !== 'radio' || input.checked) {
              previewAudio.dataset[attribute] = input.value;
              previewAudio.dispatchEvent(new Event('aiNarrationAppearanceChange'));
            }
          };
          input.addEventListener('input', update);
          input.addEventListener('change', update);
        });
      });
    },
  };
}(Drupal, drupalSettings, once));
