// Audio engine — single <audio> instance per session. Persists across watch<->index AJAX transitions.
// Plyr wrapper moves with <audio> (compact mode via meel-engine-compact class).
(function () {
  "use strict";

  function clampGain(v) {
    var n = Number(v);
    return Number.isFinite(n) ? Math.max(-12, Math.min(12, n)) : 0;
  }

  function ensureEngineStyle() {
    if (document.getElementById("meel-audio-engine-style")) return;
    var style = document.createElement("style");
    style.id = "meel-audio-engine-style";
    // Mode compact (mini-player) menyembunyikan wrapper Plyr tanpa
    // melepas dari DOM / menghentikan playback.
    style.textContent =
      "#meel-audio-engine-root.meel-engine-compact{position:absolute!important;width:0!important;height:0!important;overflow:hidden!important;opacity:0;pointer-events:none;}";
    document.head.appendChild(style);
  }

  function createEngine() {
    ensureEngineStyle();
    // Root persisten, langsung child <body>
    // Di luar area yang akan di-innerHTML-replace oleh view-router.
    var root = document.createElement("div");
    root.id = "meel-audio-engine-root";
    root.setAttribute("data-meel-persist", "true");
    // Posisi default: disembunyikan sampai ada yang mount() dia.
    root.style.cssText =
      "position:fixed;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;";

    var audio = document.createElement("audio");
    audio.id = "meel-audio-engine";
    audio.preload = "none";
    audio.setAttribute("oncontextmenu", "return false;");
    root.appendChild(audio);
    document.body.appendChild(root);

    var player = null;
    try {
      player =
        typeof Plyr !== "undefined"
          ? new Plyr(audio, window.MEEL_PLYR_COMMON || {})
          : null;
    } catch (e) {
      console.error("❌ audio-engine: Plyr init error:", e);
    }

    // Gabungkan current/duration jadi format "[current / total]"
    if (player) {
      var _durEl =
        player.elements &&
        player.elements.display &&
        player.elements.display.duration;
      if (_durEl) _durEl.style.display = "none";

      function _formatTimeFallback(v) {
        var m = Math.floor(v / 60);
        var s = Math.floor(v % 60);
        return m + ":" + (s < 10 ? "0" : "") + s;
      }

      // Selama drag seek, ambil "current" dari slider seek (preview ikut
      // jari); listener dipasang langsung ke <audio> agar selalu menang.
      var _isSeekingNow = false;

      function _seekPreviewSeconds() {
        var _inp =
          player.elements &&
          player.elements.inputs &&
          player.elements.inputs.seek;
        if (!_inp) return null;
        var _max = parseFloat(_inp.max);
        if (!Number.isFinite(_max) || _max <= 0) _max = 100;
        var _v = parseFloat(_inp.value);
        if (!Number.isFinite(_v)) return null;
        var _d = Number.isFinite(audio.duration) ? audio.duration : 0;
        return (_v / _max) * _d;
      }

      function _updateCombinedTime() {
        var _curEl =
          player.elements &&
          player.elements.display &&
          player.elements.display.currentTime;
        if (!_curEl) return;
        var _prev = _isSeekingNow ? _seekPreviewSeconds() : null;
        var _cur =
          _prev !== null
            ? _prev
            : Number.isFinite(audio.currentTime)
              ? audio.currentTime
              : 0;
        var _dur = Number.isFinite(audio.duration) ? audio.duration : 0;
        var _fmt =
          typeof window.formatTime === "function"
            ? window.formatTime
            : _formatTimeFallback;
        // Belum ada metadata (durasi belum diketahui / masih buffering) →
        // tampilkan placeholder alih-alih "0:00 / 0:00" yang menyesatkan.
        _curEl.textContent =
          _dur > 0 ? _fmt(_cur) + " / " + _fmt(_dur) : "--:-- / --:--";
      }

      audio.addEventListener("timeupdate", _updateCombinedTime);
      audio.addEventListener("seeking", function () {
        _isSeekingNow = true;
        _updateCombinedTime();
      });
      audio.addEventListener("seeked", function () {
        _isSeekingNow = false;
        _updateCombinedTime();
      });
      audio.addEventListener("loadedmetadata", function () {
        // Reset flag seek saat ganti track — load() tidak memicu seeking/seeked.
        _isSeekingNow = false;
        _updateCombinedTime();
      });
      audio.addEventListener("durationchange", function () {
        _isSeekingNow = false;
        _updateCombinedTime();
      });
      _updateCombinedTime();
    }

    // AudioContext / EQ chain
    // createMediaElementSource() hanya boleh dipanggil sekali seumur hidup <audio>.
    var ctx = null,
      analyser = null,
      sourceNode = null,
      eqFilters = [],
      eqReady = false;
    var eqBands = [60, 170, 350, 1000, 3500, 10000];
    var eqGains = eqBands.map(function () {
      return 0;
    });
    var eqEnabled = false;

    function ensureAudioContext() {
      if (ctx && ctx.state !== "closed") {
        if (ctx.state === "suspended") ctx.resume().catch(function () {});
        return true;
      }
      try {
        ctx = new (window.AudioContext || window.webkitAudioContext)({
          latencyHint: "playback",
          sampleRate: 48000,
        });
        if (ctx.resume) ctx.resume().catch(function () {});
        analyser = ctx.createAnalyser();
        analyser.fftSize = 256;
        sourceNode = ctx.createMediaElementSource(audio);
        eqFilters = [];
        var node = sourceNode;
        eqBands.forEach(function (freq, i) {
          var f = ctx.createBiquadFilter();
          f.type = "peaking";
          f.frequency.value = freq;
          f.Q.value = 1;
          f.gain.value = eqEnabled ? clampGain(eqGains[i]) : 0;
          node.connect(f);
          node = f;
          eqFilters.push(f);
        });
        node.connect(analyser);
        analyser.connect(ctx.destination);
        eqReady = true;
        return true;
      } catch (e) {
        console.error("❌ audio-engine: AudioContext error:", e);
        return false;
      }
    }

    function applyEq() {
      if (!eqFilters.length) return;
      eqFilters.forEach(function (f, i) {
        f.gain.value = eqEnabled ? clampGain(eqGains[i]) : 0;
      });
    }

    var currentTrackId = null;
    var handlers = {}; // di-replace via setHandlers() tiap halaman aktif —
    // hindari dua handler sekaligus (mis. next-song watch vs index).
    var freshLoadPending = false;

    function fire(name) {
      if (handlers[name]) {
        try {
          handlers[name]();
        } catch (e) {
          console.error("❌ audio-engine handler '" + name + "' error:", e);
        }
      }
    }
    audio.addEventListener("play", function () {
      fire("onPlay");
    });
    audio.addEventListener("pause", function () {
      fire("onPause");
    });
    audio.addEventListener("timeupdate", function () {
      fire("onTimeUpdate");
    });
    audio.addEventListener("loadedmetadata", function () {
      fire("onLoadedMetadata");
    });
    audio.addEventListener("ended", function () {
      fire("onEnded");
    });
    audio.addEventListener("error", function () {
      fire("onError");
    });

    var engine = {
      audio: audio,
      player: player,
      root: root,
      eqBands: eqBands.slice(),

      getEqState: function () {
        return { enabled: eqEnabled, gains: eqGains.slice() };
      },
      setEqEnabled: function (v) {
        eqEnabled = !!v;
        if (eqEnabled && !eqReady) ensureAudioContext();
        applyEq();
      },
      isEqEnabled: function () {
        return eqEnabled;
      },
      setEqGains: function (gains) {
        eqGains = (gains || []).map(clampGain);
        while (eqGains.length < eqBands.length) eqGains.push(0);
        applyEq();
      },
      setEqGain: function (index, value) {
        eqGains[index] = clampGain(value);
        applyEq();
      },
      ensureAudioContext: ensureAudioContext,
      isEqReady: function () {
        return eqReady;
      },
      getAnalyser: function () {
        return analyser;
      },

      // LOOP: satu-satunya titik ubah state loop
      // Tulis semua representasi secara atomik (media.loop, player.loop,
      // localStorage) agar tidak pernah divergen (desync index<->watch).
      setLoop: function (active) {
        active = !!active;
        audio.loop = active;
        if (player) player.loop = active;
        try {
          localStorage.setItem(MEEL_KEYS.GLOBAL_LOOP, String(active));
        } catch (e) {}
        return active;
      },

      getCurrentTrackId: function () {
        return currentTrackId;
      },
      // true kalau loadTrack() TERAKHIR benar-benar ganti src (bukan no-op).
      wasFreshLoad: function () {
        var v = freshLoadPending;
        freshLoadPending = false;
        return v;
      },

      setHandlers: function (h) {
        handlers = h || {};
      },

      // Reparent wrapper Plyr ke container baru tanpa reset src — kunci gapless.
      mount: function (container, opts) {
        opts = opts || {};
        if (!container) return;
        var node = (player && player.elements && player.elements.container) || root;
        root.style.cssText = "";
        root.className = opts.compact ? "meel-engine-compact" : "";
        if (root.parentNode !== container) {
          container.appendChild(root);
        }
      },

      // Ganti track HANYA kalau id beda; kalau sama, no-op total (gapless).
      loadTrack: function (meta, opts) {
        opts = opts || {};
        var id = meta && (meta.id != null ? meta.id : meta.musicId);
        if (id != null && currentTrackId != null && String(id) === String(currentTrackId)) {
          return false;
        }
        currentTrackId = id;
        freshLoadPending = true;
        audio.pause();
        audio.src = meta.streamUrl || "stream?id=" + id;
        audio.loop = !!meta.isLooping;
        // Sinkronkan Plyr config.loop.active — state loop self-consistent.
        if (player) player.loop = !!meta.isLooping;
        audio.load();
        var startTime = opts.startTime || 0;
        function onReady() {
          if (startTime > 0) audio.currentTime = startTime;
          if (opts.autoplay) audio.play().catch(function () {});
        }
        if (audio.readyState >= 1) onReady();
        else audio.addEventListener("loadedmetadata", onReady, { once: true });
        return true;
      },

      // Method eksplisit untuk keluar total dari app musik — tidak
      // dipanggil otomatis saat transisi mini<->full.
      destroy: function () {
        try {
          if (player && player.destroy) player.destroy();
        } catch (e) {}
        try {
          if (ctx && ctx.close) ctx.close();
        } catch (e) {}
        try {
          root.remove();
        } catch (e) {}
        window.__meelAudioEngine = null;
      },
    };
    return engine;
  }

  window.meelGetAudioEngine = function () {
    if (!window.__meelAudioEngine) {
      window.__meelAudioEngine = createEngine();
    }
    return window.__meelAudioEngine;
  };
})();
