




















(function () {
  "use strict";

  
  
  
  
  
  var isWatchDocFreshLoad = true;

  function updateVisualizerUI(on) {
    const btn = document.getElementById("btn-vis"),
      label = document.getElementById("vis-text"),
      cava = document.getElementById("cava-container");
    _setTogglePillUI(btn, on);
    if (label) label.innerText = on ? "Vis On" : "Vis Off";
    if (cava) {
      cava.style.display = on ? "flex" : "none";
      cava.classList.toggle("hidden", !on);
    }
  }

  
  function buildVisualizerBars(engine) {
    const cava = document.getElementById("cava-container");
    if (!cava) return;
    function barCount() {
      let w = cava.clientWidth;
      if (w <= 0)
        w = window.innerWidth >= 1024 ? 0.32 * window.innerWidth : window.innerWidth - 32;
      return w < 180 ? 12 : w < 280 ? 18 : w < 400 ? 24 : w < 600 ? 32 : 40;
    }
    function rebuild() {
      const n = barCount();
      cava.innerHTML = "";
      const frag = document.createDocumentFragment();
      const bars = [];
      for (let i = 0; i < n; i++) {
        const el = document.createElement("div");
        el.className =
          "flex-1 bg-gradient-to-t from-orange-600 to-orange-400 rounded-t-sm transition-all duration-75";
        el.style.cssText =
          "height:100%;min-width:1px;transform-origin:bottom;will-change:transform;transform:scaleY(0.04)";
        frag.appendChild(el);
        bars.push(el);
      }
      cava.appendChild(frag);
      if (engine.__vis) engine.__vis.setBars(bars);
    }
    rebuild();
    if (!cava.__meelResizeBound) {
      cava.__meelResizeBound = true;
      let debounce = null;
      window.addEventListener("resize", function () {
        clearTimeout(debounce);
        debounce = setTimeout(rebuild, 200);
      });
    }
  }

  
  
  
  function bindEngineOnce(engine) {
    if (engine.player.__meelCoreBound) return;
    engine.player.__meelCoreBound = true;

    const audio = engine.audio,
      player = engine.player;

    
    let loadingTimeout = null,
      secondaryTimeout = null,
      metadataLoaded = false,
      loadRetried = false,
      errorHandled = false,
      audioEndedNaturally = false;

    
    const RECOVERY_MAX_RETRIES = 15;
    const STUCK_CHECK_INTERVAL_MS = 3000;
    const STUCK_THRESHOLD_S = 6;
    const RECOVERY_COOLDOWN_MS = 8000;
    let recoveryRetryCount = 0;
    let isRecovering = false;
    let lastRecoveryTime = 0;
    let stuckCheckInterval = null;
    let lastPlayTime = -1;
    let lastTimeUpdateTs = 0;
    let hasEverPlayed = false;
    let waitingTimeout = null;

    function showReconnectIndicator() {
      var container = document.getElementById('player-container');
      if (!container) return;
      var existing = document.getElementById('meel-music-reconnect');
      if (existing) return;
      var el = document.createElement('div');
      el.id = 'meel-music-reconnect';
      el.style.cssText = 'position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(8,10,15,.88);z-index:60;gap:10px;padding:20px;text-align:center;';
      el.innerHTML = '<div class="animate-spin h-7 w-7 border-2 border-orange-500 border-t-transparent rounded-full"></div>' +
        '<div style="color:#f97316;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;">Sambungan Terputus</div>' +
        '<div style="color:#6b7280;font-size:10px;">Menghubungkan kembali secara otomatis...</div>';
      container.appendChild(el);
    }

    function hideReconnectIndicator() {
      var el = document.getElementById('meel-music-reconnect');
      if (el) el.remove();
    }

    function showReconnectFailed() {
      var container = document.getElementById('player-container');
      if (!container) return;
      var existing = document.getElementById('meel-music-reconnect');
      if (existing) existing.remove();
      var el = document.createElement('div');
      el.id = 'meel-music-reconnect';
      el.style.cssText = 'position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(8,10,15,.88);z-index:60;gap:12px;padding:20px;text-align:center;';
      el.innerHTML = '<div style="color:#6b7280;font-size:11px;">Tidak dapat terhubung ke media.</div>' +
        '<button onclick="window.location.reload()" style="background:#ea580c;color:#000;border:none;padding:8px 20px;border-radius:12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;cursor:pointer;">Muat Ulang</button>';
      container.appendChild(el);
    }

    function triggerStreamRecovery() {
      if (isRecovering || !hasEverPlayed || player.paused) return;
      var now = Date.now();
      if (now - lastRecoveryTime < RECOVERY_COOLDOWN_MS) return;
      if (recoveryRetryCount >= RECOVERY_MAX_RETRIES) {
        console.warn('⚠️ Music recovery: max retries reached');
        showReconnectFailed();
        return;
      }
      isRecovering = true;
      lastRecoveryTime = now;
      recoveryRetryCount++;
      stopStuckDetector();
      showReconnectIndicator();
      console.log('🔄 Music stream recovery #' + recoveryRetryCount + '...');

      var savedTime = player.currentTime || 0;
      var streamUrl = (window.MEEL_MUSIC_CONFIG && window.MEEL_MUSIC_CONFIG.streamUrl) || '';
      if (!streamUrl) { isRecovering = false; return; }

      
      var sep = streamUrl.indexOf('?') >= 0 ? '&' : '?';
      var freshUrl = streamUrl + sep + '_r=' + Date.now();

      audio.pause();
      audio.src = freshUrl;
      audio.load();

      function onReady() {
        if (savedTime > 5) audio.currentTime = savedTime;
        audio.play().then(function () {
          isRecovering = false;
          recoveryRetryCount = 0;
          hideReconnectIndicator();
          startStuckDetector();
          console.log('✅ Music stream recovered at ' + Math.floor(savedTime) + 's');
        }).catch(function (err) {
          console.warn('⚠️ Music recovery play() failed:', err);
          isRecovering = false;
          
          setTimeout(triggerStreamRecovery, RECOVERY_COOLDOWN_MS);
        });
      }

      if (audio.readyState >= 1) onReady();
      else audio.addEventListener('loadedmetadata', onReady, { once: true });
    }

    function startStuckDetector() {
      stopStuckDetector();
      lastPlayTime = -1;
      lastTimeUpdateTs = Date.now();
      stuckCheckInterval = setInterval(function () {
        if (!player || player.paused || isRecovering || isNavigating) return;
        if (document.hidden) return;
        var ct = player.currentTime;
        var now = Date.now();
        if (ct === lastPlayTime) {
          if ((now - lastTimeUpdateTs) / 1000 >= STUCK_THRESHOLD_S) {
            console.warn('⚠️ Music stream stalled (no progress for ' + STUCK_THRESHOLD_S + 's)');
            triggerStreamRecovery();
          }
        } else {
          lastPlayTime = ct;
          lastTimeUpdateTs = now;
        }
      }, STUCK_CHECK_INTERVAL_MS);
    }

    function stopStuckDetector() {
      if (stuckCheckInterval) {
        clearInterval(stuckCheckInterval);
        stuckCheckInterval = null;
      }
    }

    function startWaitingTimeout() {
      stopWaitingTimeout();
      waitingTimeout = setTimeout(function () {
        console.warn('⚠️ Music audio waiting >10s, trigger recovery');
        triggerStreamRecovery();
      }, 10000);
    }

    function stopWaitingTimeout() {
      if (waitingTimeout) { clearTimeout(waitingTimeout); waitingTimeout = null; }
    }

    
    audio.addEventListener('error', function () {
      var code = audio.error ? audio.error.code : 0;
      if (code === 2 && hasEverPlayed && !isRecovering) {
        console.warn('⚠️ Audio network error, attempting recovery...');
        triggerStreamRecovery();
      }
    });

    
    audio.addEventListener('stalled', function () {
      if (!player.paused && hasEverPlayed && !isRecovering) {
        console.warn('⚠️ Audio stalled event, starting waiting timeout...');
        startWaitingTimeout();
      }
    });

    audio.addEventListener('playing', function () {
      stopWaitingTimeout();
    });

    audio.addEventListener('canplay', function () {
      stopWaitingTimeout();
      if (isRecovering) {
        isRecovering = false;
        recoveryRetryCount = 0;
        hideReconnectIndicator();
        startStuckDetector();
      }
    });

    function isFlacNow() {
      const fn = (window.MEEL_MUSIC_CONFIG && window.MEEL_MUSIC_CONFIG.filename) || "";
      return fn.toLowerCase().endsWith(".flac");
    }
    function clearAllTimeouts() {
      clearTimeout(loadingTimeout);
      clearTimeout(secondaryTimeout);
      loadingTimeout = secondaryTimeout = null;
    }
    function showLoadingOverlay(msg) {
      hideReconnectIndicator();
      const container = document.getElementById("player-container");
      if (!container) return;
      let overlay = document.getElementById("flac-loading-overlay");
      if (!overlay) {
        overlay = document.createElement("div");
        overlay.id = "flac-loading-overlay";
        overlay.style.cssText =
          "position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(8,10,15,.85);z-index:50;border-radius:inherit;gap:12px;padding:20px;text-align:center;";
        const spinner = document.createElement("div");
        spinner.className =
          "animate-spin h-8 w-8 border-2 border-orange-500 border-t-transparent rounded-full";
        const text = document.createElement("p");
        text.id = "flac-loading-text";
        text.style.cssText =
          "color:#9ca3af;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;";
        overlay.appendChild(spinner);
        overlay.appendChild(text);
        container.style.position = "relative";
        container.appendChild(overlay);
      }
      overlay.style.display = "flex";
      const txt = document.getElementById("flac-loading-text");
      if (txt) txt.textContent = msg || "Memuat audio...";
    }
    function hideLoadingOverlay() {
      const overlay = document.getElementById("flac-loading-overlay");
      if (overlay) overlay.style.display = "none";
    }
    
    engine.__armLoadingTimeout = function () {
      metadataLoaded = false;
      errorHandled = false;
      loadRetried = false;
      audioEndedNaturally = false;
      clearAllTimeouts();
      const isFlac = isFlacNow();
      const LOADING_TIMEOUT_MS = isFlac ? 20000 : 10000;
      loadingTimeout = setTimeout(function () {
        if (!metadataLoaded && !errorHandled) {
          showLoadingOverlay("Memuat file besar... (" + (isFlac ? "FLAC" : "audio") + ")");
          if (isFlac && !loadRetried) {
            loadRetried = true;
            audio.load();
          }
          secondaryTimeout = setTimeout(function () {
            if (!metadataLoaded && !errorHandled) {
              hideLoadingOverlay();
              showLoadingOverlay("⚠️ Waktu muat habis. Silakan refresh halaman atau coba format lain.");
            }
          }, LOADING_TIMEOUT_MS);
        }
      }, LOADING_TIMEOUT_MS);
    };

    audio.addEventListener("error", function () {
      if (errorHandled) return;
      const errCode = audio.error ? audio.error.code : 0;
      
      if (errCode === 2 && hasEverPlayed) return;
      errorHandled = true;
      audioEndedNaturally = false;
      clearAllTimeouts();
      hideLoadingOverlay();
      console.error("❌ Audio error [" + errCode + "]:", audio.error ? audio.error.message : "Gagal memuat audio");
      if (isFlacNow()) {
        showLoadingOverlay("⚠️ FLAC tidak dapat dimuat. Coba refresh halaman atau gunakan format lain.");
      }
    });
    audio.addEventListener("loadedmetadata", function () {
      metadataLoaded = true;
      clearAllTimeouts();
      hideLoadingOverlay();
    });
    window.addEventListener("beforeunload", function () {
      clearAllTimeouts();
      hideLoadingOverlay();
      stopStuckDetector();
      stopWaitingTimeout();
    });

    
    let rafId = null,
      visLastTs = 0,
      visualizerOn = window.innerWidth >= 1024,
      bitrateTimer = null,
      bars = [];

    function analyserData() {
      const analyser = engine.getAnalyser();
      if (!analyser) return null;
      const data = new Uint8Array(analyser.frequencyBinCount);
      analyser.getByteFrequencyData(data);
      return data;
    }
    function renderVisFrame() {
      const cava = document.getElementById("cava-container");
      if (!visualizerOn || !engine.getAnalyser() || player.paused || !cava) {
        cancelAnimationFrame(rafId);
        return;
      }
      const now = performance.now();
      if (now - visLastTs < 33.4) {
        rafId = requestAnimationFrame(renderVisFrame);
        return;
      }
      visLastTs = now;
      const data = analyserData();
      if (!data || !bars.length) {
        rafId = requestAnimationFrame(renderVisFrame);
        return;
      }
      const n = bars.length;
      for (let i = 0; i < n; i++) {
        const v = data[Math.floor(i * (data.length / n) * 0.7)];
        const h = Math.max(4, (v / 255) * 100);
        bars[i].style.transform = "scaleY(" + (h / 100).toFixed(3) + ")";
        const l = v / 255;
        let color = "#9ca3af";
        if (l > 0.75) color = "#22c55e";
        else if (l > 0.5) color = "#FB923C";
        else if (l > 0.25) color = "#eab308";
        bars[i].style.background = color;
      }
      rafId = requestAnimationFrame(renderVisFrame);
    }
    function refreshBitrate() {
      const label = document.getElementById("realtime-bitrate");
      if (!label || player.paused) return;
      if (!engine.getAnalyser() && !engine.ensureAudioContext()) return;
      const data = analyserData();
      if (!data) return;
      updateBitrateLabel(getRealtimeVbrValue(data), label);
    }
    function startBitrateLoop() {
      if (bitrateTimer) return;
      refreshBitrate();
      bitrateTimer = setInterval(refreshBitrate, 1000);
    }
    function stopBitrateLoop() {
      if (bitrateTimer) {
        clearInterval(bitrateTimer);
        bitrateTimer = null;
      }
    }
    engine.__vis = {
      isOn: function () {
        return visualizerOn;
      },
      setOn: function (v) {
        visualizerOn = v;
        if (v) {
          if (!engine.getAnalyser()) engine.ensureAudioContext();
          startBitrateLoop();
          if (!player.paused) renderVisFrame();
        } else {
          cancelAnimationFrame(rafId);
        }
      },
      setBars: function (newBars) {
        bars = newBars;
      },
    };

    function meelResumeCtx() {
      engine.ensureAudioContext();
    }
    document.addEventListener("click", meelResumeCtx);
    document.addEventListener("keydown", meelResumeCtx);

    window.toggleEqualizer = function () {
      eqEnabled = !eqEnabled;
      applyEqToFilters();
      updateEqUI();
      saveEqState();
    };
    window.toggleVisualizer = function () {
      const next = !engine.__vis.isOn();
      engine.__vis.setOn(next);
      updateVisualizerUI(next);
    };

    
    
    function applyPlayingVisualState(isPlaying) {
      const container = document.getElementById("player-container");
      const vinyl = document.querySelector(".vinyl-wrap .vinyl-spin");
      if (isPlaying) {
        isFinished = false;
        if (container) container.classList.add("playing");
        if (vinyl) vinyl.classList.add("playing");
        if (!engine.getAnalyser()) engine.ensureAudioContext();
        startBitrateLoop();
        if (engine.__vis.isOn()) renderVisFrame();
      } else {
        if (container) container.classList.remove("playing");
        if (vinyl) vinyl.classList.remove("playing");
        cancelAnimationFrame(rafId);
        stopBitrateLoop();
      }
      window.updateMiniPlayerUI && window.updateMiniPlayerUI();
    }
    engine.__syncPlayingVisualState = applyPlayingVisualState;

    player.on("play", function () {
      if (window.meelHealthAlertActive) {
        player.pause();
        return;
      }
      hasEverPlayed = true;
      applyPlayingVisualState(true);
      startStuckDetector();
      saveAudioState();
    });
    player.on("pause", function () {
      applyPlayingVisualState(false);
      stopStuckDetector();
      stopWaitingTimeout();
      saveAudioState();
    });
    let lastSecond = -1;
    player.on("timeupdate", function () {
      if (!isFinished && player.currentTime > 0 && player.currentTime < player.duration - 1) {
        const sec = Math.floor(player.currentTime);
        if (sec !== lastSecond) {
          lastSecond = sec;
          localStorage.setItem(storageKeyMusic, player.currentTime);
          
          
          if (sec % 5 === 0) saveAudioState();
        }
      }
      window.updateMiniPlayerUI && window.updateMiniPlayerUI();
      if (player.duration > 0 && !player.paused && player.currentTime >= player.duration - 0.5) {
        audioEndedNaturally = true;
      }
    });
    player.on("loadedmetadata", function () {
      window.updateMiniPlayerUI && window.updateMiniPlayerUI();
    });
    player.on("ended", function () {
      if (window.meelHealthAlertActive) return;
      stopStuckDetector();
      stopWaitingTimeout();
      hasEverPlayed = false;
      isRecovering = false;
      hideReconnectIndicator();
      const isGenuineEnd =
        audioEndedNaturally ||
        (player.duration > 0 && Math.abs(player.currentTime - player.duration) < 1.5) ||
        (player.currentTime > 0 && !audio.error && audio.ended === true);
      if (!isGenuineEnd) {
        console.warn("⚠️ ended fired tapi bukan natural end — skip redirect.");
        return;
      }
      if (isNavigating) return;
      isNavigating = true;
      stopBitrateLoop();
      localStorage.removeItem(storageKeyMusic);
      const next = window.MEEL_MUSIC_CONFIG.nextSongUrl;
      const rec = document.querySelector(".rekomendasi-item");
      const target = next || (rec ? rec.href : "");
      if (!target) {
        isNavigating = false;
        return;
      }
      
      if (window.meelNavigateView) {
        window.meelNavigateView(target, "watch", {
          onAfterSwap: function () {
            window.meelInitWatchPlayer();
            isNavigating = false;
          },
        });
      } else {
        window.location.href = target;
      }
    });
  }

  window.meelInitWatchPlayer = function () {
    window.__meelCurrentView = "watch";
    
    
    
    if (typeof window.meelRebuildCommentPreview === "function") {
      const _ptxt = document.getElementById("comment-preview-text");
      if (_ptxt && !_ptxt.textContent.trim()) {
        window.meelRebuildCommentPreview();
      }
    }
    
    
    isMiniPlayerActive = false;
    const engine = window.meelGetAudioEngine();
    const slot = document.getElementById("player-audio-slot");
    if (!slot) return void console.error("❌ #player-audio-slot not found");
    if (!window.MEEL_MUSIC_CONFIG || !window.MEEL_MUSIC_CONFIG.id)
      return void console.error("❌ MEEL_MUSIC_CONFIG missing");
    if (typeof Plyr === "undefined") return void console.error("❌ Plyr not loaded");

    watchUrl = window.location.href;
    storageKeyMusic = "music_pos_" + window.MEEL_MUSIC_CONFIG.id;

    
    engine.mount(slot, { compact: false });
    audio = engine.audio;
    player = engine.player;
    window.player = player;
    if (!player) return void console.error("❌ Plyr init failed");

    bindEngineOnce(engine);

    
    
    if (engine.__syncPlayingVisualState) {
      engine.__syncPlayingVisualState(!engine.audio.paused);
    }

    const globalLoop = "true" === localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP);
    loadEqState();
    updateEqUI();
    
    
    applyEqToFilters();

    
    let savedActive = false,
      savedTime = 0,
      savedPlaying = false,
      savedLoop = globalLoop;
    const raw = sessionStorage.getItem(MEEL_KEYS.AUDIO_STATE);
    if (raw) {
      try {
        const k = JSON.parse(raw);
        if ((k.musicId ?? k.id) == window.MEEL_MUSIC_CONFIG.id) {
          savedActive = true;
          savedTime = k.currentTime;
          savedPlaying = k.isPlaying;
          if (k.isLooping !== undefined) {
            savedLoop = k.isLooping;
            localStorage.setItem(MEEL_KEYS.GLOBAL_LOOP, String(k.isLooping));
          }
        }
      } catch (e) {
        console.warn("⚠️ Bad audio state:", e);
      }
    }

    
    
    const skipFromIndex = sessionStorage.getItem(MEEL_KEYS.SKIP_RESUME_ONCE) === "true";
    if (skipFromIndex) sessionStorage.removeItem(MEEL_KEYS.SKIP_RESUME_ONCE);
    
    
    if (skipFromIndex) window.__meelResumeSessionActive = true;

    
    
    
    
    
    
    
    if (isWatchDocFreshLoad) {
      isWatchDocFreshLoad = false;
      savedActive = false;
      savedTime = 0;
      savedPlaying = false;
    }

    
    const isFreshTrack = engine.loadTrack(
      {
        id: window.MEEL_MUSIC_CONFIG.id,
        streamUrl: window.MEEL_MUSIC_CONFIG.streamUrl,
        isLooping: savedLoop,
      },
      {
        
        
        autoplay: savedActive ? savedPlaying : false,
        startTime: savedActive ? savedTime : 0,
      },
    );

    buildVisualizerBars(engine);
    updateVisualizerUI(engine.__vis ? engine.__vis.isOn() : window.innerWidth >= 1024);

    if (!isFreshTrack) {
      
      
      
      const wantStream = window.MEEL_MUSIC_CONFIG.streamUrl || "";
      const haveSrc = engine.audio.currentSrc || engine.audio.src || "";
      const wantId = String(window.MEEL_MUSIC_CONFIG.id);
      
      let haveId = null;
      try {
        haveId = new URL(haveSrc, window.location.href).searchParams.get("id");
      } catch (e) {}
      if (wantStream && haveSrc && haveId !== null && haveId !== wantId) {
        
        
        engine.audio.src = wantStream;
        engine.audio.load();
        if (savedActive && savedPlaying) {
          const onReloadReady = function () {
            if (savedTime > 5) engine.audio.currentTime = savedTime;
            engine.audio.play().catch(function () {});
          };
          if (engine.audio.readyState >= HTMLMediaElement.HAVE_METADATA)
            onReloadReady();
          else
            engine.audio.addEventListener("loadedmetadata", onReloadReady, {
              once: true,
            });
        }
      } else if (savedActive && savedPlaying && engine.audio.paused) {
        
        if (savedTime > 5 && engine.audio.currentTime < 1) {
          engine.audio.currentTime = savedTime;
        }
        engine.audio.play().catch(function () {});
      }
      
      
      player.loop = engine.audio.loop;
      _applyLoopUI(player.loop);
      return;
    }

    
    
    engine.setLoop(savedLoop);
    updateLoopUI();
    
    
    
    
    saveAudioState();
    if (engine.__armLoadingTimeout) engine.__armLoadingTimeout();

    const modalEl = document.getElementById("resume-modal"),
      btnResume = document.getElementById("btn-resume"),
      btnRestart = document.getElementById("btn-restart"),
      timeEl = document.getElementById("resume-time");
    if (modalEl && btnResume && btnRestart && timeEl) {
      
      
      if (skipFromIndex && !savedActive) skipResumeModalOnce = true;

      function showResumeModal() {
        return window.meelResumeModal({
          storageKey: storageKeyMusic,
          durationMargin: 5,
          countdownPrefix: "Otomatis putar dari awal dalam",
          countdownDoneText: "Otomatis putar dari awal...",
          skipOnce: function () {
            if (skipResumeModalOnce || window.__meelResumeSessionActive) {
              skipResumeModalOnce = false;
              return true;
            }
            return false;
          },
          onShow: function () {
            audio.autoplay = player.autoplay = false;
            audio.currentTime = parseFloat(localStorage.getItem(storageKeyMusic));
          },
          onResume: function (pos) {
            player.currentTime = pos;
            player.play();
          },
          onRestart: function () {
            localStorage.removeItem(storageKeyMusic);
            audio.currentTime = 0;
            player.play();
          },
        });
      }

      function onFreshTrackReady() {
        const el = document.querySelector(".plyr");
        if (el) {
          el.tabIndex = 0;
          el.focus();
        }
        if (!savedActive) {
          const savedPos = localStorage.getItem(storageKeyMusic);
          const needsResume =
            savedPos &&
            parseFloat(savedPos) > 10 &&
            (!audio.duration || parseFloat(savedPos) < audio.duration - 5);
          if (needsResume) {
            const shown = showResumeModal();
            if (!shown) {
              
              
              localStorage.removeItem(storageKeyMusic);
              audio.currentTime = 0;
              player.play();
            }
          } else {
            
            
            skipResumeModalOnce = false;
            player.play();
          }
        }
      }
      if (audio.readyState >= HTMLMediaElement.HAVE_METADATA) onFreshTrackReady();
      else audio.addEventListener("loadedmetadata", onFreshTrackReady, { once: true });
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", window.meelInitWatchPlayer);
  } else {
    window.meelInitWatchPlayer();
  }
})();
