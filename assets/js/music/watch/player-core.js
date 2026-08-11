/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* player-core.js — Bootstrap player watch.php, sekarang berbasis
 * assets/js/shared/audio-engine.js yang persisten (bukan bikin `new
 * Plyr(audio)` + AudioContext sendiri tiap load).
 *
 * window.meelInitWatchPlayer() IDEMPOTENT & DIPANGGIL ULANG setiap kali
 * landing di view watch.php (baik full page load ASLI, MAUPUN AJAX
 * transition dari index.php lewat view-router.js) — karena DOM-nya selalu
 * baru tiap kali (#player-container, #cava-container, dst di-innerHTML-
 * replace). TAPI listener yang menempel ke `audio`/`player` (elemen yang
 * SAMA, tidak pernah dibuat ulang) hanya dipasang SEKALI (lihat
 * bindEngineOnce, di-guard `player.__meelCoreBound`), supaya tidak dobel
 * tiap toggle mini<->full.
 *
 * Logic "lagu baru" (FLAC loading-timeout, resume-modal, reset counter
 * posisi) HANYA jalan kalau engine.loadTrack() benar-benar ganti src
 * (isFreshTrack) — kalau cuma pindah tampilan pada lagu yang sama, semua
 * itu di-skip total supaya gapless & tidak ada resume-modal nongol lagi.
 */
