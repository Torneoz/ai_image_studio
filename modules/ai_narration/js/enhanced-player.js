(function (Drupal, drupalSettings, once) {
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

  const refreshMode = (audio) => {
    const enhanced = playerMode(audio) === 'enhanced';
    const wrapper = audio.closest('.ai-narration-enhanced-player');
    if (wrapper) {
      wrapper.classList.toggle('is-active', enhanced);
    }
    audio.classList.toggle('ai-narration-native-player', !enhanced);
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
    const bars = Array.from({length: 56}, (_, index) => {
      const height = 22 + ((index * 17 + index * index * 7) % 72);
      return `<span class="ai-narration-enhanced-player__bar" style="--bar-height:${height}%"></span>`;
    }).join('');
    controls.innerHTML = `<button type="button" class="ai-narration-enhanced-player__play" aria-label="Play audio">▶</button><span class="ai-narration-enhanced-player__time">0:00</span><div class="ai-narration-enhanced-player__track" role="presentation"><div class="ai-narration-enhanced-player__waveform" aria-hidden="true">${bars}</div><input class="ai-narration-enhanced-player__seek" type="range" min="0" max="100" value="0" step="0.1" aria-label="Audio progress"></div><span class="ai-narration-enhanced-player__duration">0:00</span><label class="ai-narration-enhanced-player__speed-label">Speed <select class="ai-narration-enhanced-player__speed" aria-label="Playback speed"><option value="0.75">0.75×</option><option value="1" selected>1×</option><option value="1.25">1.25×</option><option value="1.5">1.5×</option></select></label>`;
    wrapper.appendChild(controls);
    const play = controls.querySelector('.ai-narration-enhanced-player__play');
    const seek = controls.querySelector('.ai-narration-enhanced-player__seek');
    const time = controls.querySelector('.ai-narration-enhanced-player__time');
    const duration = controls.querySelector('.ai-narration-enhanced-player__duration');
    const speed = controls.querySelector('.ai-narration-enhanced-player__speed');
    const updateProgress = () => {
      const progress = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
      seek.value = String(progress);
      controls.style.setProperty('--ai-narration-progress', `${progress}%`);
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
    audio.addEventListener('loadedmetadata', () => duration.textContent = formatTime(audio.duration));
    audio.addEventListener('timeupdate', () => {
      time.textContent = formatTime(audio.currentTime);
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
    refreshMode(audio);
  };

  Drupal.behaviors.aiNarrationEnhancedPlayer = {
    attach(context) {
      once('ai-narration-enhanced-player', '.ai-narration-result audio', context).forEach(enhance);
      once('ai-narration-player-mode', '[data-ai-narration-player-mode]', context).forEach((select) => {
        select.addEventListener('change', () => document.querySelectorAll('.ai-narration-result audio').forEach(refreshMode));
      });
    },
  };
}(Drupal, drupalSettings, once));
