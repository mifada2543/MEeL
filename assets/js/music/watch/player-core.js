/* ============================================================
 * player-core.js — Inti player musik, jalan saat DOMContentLoaded: init Plyr, deteksi FLAC & loading overlay, resume posisi terakhir, visualizer canvas (cava-style bar), dan mode mini-player
 * (shell, kontrol play/pause/seek/next/prev, ganti lagu tanpa reload).
 * Yang tersisa di sini: init Plyr, FLAC loading overlay, resume posisi, visualizer, bitrate, dan handler play/pause/ended.
 * Depends on: state.js, utils.js, loop-ui.js, audio-state.js, equalizer.js, shared/plyr-config.js (MEEL_PLYR_COMMON), mini-player.js (updateMiniPlayerUI), shared/resume-modal.js (meelResumeModal)
 * ============================================================ */
document.addEventListener("DOMContentLoaded", () => {
  if (
    ((watchUrl = window.location.href),
    (audio = document.getElementById("main-player")),
    !audio)
  )
    return void console.error("❌ #main-player not found");
  if (!window.MEEL_MUSIC_CONFIG?.id)
    return void console.error("❌ MEEL_MUSIC_CONFIG missing");
  if (
    ((storageKeyMusic = "music_pos_" + window.MEEL_MUSIC_CONFIG.id),
    "undefined" == typeof Plyr)
  )
    return void console.error("❌ Plyr not loaded");
  // ── Deteksi FLAC & tambahkan event handler error/timeout ──
  const isFlac =
    audio.querySelector('source[type="audio/flac"]') !== null ||
    window.MEEL_MUSIC_CONFIG?.filename?.toLowerCase().endsWith(".flac");
  let loadingTimeout = null;
  let secondaryTimeout = null;
  let metadataLoaded = false;
  let loadRetried = false;
  const LOADING_TIMEOUT_MS = isFlac ? 20000 : 10000; // 20s untuk FLAC, 10s untuk lainnya
  // Fungsi untuk membersihkan semua timeout
  function clearAllTimeouts() {
    if (loadingTimeout) {
      clearTimeout(loadingTimeout);
      loadingTimeout = null;
    }
    if (secondaryTimeout) {
      clearTimeout(secondaryTimeout);
      secondaryTimeout = null;
    }
  }
  // Fungsi untuk menampilkan/tutup loading overlay
  function showLoadingOverlay(msg) {
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
      const container = document.getElementById("player-container");
      if (container) {
        container.style.position = "relative";
        container.appendChild(overlay);
      }
    }
    overlay.style.display = "flex";
    const txt = document.getElementById("flac-loading-text");
    if (txt) txt.textContent = msg || "Memuat audio...";
  }

  function hideLoadingOverlay() {
    const overlay = document.getElementById("flac-loading-overlay");
    if (overlay) overlay.style.display = "none";
  }
  let audioEndedNaturally = false;
  let errorHandled = false;
  function onAudioError(e) {
    if (errorHandled) return;
    errorHandled = true;
    audioEndedNaturally = false;
    clearAllTimeouts();
    hideLoadingOverlay();
    const errCode = audio.error ? audio.error.code : "?";
    const errMsg = audio.error ? audio.error.message : "Gagal memuat audio";
    console.error("❌ Audio error [" + errCode + "]:", errMsg);
    if (isFlac) {
      showLoadingOverlay(
        "⚠️ FLAC tidak dapat dimuat. Coba refresh halaman atau gunakan format lain.",
      );
    }
  }
  function onLoadedMetadata() {
    metadataLoaded = true;
    clearAllTimeouts();
    hideLoadingOverlay();
  }
  audio.addEventListener("error", onAudioError);
  audio.addEventListener("loadedmetadata", onLoadedMetadata, { once: true });
  loadingTimeout = setTimeout(() => {
    if (!metadataLoaded && !errorHandled) {
      console.warn(
        "⚠️ loadedmetadata timeout setelah " + LOADING_TIMEOUT_MS / 1000 + "s.",
      );
      showLoadingOverlay(
        "Memuat file besar... (" + (isFlac ? "FLAC" : "audio") + ")",
      );
      if (isFlac && audio && !loadRetried) {
        loadRetried = true;
        audio.load();
      }
      secondaryTimeout = setTimeout(() => {
        if (!metadataLoaded && !errorHandled) {
          hideLoadingOverlay();
          showLoadingOverlay(
            "⚠️ Waktu muat habis. Silakan refresh halaman atau coba format lain.",
          );
        }
      }, LOADING_TIMEOUT_MS);
    }
  }, LOADING_TIMEOUT_MS);
  function cleanupAudioListeners() {
    clearAllTimeouts();
    if (audio) {
      audio.removeEventListener("error", onAudioError);
      audio.removeEventListener("loadedmetadata", onLoadedMetadata);
    }
    hideLoadingOverlay();
  }
  window.addEventListener("beforeunload", cleanupAudioListeners);
  try {
    ((player = new Plyr(audio, {
      ...MEEL_PLYR_COMMON,
      controls: [
        "play",
        "progress",
        "current-time",
        "duration",
        "mute",
        "volume",
        "settings",
      ],
      settings: ["speed"],
    })),
      (window.player = player));
  } catch (U) {
    return void console.error("❌ Plyr init error:", U);
  }
  if (!player) return void console.error("❌ Plyr init failed");
  const e = document.getElementById("player-container"),
    t = document.getElementById("realtime-bitrate"),
    n = document.getElementById("cava-container");
  if (!e || !t || !n)
    return void console.error("❌ Required containers missing");
  const a = "true" === localStorage.getItem("meel_global_loop");
  ((player.loop = a), updateLoopUI(), loadEqState(), updateEqUI());
  let o = !1,
    i = 0,
    l = !1,
    r = a;
  const s = sessionStorage.getItem("meel_audio_state");
  if (s)
    try {
      const k = JSON.parse(s);
      (k.musicId ?? k.id) == window.MEEL_MUSIC_CONFIG.id &&
        ((o = !0),
        (i = k.currentTime),
        (l = k.isPlaying),
        void 0 !== k.isLooping &&
          ((r = k.isLooping),
          localStorage.setItem("meel_global_loop", String(k.isLooping))));
    } catch (O) {
      console.warn("⚠️ Bad audio state:", O);
    }
  localStorage.getItem(storageKeyMusic);
  let d,
    c = window.innerWidth >= 1024,
    u = [];
  function p() {
    let e = n.clientWidth;
    return (
      e <= 0 &&
        (e =
          window.innerWidth >= 1024
            ? 0.32 * window.innerWidth
            : window.innerWidth - 32),
      e < 180 ? 12 : e < 280 ? 18 : e < 400 ? 24 : e < 600 ? 32 : 40
    );
  }
  function m() {
    const e = p();
    if (u.length === e) return;
    ((u = []), (n.innerHTML = ""));
    const t = document.createDocumentFragment();
    for (let n = 0; n < e; n++) {
      const e = document.createElement("div");
      ((e.className =
        "flex-1 bg-gradient-to-t from-orange-600 to-orange-400 rounded-t-sm transition-all duration-75"),
        (e.style.cssText =
          "height:100%;min-width:1px;transform-origin:bottom;will-change:transform;transform:scaleY(0.04)"),
        t.appendChild(e),
        u.push(e));
    }
    n.appendChild(t);
  }
  (m(),
    window.addEventListener("resize", function () {
      (clearTimeout(d),
        (d = setTimeout(() => {
          u.length !== p() &&
            (m(), c && I && !player.paused && (cancelAnimationFrame(E), _()));
        }, 200)));
    }));
  const y = window.MEEL_MUSIC_CONFIG.fileSizeBytes;
  let g,
    w,
    f,
    E,
    h = 160,
    I = !1,
    q = !1;
  const S = () => {
    q = !0;
  };
  function v() {
    if (!q) return !1;
    if (g && "closed" !== g.state)
      return ("suspended" === g.state && g.resume(), !0);
    try {
      ((g = new (window.AudioContext || window.webkitAudioContext)({
        latencyHint: "playback",
        sampleRate: 48e3,
      })),
        g.resume && g.resume().catch(() => {}),
        (w = g.createAnalyser()),
        (f = g.createMediaElementSource(audio)),
        (eqFilters = []));
      let e = f;
      return (
        eqBands.forEach((t, n) => {
          const a = g.createBiquadFilter();
          ((a.type = "peaking"),
            (a.frequency.value = t),
            (a.Q.value = 1),
            (a.gain.value = normalizeEqValue(eqGains[n] ?? 0)),
            e.connect(a),
            (e = a),
            eqFilters.push(a));
        }),
        e.connect(w),
        w.connect(g.destination),
        (w.fftSize = 256),
        applyEqToFilters(),
        (I = !0),
        !0
      );
    } catch (e) {
      return (console.error("❌ AudioContext error:", e), !1);
    }
  }
  let _visLastTs = 0;
  function _() {
    if (!c || !I || player.paused) return void cancelAnimationFrame(E);
    const _now = performance.now();
    if (_now - _visLastTs < 33.4) return void (E = requestAnimationFrame(_));
    _visLastTs = _now;
    const e = new Uint8Array(w.frequencyBinCount);
    w.getByteFrequencyData(e);
    const n = u.length;
    if (0 === n) return void (E = requestAnimationFrame(_));
    for (let t = 0; t < n; t++) {
      const o = e[Math.floor(t * (e.length / n) * 0.7)],
        i = Math.max(4, (o / 255) * 100);
      u[t].style.transform = `scaleY(${(i / 100).toFixed(3)})`;
      const l = o / 255;
      let r = "#9ca3af";
      (l > 0.75
        ? (r = "#22c55e")
        : l > 0.5
          ? (r = "#FB923C")
          : l > 0.25 && (r = "#eab308"),
        (u[t].style.background = r));
    }
    E = requestAnimationFrame(_);
  }
  let bitrateTimer = null;
  function refreshBitrate() {
    if (!t || player.paused) return;
    if (!I && !v()) return;
    if (!I || !w) return;
    const e = new Uint8Array(w.frequencyBinCount);
    w.getByteFrequencyData(e);
    updateBitrateLabel(getRealtimeVbrValue(e), t);
  }
  function startBitrateLoop() {
    if (bitrateTimer) return;
    refreshBitrate();
    bitrateTimer = setInterval(refreshBitrate, 1e3);
  }
  function stopBitrateLoop() {
    if (bitrateTimer) {
      clearInterval(bitrateTimer);
      bitrateTimer = null;
    }
  }
  function meelResumeCtx() {
    g && "suspended" === g.state && g.resume().catch(() => {});
  }
  document.addEventListener("click", meelResumeCtx);
  document.addEventListener("keydown", meelResumeCtx);
  function b() {
    const e = document.getElementById("btn-vis"),
      t = document.getElementById("vis-text"),
      a = c;
    (_setTogglePillUI(e, a),
      t && (t.innerText = a ? "Vis On" : "Vis Off"),
      (n.style.display = a ? "flex" : "none"),
      n.classList.toggle("hidden", !a));
  }
  (document.addEventListener("click", S, { once: !0 }),
    document.addEventListener("keydown", S, { once: !0 }),
    (window.toggleEqualizer = function () {
      ((eqEnabled = !eqEnabled),
        eqEnabled ? (I ? applyEqToFilters() : v()) : applyEqToFilters(),
        updateEqUI(),
        saveEqState());
    }),
    (window.toggleVisualizer = function () {
      ((c = !c),
        b(),
        c
          ? (I || v(), startBitrateLoop(), !player.paused && _())
          : cancelAnimationFrame(E));
    }),
    setTimeout(b, 100));
  const L = document.getElementById("resume-modal"),
    M = document.getElementById("btn-resume"),
    T = document.getElementById("btn-restart"),
    x = document.getElementById("resume-time");
  if (L && M && T && x) {
    const _skipFromIndex =
      sessionStorage.getItem("skip_resume_once") === "true";
    sessionStorage.removeItem("skip_resume_once");
    if (_skipFromIndex) {
      skipResumeModalOnce = true;
    }
    function B() {
      return window.meelResumeModal({
        storageKey: storageKeyMusic,
        durationMargin: 5,
        countdownPrefix: "Otomatis putar dari awal dalam",
        countdownDoneText: "Otomatis putar dari awal...",
        skipOnce: () => {
          if (skipResumeModalOnce) {
            skipResumeModalOnce = !1;
            return !0;
          }
          return !1;
        },
        onShow: () => {
          audio.autoplay = player.autoplay = !1;
          audio.currentTime = parseFloat(localStorage.getItem(storageKeyMusic));
        },
        onResume: (pos) => {
          player.currentTime = pos;
          player.play();
        },
        onRestart: () => {
          localStorage.removeItem(storageKeyMusic);
          audio.currentTime = 0;
          player.play();
        },
      });
    }
    player.on("ready", () => {
      y > 0 &&
        player.duration > 0 &&
        (h = Math.round((8 * y) / (1e3 * player.duration)));
      const e = document.querySelector(".plyr");
      if ((e && ((e.tabIndex = 0), e.focus()), o)) {
        ((player.loop = r),
          localStorage.setItem("meel_global_loop", String(r)),
          updateLoopUI(),
          sessionStorage.removeItem("meel_audio_state"));
        const _savedPos = localStorage.getItem(storageKeyMusic);
        if (
          _savedPos &&
          parseFloat(_savedPos) > 10 &&
          (!player.duration || parseFloat(_savedPos) < player.duration - 5)
        ) {
          B();
        }
        if (L && L.classList.contains("hidden")) {
          const e = () => {
            ((player.currentTime = Math.max(0, i)),
              l && player.play().catch(() => {}));
          };
          audio.readyState >= HTMLMediaElement.HAVE_METADATA
            ? e()
            : audio.addEventListener("loadedmetadata", e, { once: !0 });
        }
      } else {
        const e = localStorage.getItem(storageKeyMusic);
        e &&
        parseFloat(e) > 10 &&
        (!player.duration || parseFloat(e) < player.duration - 5)
          ? B()
          : player.play().catch(() => {});
      }
    });
  }
  const A = () => document.querySelector(".vinyl-wrap .vinyl-spin");
  (player.on("play", () => {
    window.meelHealthAlertActive
      ? player.pause()
      : ((isFinished = !1),
        e.classList.add("playing"),
        A()?.classList.add("playing"),
        I || v(),
        startBitrateLoop(),
        c && _(),
        window.updateMiniPlayerUI());
  }),
    player.on("pause", () => {
      (e.classList.remove("playing"),
        A()?.classList.remove("playing"),
        cancelAnimationFrame(E),
        stopBitrateLoop(),
        window.updateMiniPlayerUI());
    }));
  let F = -1;
  (player.on("timeupdate", () => {
    if (
      !isFinished &&
      player.currentTime > 0 &&
      player.currentTime < player.duration - 1
    ) {
      const e = Math.floor(player.currentTime);
      e !== F &&
        ((F = e), localStorage.setItem(storageKeyMusic, player.currentTime));
    }
    window.updateMiniPlayerUI();
  }),
    player.on("loadedmetadata", window.updateMiniPlayerUI),
    player.on("ended", () => {
      // Jeda kesehatan (20-20-20) aktif → jangan auto-next ke lagu lain.
      if (window.meelHealthAlertActive) return;
      const isGenuineEnd =
        audioEndedNaturally ||
        (player.duration > 0 &&
          Math.abs(player.currentTime - player.duration) < 1.5) ||
        (player.currentTime > 0 && !audio.error && audio.ended === true);
      if (!isGenuineEnd) {
        console.warn(
          "⚠️ ended fired tapi bukan natural end — skip redirect. err=",
          !!audio.error,
        );
        return;
      }
      if (isNavigating) return;
      isNavigating = true;
      stopBitrateLoop();
      const e = window.MEEL_MUSIC_CONFIG.nextSongUrl;
      if (e) window.location.href = e;
      else {
        localStorage.removeItem(storageKeyMusic);
        const e = document.querySelector(".rekomendasi-item");
        if (e) window.location.href = e.href;
        else isNavigating = false;
      }
    }),
    player.on("timeupdate", () => {
      if (
        player.duration > 0 &&
        !player.paused &&
        player.currentTime >= player.duration - 0.5
      ) {
        audioEndedNaturally = true;
      }
    }));
});