(function () {
  "use strict";

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

  // Rebuild visualizer bars tiap #cava-container adalah node baru (per mount).
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

  // ── Semua listener yang menempel ke `audio`/`player` (elemen persisten) ──
  // Dipasang SEKALI SEUMUR SESI, tidak peduli berapa kali user
  // toggle mini<->full.
  function bindEngineOnce(engine) {
    if (engine.player.__meelCoreBound) return;
    engine.player.__meelCoreBound = true;

    const audio = engine.audio,
      player = engine.player;

    // ── FLAC loading-overlay & timeout ──
    let loadingTimeout = null,
      secondaryTimeout = null,
      metadataLoaded = false,
      loadRetried = false,
      errorHandled = false,
      audioEndedNaturally = false;

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
    // Dipanggil HANYA saat isFreshTrack (lihat meelInitWatchPlayer).
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
      errorHandled = true;
      audioEndedNaturally = false;
      clearAllTimeouts();
      hideLoadingOverlay();
      const errCode = audio.error ? audio.error.code : "?";
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
    });

    // ── Visualizer / bitrate — pakai analyser dari engine ──
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

    // Sync visual di-expose ke engine & dipanggil eksplisit tiap
    // landing — event 'play'/'pause' tidak fire saat loadTrack() no-op.
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
      applyPlayingVisualState(true);
    });
    player.on("pause", function () {
      applyPlayingVisualState(false);
    });
    let lastSecond = -1;
    player.on("timeupdate", function () {
      if (!isFinished && player.currentTime > 0 && player.currentTime < player.duration - 1) {
        const sec = Math.floor(player.currentTime);
        if (sec !== lastSecond) {
          lastSecond = sec;
          localStorage.setItem(storageKeyMusic, player.currentTime);
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
      // Auto-next lewat AJAX router agar konsisten gapless.
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
    // Reset mode mini-player — interval saveAudioState() (5s) dari sesi
    // mini-mode sebelumnya tidak boleh terus menulis state.
    isMiniPlayerActive = false;
    const engine = window.meelGetAudioEngine();
    const slot = document.getElementById("player-audio-slot");
    if (!slot) return void console.error("❌ #player-audio-slot not found");
    if (!window.MEEL_MUSIC_CONFIG || !window.MEEL_MUSIC_CONFIG.id)
      return void console.error("❌ MEEL_MUSIC_CONFIG missing");
    if (typeof Plyr === "undefined") return void console.error("❌ Plyr not loaded");

    watchUrl = window.location.href;
    storageKeyMusic = "music_pos_" + window.MEEL_MUSIC_CONFIG.id;

    // Reparent (BUKAN re-create) elemen audio+Plyr ke slot watch.php ini.
    engine.mount(slot, { compact: false });
    audio = engine.audio;
    player = engine.player;
    window.player = player;
    if (!player) return void console.error("❌ Plyr init failed");

    bindEngineOnce(engine);

    // Sync visual playing-state eksplisit — event 'play'/'pause' tidak
    // fire saat loadTrack() no-op (transisi gapless).
    if (engine.__syncPlayingVisualState) {
      engine.__syncPlayingVisualState(!engine.audio.paused);
    }

    const globalLoop = "true" === localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP);
    loadEqState();
    updateEqUI();
    // EQ gain perlu di-reapply tiap landing — engine persisten tidak
    // reset sendiri saat pindah tampilan.
    applyEqToFilters();

    // Baca sessionStorage untuk resume lintas-halaman.
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

    // Baca & buang flag skip_resume_once SELALU di sini (sebelum early-return
    // gapless) agar tidak menekan resume-modal pada fresh-track load berikutnya.
    const skipFromIndex = sessionStorage.getItem(MEEL_KEYS.SKIP_RESUME_ONCE) === "true";
    if (skipFromIndex) sessionStorage.removeItem(MEEL_KEYS.SKIP_RESUME_ONCE);
    // Marker sesi mini-player in-memory — jangan interupsi lagu berikutnya
    // di sesi yang sama; dibersihkan saat pause/close eksplisit.
    if (skipFromIndex) window.__meelResumeSessionActive = true;

    // KUNCI GAPLESS: track ID sama → loadTrack() no-op total.
    const isFreshTrack = engine.loadTrack(
      {
        id: window.MEEL_MUSIC_CONFIG.id,
        streamUrl: window.MEEL_MUSIC_CONFIG.streamUrl,
        isLooping: savedLoop,
      },
      {
        // play() didelegasikan ke onFreshTrackReady() — jangan autoplay
        // langsung di sini (resume-modal race).
        autoplay: savedActive ? savedPlaying : false,
        startTime: savedActive ? savedTime : 0,
      },
    );

    buildVisualizerBars(engine);
    updateVisualizerUI(engine.__vis ? engine.__vis.isOn() : window.innerWidth >= 1024);

    if (!isFreshTrack) {
      // ── Mobile-only ──
      // iOS Safari menghentikan <audio> saat elemen dipindah antar-DOM
      // oleh view-router — sync ulang eksplisit.
      const wantStream = window.MEEL_MUSIC_CONFIG.streamUrl || "";
      const haveSrc = engine.audio.currentSrc || engine.audio.src || "";
      const wantId = String(window.MEEL_MUSIC_CONFIG.id);
      // Bandingkan param id (boundary-safe); haveId === null → dianggap cocok.
      let haveId = null;
      try {
        haveId = new URL(haveSrc, window.location.href).searchParams.get("id");
      } catch (e) {}
      if (wantStream && haveSrc && haveId !== null && haveId !== wantId) {
        // Resource termuat ≠ lagu halaman ini (quirk mobile) → muat ulang src
        // & pulihkan posisi/playback dari state tersimpan.
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
        // Audio berhenti karena detach → pulihkan posisi & playback.
        if (savedTime > 5 && engine.audio.currentTime < 1) {
          engine.audio.currentTime = savedTime;
        }
        engine.audio.play().catch(function () {});
      }
      // Track sama — hanya pindah tampilan: tanpa reset, tanpa resume-modal.
      // Sync loop via setter Plyr agar config.loop.active tidak stale.
      player.loop = engine.audio.loop;
      _applyLoopUI(player.loop);
      return;
    }

    // ── Hanya untuk track yang BENAR-BENAR baru ──
    // setLoop menyinkronkan media.loop + Plyr config + localStorage secara atomik.
    engine.setLoop(savedLoop);
    updateLoopUI();
    if (savedActive) sessionStorage.removeItem(MEEL_KEYS.AUDIO_STATE);
    if (engine.__armLoadingTimeout) engine.__armLoadingTimeout();

    const modalEl = document.getElementById("resume-modal"),
      btnResume = document.getElementById("btn-resume"),
      btnRestart = document.getElementById("btn-restart"),
      timeEl = document.getElementById("resume-time");
    if (modalEl && btnResume && btnRestart && timeEl) {
      // Arm one-shot skip HANYA kalau modal benar-benar bisa muncul
      // (savedActive false); kalau resume lintas-halaman, jangan di-arm.
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
              // Modal ditekan tapi ada posisi tersimpan
              // → auto putar dari awal.
              localStorage.removeItem(storageKeyMusic);
              audio.currentTime = 0;
              player.play();
            }
          } else {
            // Tidak ada posisi tersimpan → mulai putar; one-shot
            // skipResumeModalOnce "habis" di sini.
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