const config = window.playerConfig || {};
let videoElement,
  player,
  hls,
  videoSrc = config.videoSrc || "",
  isHls = config.isHls || !1,
  vttSrc = config.vttSrc || "",
  videoId = config.id || "",
  videoTitle = config.title || "",
  videoUploader = config.uploader || "",
  storageKeyVideo = `video_pos_${videoId}`,
  isAutoRecovering = !1,
  isRecovering = !1,
  isCheckingStatus = !1,
  waitingTimeout = null,
  recoveryRetryCount = 0;
const MAX_RECOVERY_RETRIES = 20;
let lastSuccessfulRecovery = 0;
const POST_RECOVERY_COOLDOWN_MS = 5e3;
let hasEverPlayed = !1,
  playbackStartTimeout = null;
const PLAYBACK_START_TIMEOUT_MS = 2e4;
let recoveryDelay = 1e4,
  lastRecoveryTime = 0,
  recoveryTimeoutId = null,
  playbackStartTimestamp = 0,
  lastLocalStorageSave = 0;
const LOCAL_STORAGE_THROTTLE_MS = 5e3;
let lastPlayTime = -1,
  lastTimeUpdateTimestamp = Date.now(),
  stuckCheckInterval = null,
  isTransitioningNext = !1,
  nextVideoTransitionId = 0;
const isTouchDevice = "ontouchstart" in window || navigator.maxTouchPoints > 0;
let glowRAF = null,
  glowLastSampleTime = 0;
const GLOW_W = 16,
  GLOW_H = 9,
  GLOW_SAMPLE_INTERVAL = 120,
  GLOW_LERP_FACTOR = 0.1;
let glowTargetData = new Float32Array(GLOW_W * GLOW_H * 4),
  glowCurData = new Float32Array(GLOW_W * GLOW_H * 4),
  glowStartFn = null,
  glowStopFn = null,
  glowEnabled = "false" !== localStorage.getItem("meel_glow_enabled"),
  glowNavbar = null;
window.lucide && lucide.createIcons();
const plyrOptions = {
    iconUrl: "../assets/plyr.svg",
    controls: [
      "play-large",
      "play",
      "progress",
      "current-time",
      "duration",
      "mute",
      "volume",
      "captions",
      "settings",
      "pip",
      "airplay",
      "fullscreen",
    ],
    settings: ["quality", "speed"],
    speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
    tooltips: { controls: !0, seek: !0 },
    i18n: {
      play: "Putar video",
      pause: "Jeda video",
      restart: "Putar ulang",
      rewind: "Mundur 10 detik",
      forward: "Maju 10 detik",
      seek: "Cari posisi",
      currentTime: "Waktu saat ini",
      duration: "Durasi",
      volume: "Volume",
      mute: "Bisukan",
      unmute: "Suarakan",
      captions: "Teks",
      settings: "Pengaturan",
      fullscreen: "Layar penuh",
      exitFullscreen: "Keluar layar penuh",
      pip: "Gambar dalam gambar",
      airplay: "AirPlay",
      qualityLabel: {},
    },
    fullscreen: { enabled: !0, fallback: !0, iosNative: !1 },
    clickToPlay: !isTouchDevice,
    keyboard: { focused: !0, global: !0 },
    previewThumbnails: { enabled: "" !== vttSrc, src: vttSrc },
    mediaMetadata: {},
  },
  HLS_CONFIG = {
    maxBufferLength: 45,
    maxMaxBufferLength: 90,
    maxBufferHole: 0.5,
    nudgeMaxRetry: 5,
    nudgeOffset: 0.1,
    enableWorker: !0,
    backBufferLength: 10,
    lowLatencyMode: !1,
    startLevel: -1,
    abrEwmaDefaultEstimate: 5e5,
    fragLoadingTimeOut: 2e4,
    manifestLoadingTimeOut: 1e4,
  };
function stopPlaybackStartTimeout() {
  playbackStartTimeout &&
    (clearTimeout(playbackStartTimeout), (playbackStartTimeout = null));
}
function startPlaybackStartTimeout() {
  (stopPlaybackStartTimeout(),
    (playbackStartTimeout = setTimeout(() => {
      hasEverPlayed ||
        (console.warn(
          "Video tidak kunjung mulai putar (>" +
            PLAYBACK_START_TIMEOUT_MS / 1e3 +
            " detik), trigger recovery.",
        ),
        triggerPlayerRecovery());
    }, PLAYBACK_START_TIMEOUT_MS)));
}
function destroyPlayer() {
  /* Clean up glow RAF to prevent stale videoElement reference */
  if (glowRAF) {
    cancelAnimationFrame(glowRAF);
    glowRAF = null;
  }
  if ((stopStuckDetector(), stopPlaybackStartTimeout(), player)) {
    try {
      player.destroy();
    } catch (e) {
      console.error("Gagal destroy player:", e);
    }
    player = null;
  }
  if (hls) {
    try {
      hls.destroy();
    } catch (e) {
      console.error("Gagal destroy hls:", e);
    }
    hls = null;
  }
}
function showReconnectingIndicator() {
  const e = document.getElementById("main-video-wrapper");
  if (!e) return;
  const t = document.getElementById("meel-reconnect-indicator");
  t && t.remove();
  const n = document.createElement("div");
  ((n.id = "meel-reconnect-indicator"),
    (n.className =
      "absolute inset-0 bg-[#080a0f]/95 flex flex-col items-center justify-center z-[100] text-white gap-3 p-4 text-center rounded-none sm:rounded-none"),
    (n.innerHTML =
      '\n    <div class="animate-spin h-8 w-8 border-4 border-red-600 border-t-transparent rounded-full"></div>\n    <div class="text-sm font-bold uppercase tracking-wider text-white">Sambungan Media Terputus</div>\n    <div class="text-xs text-gray-500">Mencoba menghubungkan kembali secara otomatis...</div>\n  '),
    e.appendChild(n));
}
function checkMediaAndRecover() {
  if (isCheckingStatus) return;
  if ((recoveryRetryCount++, recoveryRetryCount > MAX_RECOVERY_RETRIES)) {
    (console.warn("Batas percobaan pemulihan tercapai, berhenti mencoba."),
      (isCheckingStatus = !1),
      (recoveryRetryCount = 0));
    const e = document.getElementById("meel-reconnect-indicator");
    return void (
      e &&
      (e.innerHTML =
        '\n        <div class="flex flex-col items-center gap-3 p-4 text-center">\n          <div class="text-xs text-gray-500">Tidak dapat terhubung ke media.</div>\n          <button onclick="window.location.reload()" class="px-5 py-2.5 bg-red-600 hover:bg-red-500 text-white text-xs font-bold rounded-xl transition-all border-none cursor-pointer">Muat Ulang Halaman</button>\n        </div>\n      ')
    );
  }
  ((isCheckingStatus = !0),
    showReconnectingIndicator(),
    console.log(`Mengecek ketersediaan file media di: ${videoSrc}`));
  const e = new AbortController(),
    t = setTimeout(() => {
      e.abort();
    }, 3e3);
  fetch(videoSrc, { method: "HEAD", signal: e.signal })
    .then((e) => {
      clearTimeout(t);
      const n = e.headers.get("content-type") || "";
      if (e.ok && !n.includes("text/html")) {
        (console.log("Media terdeteksi online! Memulai pemulihan via HTMX..."),
          (recoveryRetryCount = 0),
          (lastSuccessfulRecovery = Date.now()));
        const e = player ? player.currentTime : 0;
        e > 0 && localStorage.setItem(storageKeyVideo, e);
        const t = document.getElementById("meel-reconnect-indicator");
        (t && t.remove(),
          (isRecovering = !0),
          (isAutoRecovering = !0),
          window.htmx
            ? htmx.ajax("GET", window.location.href, {
                target: "#main-video-wrapper",
                select: "#main-video-wrapper",
                swap: "outerHTML",
              })
            : window.location.reload(),
          (isCheckingStatus = !1));
      } else
        (console.log(
          "Media masih offline (kembalian server bukan file media). Menguji ulang dalam 3 detik...",
        ),
          setTimeout(() => {
            ((isCheckingStatus = !1), checkMediaAndRecover());
          }, 3e3));
    })
    .catch((e) => {
      (clearTimeout(t),
        console.log(
          "Koneksi media gagal/offline atau timeout. Menguji ulang dalam 3 detik...",
        ),
        setTimeout(() => {
          ((isCheckingStatus = !1), checkMediaAndRecover());
        }, 3e3));
    });
}
function triggerPlayerRecovery() {
  if (isRecovering || isCheckingStatus || isTransitioningNext) return;
  if (player && player.paused && hasEverPlayed)
    return void console.log("Video sedang di-paused, skip recovery.");
  const e = Date.now();
  lastSuccessfulRecovery > 0 &&
  e - lastSuccessfulRecovery < POST_RECOVERY_COOLDOWN_MS
    ? console.log(
        "Masih dalam masa cooldown pasca-recovery (" +
          Math.round(
            (POST_RECOVERY_COOLDOWN_MS - (e - lastSuccessfulRecovery)) / 1e3,
          ) +
          "s lagi), skip recovery.",
      )
    : e - lastRecoveryTime < recoveryDelay
      ? console.log("Menunda pemulihan: masih dalam masa cooldown.")
      : ((lastRecoveryTime = e), stopStuckDetector(), checkMediaAndRecover());
}
function startStuckDetector() {
  (stopStuckDetector(),
    (stuckCheckInterval = setInterval(() => {
      if (
        !player ||
        (hasEverPlayed && player.paused) ||
        isRecovering ||
        isTransitioningNext
      )
        return;
      if (document.hidden) return;
      const e = player.currentTime,
        t = Date.now();
      e === lastPlayTime
        ? (t - lastTimeUpdateTimestamp) / 1e3 >= 6 && triggerPlayerRecovery()
        : ((lastPlayTime = e), (lastTimeUpdateTimestamp = t));
    }, 2e3)));
}
function stopStuckDetector() {
  stuckCheckInterval &&
    (clearInterval(stuckCheckInterval), (stuckCheckInterval = null));
}
function registerHlsErrorListener(e) {
  e.on(Hls.Events.ERROR, function (t, n) {
    if (n.fatal)
      switch ((console.warn("Fatal HLS error encountered:", n.type), n.type)) {
        case Hls.ErrorTypes.NETWORK_ERROR:
          triggerPlayerRecovery();
          break;
        case Hls.ErrorTypes.MEDIA_ERROR:
          e.recoverMediaError();
          break;
        default:
          triggerPlayerRecovery();
      }
  });
}
function registerVideoErrorListener(e) {
  e &&
    (e.dataset.meelErrorRegistered ||
      ((e.dataset.meelErrorRegistered = "1"),
      e.addEventListener("error", () => {
        const t = e.error;
        t &&
          (console.warn(
            "HTML5 video error:",
            t.message || "Unknown",
            "code:",
            t.code,
          ),
          (2 !== t.code && 3 !== t.code && 4 !== t.code) ||
            triggerPlayerRecovery());
      }),
      e.addEventListener("stalled", () => {
        (console.warn("Video stalled, memulai waiting timeout..."),
          startWaitingTimeout());
      })));
}
function startWaitingTimeout() {
  (stopWaitingTimeout(),
    (waitingTimeout = setTimeout(() => {
      (console.warn(
        "Video menunggu data terlalu lama (>10 detik), trigger recovery",
      ),
        triggerPlayerRecovery());
    }, 1e4)));
}
function stopWaitingTimeout() {
  waitingTimeout && (clearTimeout(waitingTimeout), (waitingTimeout = null));
}
function initPlayer() {
  ((videoElement = document.getElementById("main-video")),
    videoElement &&
      (isHls && window.Hls && Hls.isSupported()
        ? ((hls = new Hls(HLS_CONFIG)),
          registerHlsErrorListener(hls),
          hls.loadSource(videoSrc),
          hls.attachMedia(videoElement),
          hls.on(Hls.Events.MANIFEST_PARSED, function () {
            const e = hls.levels.map((e) => e.bitrate);
            (e.length > 1 &&
              ((plyrOptions.quality = {
                default: e[0],
                options: e,
                forced: !0,
                onChange: (e) => {
                  const t = hls.levels.findIndex((t) => t.bitrate === e);
                  hls.currentLevel = t;
                },
              }),
              (plyrOptions.i18n = { ...plyrOptions.i18n, qualityLabel: {} }),
              hls.levels.forEach((e) => {
                const t = e.name
                  ? e.name
                  : `${e.height}p (${Math.round(e.bitrate / 1e3)}kbps)`;
                plyrOptions.i18n.qualityLabel[e.bitrate] = t;
              })),
              player ||
                ((player = new Plyr(videoElement, plyrOptions)),
                setupMeelPlayerEvents()));
          }))
        : ((player = new Plyr(videoElement, plyrOptions)),
          isHls && (videoElement.src = videoSrc),
          setupMeelPlayerEvents()),
      registerVideoListeners()));
}
function registerVideoListeners() {
  videoElement && registerVideoErrorListener(videoElement);
}
function setupMeelPlayerEvents() {
  window.player = player;
  const e = document.getElementById("resume-modal"),
    t = document.getElementById("btn-resume"),
    n = document.getElementById("btn-restart"),
    o = document.getElementById("resume-time"),
    l = document.getElementById("resume-countdown");
  function a() {
    const e = document.getElementById("main-video-wrapper"),
      t = videoElement;
    if (!(e && t && t.videoWidth && t.videoHeight)) return;
    const n = t.videoWidth,
      o = t.videoHeight,
      l = (e, t) => (0 === t ? e : l(t, e % t)),
      a = l(n, o);
    (console.log(`[MEeL] Aspect ratio video: ${n / a}:${o / a} (${n}x${o})`),
      isMiniPlayerActive || (e.style.aspectRatio = `${n} / ${o}`));
  }
  (videoElement.readyState >= 1 && videoElement.videoWidth
    ? a()
    : videoElement.addEventListener("loadedmetadata", a, { once: !0 }),
    player.on("ready", (r) => {
      if (
        ("function" == typeof window.appendCustomSettings &&
          setTimeout(window.appendCustomSettings, 0),
        videoElement && !isHls && (videoElement.preload = "auto"),
        a(),
        vttSrc)
      )
        setTimeout(() => refreshVttSprites(vttSrc), 300);
      else {
        player.config.previewThumbnails.enabled = !1;
        const e = document.querySelector(".plyr__preview-thumb");
        e && (e.style.display = "none");
      }
      setTimeout(() => {
        if (!player?.elements?.controls) return;
        const e = player.elements.controls;
        if (e.querySelector('[data-plyr="meel-miniplayer"]')) return;
        const t = e.querySelector('[data-plyr="pip"]');
        if (!t) return;
        const n = document.createElement("button");
        ((n.className = "plyr__control"),
          n.setAttribute("data-plyr", "meel-miniplayer"),
          n.setAttribute("type", "button"),
          n.setAttribute("aria-label", "Mini Player"),
          (n.title = "Mini Player"),
          (n.innerHTML =
            '<i data-lucide="shrink" style="width:18px;height:18px;"></i>'),
          n.addEventListener("click", (e) => {
            (e.stopPropagation(), window.toggleMiniPlayer());
          }),
          t.parentNode.insertBefore(n, t.nextSibling),
          window.lucide && lucide.createIcons());
      }, 200);
      const i = localStorage.getItem(storageKeyVideo);
      if (isAutoRecovering && i)
        return (
          (isAutoRecovering = !1),
          (player.currentTime = parseFloat(i)),
          player.play().catch(() => {}),
          startStuckDetector(),
          void startPlaybackStartTimeout()
        );
      function s() {
        if (
          i &&
          parseFloat(i) > 10 &&
          (!player.duration || parseFloat(i) < player.duration - 10)
        ) {
          const a = Math.floor(i / 60),
            r = Math.floor(i % 60);
          (o && (o.innerText = `${a}:${r.toString().padStart(2, "0")}`),
            e && e.classList.remove("hidden"));
          let s = 15;
          const c = setInterval(() => {
              (s--,
                s > 0
                  ? l &&
                    (l.innerText = `Otomatis ulang dari awal dalam ${s}s...`)
                  : clearInterval(c));
            }, 1e3),
            d = setTimeout(() => {
              n && n.click();
            }, 15e3);
          (t &&
            (t.onclick = () => {
              (clearTimeout(d),
                clearInterval(c),
                (player.currentTime = parseFloat(i)),
                player.play(),
                e.classList.add("hidden"));
            }),
            n &&
              (n.onclick = () => {
                (clearTimeout(d),
                  clearInterval(c),
                  localStorage.removeItem(storageKeyVideo),
                  (player.currentTime = 0),
                  player.play(),
                  e.classList.add("hidden"));
              }));
        } else
          (player.play().catch(() => console.log("Menunggu interaksi user...")),
            startStuckDetector(),
            startPlaybackStartTimeout());
      }
      if (isHls && hls) {
        let e = !1;
        const t = setTimeout(() => {
          e || ((e = !0), s());
        }, 8e3);
        hls.on(Hls.Events.FRAG_BUFFERED, function n() {
          if (e) hls.off(Hls.Events.FRAG_BUFFERED, n);
          else
            try {
              const o = videoElement.buffered;
              o.length > 0 &&
                o.end(0) - (videoElement.currentTime || 0) >= 15 &&
                (clearTimeout(t),
                (e = !0),
                hls.off(Hls.Events.FRAG_BUFFERED, n),
                s());
            } catch (e) {}
        });
      } else s();
    }),
    player.on("controlsshown", () => {}),
    player.on("play", () => {
      (stopPlaybackStartTimeout(),
        (playbackStartTimestamp = Date.now()),
        (lastTimeUpdateTimestamp = Date.now()),
        player && (lastPlayTime = player.currentTime),
        startStuckDetector());
    }),
    player.on("playing", () => {
      ((hasEverPlayed = !0),
        stopPlaybackStartTimeout(),
        (lastTimeUpdateTimestamp = Date.now()),
        player && (lastPlayTime = player.currentTime),
        startStuckDetector());
    }),
    player.on("pause", () => {
      (stopStuckDetector(),
        stopWaitingTimeout(),
        stopPlaybackStartTimeout(),
        player.currentTime > 0 &&
          (localStorage.setItem(storageKeyVideo, player.currentTime),
          (lastLocalStorageSave = Date.now())));
    }),
    player.on("seeking", () => {
      const e = Date.now();
      ((lastTimeUpdateTimestamp = e),
        (lastLocalStorageSave = e),
        player &&
          (localStorage.setItem(storageKeyVideo, player.currentTime),
          (lastPlayTime = player.currentTime)));
    }),
    player.on("seeked", () => {
      ((lastTimeUpdateTimestamp = Date.now()),
        player && (lastPlayTime = player.currentTime));
    }),
    player.on("timeupdate", () => {
      if (player.currentTime > 0) {
        const e = Date.now();
        ((lastPlayTime = player.currentTime),
          (lastTimeUpdateTimestamp = e),
          e - lastLocalStorageSave >= LOCAL_STORAGE_THROTTLE_MS &&
            (localStorage.setItem(storageKeyVideo, player.currentTime),
            (lastLocalStorageSave = e)),
          playbackStartTimestamp > 0 &&
            e - playbackStartTimestamp > 5e3 &&
            ((recoveryDelay = 5e3), (playbackStartTimestamp = 0)));
      }
      stopWaitingTimeout();
    }),
    player.on("waiting", () => {
      startWaitingTimeout();
    }),
    player.on("playing", () => {
      stopWaitingTimeout();
    }),
    player.on("canplay", () => {
      stopWaitingTimeout();
    }),
    player.on("ended", async () => {
      if ((stopStuckDetector(), player.loop)) return;
      if (isTransitioningNext) return;
      ((isTransitioningNext = !0), (isRecovering = !0));
      const e = ++nextVideoTransitionId;
      localStorage.removeItem(storageKeyVideo);
      const t = document.querySelector(".rekomendasi-item");
      if (!t) return ((isTransitioningNext = !1), void (isRecovering = !1));
      const n = player.fullscreen.active || !!document.fullscreenElement;
      try {
        const o = await fetch(t.href),
          l = await o.text();
        if (e !== nextVideoTransitionId) return;
        const a = new DOMParser().parseFromString(l, "text/html");
        ((watchUrl = t.href),
          window.history.pushState({}, "", t.href),
          (document.title = a.title));
        const r = a.getElementById("main-video");
        if (!r) throw new Error("Video elemen tidak ditemukan");
        const i = r.getAttribute("data-src"),
          s = "true" === r.getAttribute("data-ishls"),
          c = r.getAttribute("data-poster"),
          d = r.getAttribute("data-vtt");
        ((videoId =
          new URL(t.href, window.location.href).searchParams.get("id") ||
          videoId),
          (storageKeyVideo = `video_pos_${videoId}`),
          (vttSrc = d));
        let p = {};
        (a.querySelectorAll("script:not([src])").forEach((e) => {
          const t = e.textContent.match(
            /window\.playerConfig\s*=\s*(\{[\s\S]*?\});/,
          );
          if (t)
            try {
              p = new Function("return " + t[1])();
            } catch (e) {}
        }),
          (videoTitle = p.title || ""),
          (videoUploader = p.uploader || ""),
          (window.playerConfig = {
            videoSrc: i,
            isHls: s,
            vttSrc: d,
            id: videoId,
            title: videoTitle,
            uploader: videoUploader,
          }),
          isMiniPlayerActive && updateMiniPlayerInfo(videoTitle, videoUploader),
          updateSearchExcludeId(videoId),
          ["watch-details-wrapper", "recommendation-column"].forEach((e) => {
            const t = document.getElementById(e),
              n = a.getElementById(e);
            t && n && (t.innerHTML = n.innerHTML);
          }),
          window.lucide && window.lucide.createIcons(),
          window.htmx && htmx.process(document.body),
          isMiniPlayerActive ||
            requestAnimationFrame(() => {
              const e = document.getElementById("desc-text"),
                t = document.getElementById("btn-read-more");
              e &&
                t &&
                e.scrollHeight > e.clientHeight &&
                t.classList.remove("hidden");
            }),
          (player.poster = c),
          s
            ? (!hls && window.Hls && Hls.isSupported()
                ? ((hls = new Hls(HLS_CONFIG)),
                  registerHlsErrorListener(hls),
                  hls.attachMedia(player.media))
                : hls &&
                  hls.media !== player.media &&
                  (hls.detachMedia(), hls.attachMedia(player.media)),
              hls.loadSource(i),
              videoElement.addEventListener(
                "loadedmetadata",
                function () {
                  var e = document.getElementById("main-video-wrapper");
                  e &&
                    videoElement &&
                    videoElement.videoWidth &&
                    videoElement.videoHeight &&
                    (e.style.aspectRatio =
                      videoElement.videoWidth + "/" + videoElement.videoHeight);
                },
                { once: !0 },
              ))
            : (hls && (hls.destroy(), (hls = null)),
              (player.media.src = i),
              player.media.load(),
              videoElement.addEventListener(
                "loadedmetadata",
                function () {
                  var e = document.getElementById("main-video-wrapper");
                  e &&
                    videoElement &&
                    videoElement.videoWidth &&
                    videoElement.videoHeight &&
                    (e.style.aspectRatio =
                      videoElement.videoWidth + "/" + videoElement.videoHeight);
                },
                { once: !0 },
              )));
        const u = player.play();
        if (
          (void 0 !== u &&
            u.catch((e) => {
              console.error("Autoplay dicegah oleh browser:", e);
            }),
          d)
        )
          setTimeout(() => refreshVttSprites(d), 300);
        else {
          player.config.previewThumbnails.enabled = !1;
          const e = document.querySelector(".plyr__preview-thumb");
          e && (e.style.display = "none");
        }
        n &&
          !player.fullscreen.active &&
          (player.fullscreen.toggle(),
          d &&
            (setTimeout(() => refreshVttSprites(d), 500),
            setTimeout(() => refreshVttSprites(d), 1500)));
      } catch (e) {
        (console.error("Gagal transisi seamless, fallback ke reload:", e),
          (window.location.href = t.href));
      } finally {
        e === nextVideoTransitionId &&
          ((isTransitioningNext = !1),
          (isRecovering = !1),
          startStuckDetector());
      }
    }),
    player.on("enterfullscreen", () => {
      (screen.orientation?.lock &&
        screen.orientation.lock("landscape").catch(() => {}),
        vttSrc && setTimeout(() => refreshVttSprites(vttSrc), 300));

      /* ── Ignore notch: force true fullscreen ── */
      document.body.classList.add("meel-fs-active");
      const e_fsWrap = document.getElementById("main-video-wrapper"),
        e_fsGlow = document.getElementById("video-glow-container");
      if (e_fsWrap) {
        e_fsWrap._meelSavedRatio = e_fsWrap.style.aspectRatio || "";
        e_fsWrap.style.setProperty("aspect-ratio", "unset", "important");
        e_fsWrap.style.setProperty("height", "100vh", "important");
        e_fsWrap.style.setProperty("width", "100vw", "important");
        e_fsWrap.style.setProperty("border-radius", "0", "important");
      }
      if (e_fsGlow) {
        e_fsGlow._meelSavedHeight = e_fsGlow.style.height || "";
        e_fsGlow._meelSavedWidth = e_fsGlow.style.width || "";
        e_fsGlow.style.setProperty("height", "100vh", "important");
        e_fsGlow.style.setProperty("width", "100vw", "important");
      }
      const e = player.elements.container,
        t = e ? e.querySelector(".plyr__video-wrapper") : null;
      if (e && t && videoElement) {
        const n = t.querySelector("#video-glow-canvas-fs");
        (n && n.remove(),
          (videoElement.style.position = "relative"),
          (videoElement.style.zIndex = "2"));
        const o = document.createElement("canvas");
        ((o.id = "video-glow-canvas-fs"),
          (o.width = GLOW_W),
          (o.height = GLOW_H),
          (o.style.cssText = [
            "position:absolute",
            "top:50%",
            "left:50%",
            "transform:translate3d(-50%,-50%,0) scale(1.0)",
            "width:100%",
            "height:100%",
            "pointer-events:none",
            "z-index:1",
            "filter:blur(60px) brightness(1.8) saturate(1.15)",
            "opacity:0",
            "will-change:transform,opacity",
            "transition:opacity 0.6s ease",
          ].join(";")),
          t.insertBefore(o, t.firstChild));
        const l = o.getContext("2d"),
          a = document.createElement("canvas");
        ((a.width = GLOW_W), (a.height = GLOW_H));
        const r = a.getContext("2d", { willReadFrequently: !0 }),
          i = new Float32Array(GLOW_W * GLOW_H * 4),
          s = new Float32Array(GLOW_W * GLOW_H * 4);
        let c = null,
          d = 0;
        const p = () => {
            if (!(videoElement.readyState < 2 || document.hidden))
              try {
                r.drawImage(videoElement, 0, 0, GLOW_W, GLOW_H);
                const e = r.getImageData(0, 0, GLOW_W, GLOW_H).data;
                i.set(e);
              } catch (e) {}
          },
          u = (timestamp) => {
            if (!c) return;
            if (timestamp - d >= GLOW_SAMPLE_INTERVAL) {
              d = timestamp;
              p();
            }
            for (let e = 0; e < s.length; e++) s[e] += GLOW_LERP_FACTOR * (i[e] - s[e]);
            const e = l.createImageData(GLOW_W, GLOW_H);
            for (let t = 0; t < s.length; t++) e.data[t] = Math.round(s[t]);
            l.putImageData(e, 0, 0);
            c = requestAnimationFrame(u);
          },
          m = () => {
            if (!glowEnabled || c) return;
            o.style.opacity = "0.6";
            p();
            d = 0;
            c = requestAnimationFrame(u);
          },
          y = () => {
            if (c) {
              cancelAnimationFrame(c);
              c = null;
            }
            o.style.opacity = "0";
            i.fill(0);
            s.fill(0);
            l.clearRect(0, 0, GLOW_W, GLOW_H);
          },
          v = () => {
            if (c) {
              cancelAnimationFrame(c);
              c = null;
            }
          };
        ((e._fsGlowStart = m),
          (e._fsGlowStop = y),
          (e._fsGlowPause = v),
          player.on("play", m),
          player.on("playing", m),
          player.on("pause", v),
          player.on("ended", y),
          videoElement.paused || videoElement.ended || m());
      }
    }),
    player.on("exitfullscreen", () => {
      screen.orientation?.unlock && screen.orientation.unlock();

      /* ── Restore notch-ignoring overrides ── */
      document.body.classList.remove("meel-fs-active");
      const e_xsWrap = document.getElementById("main-video-wrapper"),
        e_xsGlow = document.getElementById("video-glow-container");
      if (e_xsWrap) {
        e_xsWrap.style.removeProperty("aspect-ratio");
        e_xsWrap.style.removeProperty("height");
        e_xsWrap.style.removeProperty("width");
        e_xsWrap.style.removeProperty("border-radius");
        if (e_xsWrap._meelSavedRatio)
          e_xsWrap.style.aspectRatio = e_xsWrap._meelSavedRatio;
        delete e_xsWrap._meelSavedRatio;
      }
      if (e_xsGlow) {
        e_xsGlow.style.removeProperty("height");
        e_xsGlow.style.removeProperty("width");
        delete e_xsGlow._meelSavedHeight;
        delete e_xsGlow._meelSavedWidth;
      }
      const e = player.elements.container;
      if (e) {
        (e._fsGlowStop && e._fsGlowStop(),
          e._fsGlowStart && player.off("play", e._fsGlowStart),
          e._fsGlowStart && player.off("playing", e._fsGlowStart),
          e._fsGlowPause && player.off("pause", e._fsGlowPause),
          e._fsGlowStop && player.off("ended", e._fsGlowStop),
          delete e._fsGlowStart,
          delete e._fsGlowStop,
          delete e._fsGlowPause);
        const t = e.querySelector(".plyr__video-wrapper"),
          n = t ? t.querySelector("#video-glow-canvas-fs") : null;
        (n && n.remove(),
          videoElement &&
            ((videoElement.style.position = ""),
            (videoElement.style.zIndex = "")));
      }
    }));
  const r = document.getElementById("video-glow-canvas");
  if (r && videoElement) {
    const e = document.createElement("canvas");
    ((e.width = GLOW_W), (e.height = GLOW_H));
    const t = e.getContext("2d", { willReadFrequently: !0 });
    ((r.width = GLOW_W), (r.height = GLOW_H));
    const n = r.getContext("2d");
    glowNavbar = document.querySelector("nav");
    const o = () => {
        if (!(videoElement.readyState < 2 || document.hidden))
          try {
            t.drawImage(videoElement, 0, 0, GLOW_W, GLOW_H);
            const e = t.getImageData(0, 0, GLOW_W, GLOW_H).data;
            glowTargetData.set(e);
          } catch (e) {}
      },
      a = (timestamp) => {
        if (!glowRAF) return;
        if (timestamp - glowLastSampleTime >= GLOW_SAMPLE_INTERVAL) {
          glowLastSampleTime = timestamp;
          o();
        }
        for (let e = 0; e < glowCurData.length; e++)
          glowCurData[e] += (glowTargetData[e] - glowCurData[e]) * GLOW_LERP_FACTOR;
        const e = n.createImageData(GLOW_W, GLOW_H);
        for (let t = 0; t < glowCurData.length; t++)
          e.data[t] = Math.round(glowCurData[t]);
        n.putImageData(e, 0, 0);
        if (glowNavbar) {
          let e = 0,
            t = 0,
            n = 0;
          for (let o = 0; o < GLOW_W; o++) {
            const l = 4 * o;
            ((e += glowCurData[l]),
              (t += glowCurData[l + 1]),
              (n += glowCurData[l + 2]));
          }
          const o = Math.round(e / GLOW_W),
            l = Math.round(t / GLOW_W),
            a = Math.round(n / GLOW_W);
          glowNavbar.style.setProperty("--navbar-glow-color", `${o},${l},${a}`);
        }
        glowRAF = requestAnimationFrame(a);
      },
      i = () => {
        if (!glowEnabled || glowRAF) return;
        r.classList.add("glow-active");
        o();
        glowLastSampleTime = 0;
        glowRAF = requestAnimationFrame(a);
      },
      s = (e = !1) => {
        if (glowRAF) {
          cancelAnimationFrame(glowRAF);
          glowRAF = null;
        }
        r.classList.remove("glow-active");
        glowNavbar && glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0");
        e &&
          (glowTargetData.fill(0),
          glowCurData.fill(0),
          n.clearRect(0, 0, GLOW_W, GLOW_H));
      },
      c = () => {
        if (glowRAF) {
          cancelAnimationFrame(glowRAF);
          glowRAF = null;
        }
      };
    ((glowStartFn = i), (glowStopFn = s));
    const d = (e) =>
        `${e ? "On" : "Off"} <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:${e ? "inline-block" : "none"};vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>`,
      p = () => {
        const e = document.getElementById("plyr-setting-glow");
        if (!e) return;
        e.setAttribute("aria-checked", glowEnabled ? "true" : "false");
        const t = e.querySelector(".plyr__menu__value");
        t && (t.innerHTML = d(glowEnabled));
      },
      u = () => {
        const e = document.getElementById("plyr-setting-loop");
        if (!e) return;
        const t = !!player && player.loop;
        e.setAttribute("aria-checked", t ? "true" : "false");
        const n = e.querySelector(".plyr__menu__value");
        n && (n.innerHTML = d(t));
      };
    ((window.updateLoopMenuUI = u), (window.updateGlowMenuUI = p));
    const m = () => {
      if (!player?.elements?.container) return null;
      const e = player.elements?.settings?.panels?.home;
      if (e) return e.querySelector('[role="menu"]') || e;
      const t = player.elements.container;
      return (
        t.querySelector('.plyr__menu__container [id$="-home"] [role="menu"]') ||
        t.querySelector('.plyr__menu__container [id$="-home"]') ||
        t.querySelector('.plyr__menu__container [role="menu"]') ||
        t.querySelector('.plyr__menu [role="menu"]')
      );
    };
    let y = !1;
    const v = () => {
      const e = m();
      if (!e) return;
      (e.querySelector("#plyr-setting-glow")?.remove(),
        e.querySelector("#plyr-setting-loop")?.remove());
      const t = document.createElement("button");
      ((t.type = "button"),
        (t.className = "plyr__control"),
        t.setAttribute("role", "menuitemcheckbox"),
        (t.id = "plyr-setting-glow"),
        (t.innerHTML =
          '<span>Ambient Glow</span><span class="plyr__menu__value"></span>'),
        t.addEventListener("click", (e) => {
          (e.stopPropagation(), window.toggleGlow());
        }));
      const n = document.createElement("button");
      ((n.type = "button"),
        (n.className = "plyr__control"),
        n.setAttribute("role", "menuitemcheckbox"),
        (n.id = "plyr-setting-loop"),
        (n.innerHTML =
          '<span>Loop Playback</span><span class="plyr__menu__value"></span>'),
        n.addEventListener("click", (e) => {
          (e.stopPropagation(), window.toggleLoop());
        }),
        e.appendChild(t),
        e.appendChild(n),
        p(),
        u());
    };
    window.appendCustomSettings = () => {
      if (y) return;
      if (!player?.elements?.container) return;
      y = !0;
      const e = player.elements.container.querySelector(
          '[data-plyr="settings"]',
        ),
        t = () => setTimeout(v, 0);
      e &&
        (e.addEventListener("click", t),
        e.addEventListener("touchend", t, { passive: !0 }));
    };
    let g = null;
    const h = (e, t) => {
      const n = player?.elements?.container;
      if (!n) return;
      const o = n.querySelector(".meel-toggle-toast");
      (o && o.remove(), g && (clearTimeout(g), (g = null)));
      const l = document.createElement("div");
      ((l.className = "meel-toggle-toast"),
        (l.innerHTML = `${e}<span>${t}</span>`),
        n.appendChild(l),
        (g = setTimeout(() => l.remove(), 1900)));
    };
    ((window.toggleGlow = () => {
      if (
        ((glowEnabled = !glowEnabled),
        localStorage.setItem(
          "meel_glow_enabled",
          glowEnabled ? "true" : "false",
        ),
        p(),
        h(
          glowEnabled
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="2" x2="22" y2="22"/><path d="M9.58 4.18A8 8 0 0 1 20 12c0 1.49-.41 2.88-1.12 4.08M6.51 6.51A8 8 0 0 0 4 12c0 4.42 3.58 8 8 8a8 8 0 0 0 5.49-2.18"/></svg>',
          glowEnabled ? "Ambient Glow On" : "Ambient Glow Off",
        ),
        glowEnabled)
      ) {
        videoElement &&
          !videoElement.paused &&
          !videoElement.ended &&
          glowStartFn &&
          glowStartFn();
        const e = player?.elements?.container;
        e &&
          e._fsGlowStart &&
          !videoElement.paused &&
          !videoElement.ended &&
          e._fsGlowStart();
      } else {
        glowStopFn && glowStopFn(!0);
        const e = player?.elements?.container;
        e && e._fsGlowStop && e._fsGlowStop();
      }
    }),
      (window.toggleLoop = () => {
        if (player) {
          ((player.loop = !player.loop), u());
          const e = player.loop;
          h(
            e
              ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>'
              : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="2" x2="22" y2="22"/><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h11"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
            e ? "Loop On" : "Loop Off",
          );
        }
      }),
      player.on("play", i),
      player.on("playing", i),
      player.on("pause", c),
      player.on("ended", () => s(!0)),
      videoElement.paused || videoElement.ended || i());
  }
  setupMobileGestures();
}
(document.addEventListener("visibilitychange", () => {
  if (document.hidden) {
    /* Pause glow RAF when tab hidden */
    if (glowRAF) {
      cancelAnimationFrame(glowRAF);
      glowRAF = null;
    }
    const e = player?.elements?.container;
    if (e && e._fsGlowPause) e._fsGlowPause();
  } else {
    /* Resume glow when tab visible */
    if (glowEnabled && videoElement && !videoElement.paused && !videoElement.ended && !glowRAF && glowStartFn) {
      glowStartFn();
    }
    const e = player?.elements?.container;
    if (e && e._fsGlowStart && videoElement && !videoElement.paused && !videoElement.ended) {
      e._fsGlowStart();
    }
    lastTimeUpdateTimestamp = Date.now();
    player && (lastPlayTime = player.currentTime);
  }
}),
  document.addEventListener("DOMContentLoaded", () => {
    initPlayer();
  }),
  document.addEventListener("htmx:beforeSwap", function (e) {
    if ("main-video-wrapper" === e.detail.target.id && isRecovering) {
      const t = e.detail.xhr;
      t &&
        t.status >= 400 &&
        (e.preventDefault(),
        console.warn(
          "HTMX recovery swap gagal (status " +
            t.status +
            "), fallback reload.",
        ),
        window.location.reload());
    }
  }),
  document.addEventListener("htmx:afterSwap", function (e) {
    if ("main-video-wrapper" === e.detail.target.id) {
      (destroyPlayer(), (isRecovering = !1));
      const e = document.getElementById("meel-reconnect-indicator");
      (e && e.remove(), initPlayer());
    }
    if (isMiniPlayerActive) {
      const t = document.getElementById("temp-index-content");
      t && attachMiniPlayerVideoCardListeners(t);
    }
  }));
let isMiniPlayerActive = !1,
  watchUrl = window.location.href,
  savedWatchScrollY = 0,
  miniShell = null;
function setNavbarSearchTarget(e) {
  (["v-search-watch", "v-search-mobile"].forEach((t) => {
    const n = document.getElementById(t);
    n && n.setAttribute("hx-target", e);
  }),
    document
      .querySelectorAll('button[hx-include="#v-search-watch"]')
      .forEach((t) => t.setAttribute("hx-target", e)));
}
function buildMiniShell(e) {
  const t = document.createElement("div");
  t.id = "mini-player-shell";
  const n = document.createElement("button");
  ((n.id = "mini-expand-btn"),
    (n.title = "Perlebar player"),
    (n.innerHTML =
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h7v2H5v5H3V3zm11 0h7v7h-2V5h-5V3zM3 14h2v5h5v2H3v-7zm16 5h-5v2h7v-7h-2v5z"/></svg>'),
    n.addEventListener("click", (e) => {
      (e.stopPropagation(), toggleMiniPlayer());
    }));
  const o = document.createElement("button");
  ((o.id = "mini-close-btn"),
    (o.title = "Tutup mini player"),
    (o.innerHTML =
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>'),
    o.addEventListener("click", (e) => {
      (e.stopPropagation(), closeMiniPlayer());
    }),
    t.appendChild(e),
    t.appendChild(n),
    t.appendChild(o));
  const l = videoTitle,
    a = videoUploader,
    r = document.createElement("div");
  return (
    (r.id = "mini-player-info"),
    (r.title = "Kembali ke video"),
    (r.innerHTML = `\n    <div style="flex:1;min-width:0;">\n      <div id="mini-info-title">${l}</div>\n      <div id="mini-info-uploader">${a}</div>\n    </div>\n  `),
    r.addEventListener("click", () => toggleMiniPlayer()),
    t.appendChild(r),
    t
  );
}
function closeMiniPlayer() {
  isMiniPlayerActive &&
    (player && player.pause(), (window.location.href = "index.php"));
}
function updateMiniPlayerInfo(e, t) {
  const n = document.getElementById("mini-info-title"),
    o = document.getElementById("mini-info-uploader");
  (n && (n.textContent = e || ""), o && (o.textContent = t || ""));
}
function attachMiniPlayerVideoCardListeners(e) {
  e &&
    e.querySelectorAll('a[href*="watch.php"]').forEach((e) => {
      e.dataset.miniIntercepted ||
        ((e.dataset.miniIntercepted = "1"),
        e.addEventListener("click", async (t) => {
          if (!isMiniPlayerActive) return;
          t.preventDefault();
          const n = e.href;
          try {
            const e = await fetch(n),
              t = await e.text(),
              o = new DOMParser().parseFromString(t, "text/html");
            let l = {};
            o.querySelectorAll("script:not([src])").forEach((e) => {
              const t = e.textContent.match(
                /window\.playerConfig\s*=\s*(\{[\s\S]*?\});/,
              );
              if (t)
                try {
                  l = new Function("return " + t[1])();
                } catch (e) {
                  console.error("Gagal parse playerConfig:", e);
                }
            });
            const a = o.getElementById("main-video"),
              r = l.title || "",
              i = l.uploader || "",
              s = l.videoSrc || a?.dataset?.src || "",
              c = !0 === l.isHls || "true" === l.isHls,
              d = a?.dataset?.poster || "",
              p = l.id || new URL(n).searchParams.get("id") || "";
            updateSearchExcludeId(p);
            const u = l.vttSrc || "";
            if (!s) return void (window.location.href = n);
            ((storageKeyVideo = `video_pos_${p}`),
              (videoSrc = s),
              (isHls = c),
              (vttSrc = u),
              (videoId = p),
              destroyPlayer());
            const m = document.getElementById("main-video");
            (m &&
              ((m.innerHTML = ""),
              (m.dataset.src = s),
              (m.dataset.ishls = c ? "true" : "false"),
              (m.dataset.poster = d),
              (m.poster = d),
              c ? m.removeAttribute("src") : (m.src = s),
              m.load()),
              (videoTitle = r),
              (videoUploader = i),
              (window.playerConfig = {
                videoSrc: s,
                isHls: c,
                vttSrc: u,
                id: p,
                title: r,
                uploader: i,
              }),
              initPlayer(),
              updateMiniPlayerInfo(r, i),
              (document.title = o.title),
              ["watch-details-wrapper", "recommendation-column"].forEach(
                (e) => {
                  const t = document.getElementById(e),
                    n = o.getElementById(e);
                  t && n && (t.innerHTML = n.innerHTML);
                },
              ),
              window.lucide && window.lucide.createIcons(),
              window.htmx && htmx.process(document.body),
              (watchUrl = n),
              window.history.pushState({ miniPlayer: !0 }, "", n));
            const y = document.getElementById("temp-index-content");
            y && attachMiniPlayerVideoCardListeners(y);
          } catch (e) {
            (console.error("Gagal ganti video di mini-player:", e),
              (window.location.href = n));
          }
        }));
    });
}
function setupMobileGestures() {
  if (!isTouchDevice) return;
  const e = document.querySelector(".plyr");
  if (!e) return;
  let t = !1,
    n = null,
    o = 0,
    l = 0,
    a = null,
    r = !1;
  function i() {
    ((t = !0),
      e.classList.add("plyr--hide-controls"),
      e.classList.remove("plyr--hide-controls"),
      player &&
        player.elements &&
        player.elements.controls &&
        ((player.elements.controls.style.opacity = ""),
        (player.elements.controls.style.pointerEvents = "")));
    const n = e.querySelector(".plyr__control--overlaid");
    (n && (n.style.opacity = ""), c());
  }
  function s() {
    ((t = !1),
      clearTimeout(n),
      player &&
        player.elements &&
        player.elements.controls &&
        ((player.elements.controls.style.opacity = "0"),
        (player.elements.controls.style.pointerEvents = "none")));
    const o = e.querySelector(".plyr__control--overlaid");
    o && (o.style.opacity = "0");
  }
  function c() {
    (clearTimeout(n),
      (n = setTimeout(() => {
        t && s();
      }, 3e3)));
  }
  (e.addEventListener(
    "touchstart",
    (n) => {
      const d = Date.now();
      l = d;
      const p = n.target;
      if (
        p.closest(".plyr__controls") ||
        p.closest(".plyr__control--overlaid") ||
        p.closest(".plyr__menu") ||
        p.closest(".plyr__volume") ||
        p.closest(".plyr__progress")
      )
        return void (t && c());
      const u = e.getBoundingClientRect(),
        m = n.touches[0] || n.changedTouches[0];
      if (!m) return;
      const y =
        ((v = m.clientX - u.left),
        (g = u.width),
        v < 0.4 * g ? "left" : v > 0.6 * g ? "right" : "center");
      var v, g;
      if (d - o < 300 && r)
        return (
          clearTimeout(a),
          (r = !1),
          n.preventDefault(),
          n.stopPropagation(),
          "left" === y
            ? (player && player.rewind(10),
              tampilkanSisiIndikator("rewind", "-10s"))
            : "right" === y &&
              (player && player.forward(10),
              tampilkanSisiIndikator("forward", "+10s")),
          void (o = 0)
        );
      ((o = d),
        (r = !0),
        clearTimeout(a),
        (a = setTimeout(() => {
          ((r = !1),
            (function (e) {
              "left" === e || "right" === e
                ? t
                  ? s()
                  : i()
                : t
                  ? player &&
                    (player.paused ? player.play() : player.pause(), c())
                  : i();
            })(y));
        }, 300)));
    },
    { passive: !1 },
  ),
    e.addEventListener(
      "dblclick",
      (e) => {
        Date.now() - l < 1e3 && (e.preventDefault(), e.stopPropagation());
      },
      !0,
    ),
    (function () {
      let e = null,
        o = null,
        l = null;
      (document.addEventListener(
        "touchstart",
        (a) => {
          const r = a.target.closest(".plyr__volume input[type='range']");
          r &&
            t &&
            ((e = a.touches[0].clientY),
            (o = parseFloat(r.value)),
            (l = r),
            clearTimeout(n),
            a.preventDefault());
        },
        { passive: !1 },
      ),
        document.addEventListener(
          "touchmove",
          (t) => {
            if (!l || null === e) return;
            t.preventDefault();
            const n =
                ((e - t.touches[0].clientY) / 120) *
                (parseFloat(l.max) - parseFloat(l.min)),
              a = Math.min(
                parseFloat(l.max),
                Math.max(parseFloat(l.min), o + n),
              );
            ((l.value = a),
              l.dispatchEvent(new Event("input", { bubbles: !0 })),
              player && (player.volume = a));
          },
          { passive: !1 },
        ),
        document.addEventListener("touchend", () => {
          (l && c(), (e = null), (o = null), (l = null));
        }));
    })(),
    player &&
      (player.on("play", () => {
        c();
      }),
      player.on("pause", () => {
        (clearTimeout(n),
          (t = !0),
          player.elements &&
            player.elements.controls &&
            ((player.elements.controls.style.opacity = ""),
            (player.elements.controls.style.pointerEvents = "")));
      })));
}
((window.toggleMiniPlayer = async function () {
  const e = document.getElementById("main-video-wrapper"),
    t = document.getElementById("watch-details-wrapper"),
    n = document.getElementById("recommendation-wrapper"),
    o = document.getElementById("app-content-grid"),
    l = document.getElementById("left-column");
  if (isMiniPlayerActive) {
    ((isMiniPlayerActive = !1),
      setNavbarSearchTarget("#recommendation-column"));
    const e = document.getElementById("main-video-wrapper");
    if (e) {
      (e.classList.remove("mini-player-mode"),
        e.style.removeProperty("aspect-ratio"),
        e.style.removeProperty("height"),
        e.style.removeProperty("width"),
        e.style.removeProperty("position"),
        (e.style.aspectRatio = "16 / 9"),
        player?.elements?.controls &&
          Array.from(player.elements.controls.children).forEach(
            (e) => (e.style.display = ""),
          ));
      const t = document.getElementById("video-glow-container");
      if (t) {
        const n = t.querySelector("canvas");
        n ? t.insertBefore(e, n.nextSibling) : t.appendChild(e);
      } else l && l.insertBefore(e, l.firstChild);
      const n = document.getElementById("video-glow-canvas");
      n &&
        (n.style.removeProperty("display"),
        glowTargetData.fill(0),
        glowCurData.fill(0),
        glowNavbar &&
          glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0"),
        videoElement?.paused ||
          videoElement?.ended ||
          !glowStartFn ||
          glowStartFn());
    }
    (miniShell && (miniShell.remove(), (miniShell = null)),
      (document.body.style.paddingBottom = ""));
    const a = document.getElementById("temp-index-content");
    (a && (a.style.display = "none"),
      o && (o.style.display = ""),
      t && (t.style.display = "block"),
      n && (n.style.display = "block"),
      requestAnimationFrame(() => {
        if (
          videoElement &&
          videoElement.videoWidth &&
          videoElement.videoHeight
        ) {
          const t = videoElement.videoWidth,
            n = videoElement.videoHeight;
          e && (e.style.aspectRatio = `${t} / ${n}`);
        }
        const t = document.getElementById("desc-text"),
          n = document.getElementById("btn-read-more");
        (t &&
          n &&
          (n.classList.add("hidden"),
          t.scrollHeight > t.clientHeight && n.classList.remove("hidden")),
          window.scrollTo({
            top: savedWatchScrollY,
            left: 0,
            behavior: "instant",
          }));
      }),
      window.history.pushState({}, "", watchUrl));
  } else {
    ((isMiniPlayerActive = !0),
      setNavbarSearchTarget("#video-container"),
      (savedWatchScrollY = window.scrollY),
      window.scrollTo({ top: 0, left: 0, behavior: "instant" }),
      e &&
        (e.style.removeProperty("aspect-ratio"),
        e.style.removeProperty("height")),
      (miniShell = buildMiniShell(e)),
      e.classList.add("mini-player-mode"));
    const l = document.getElementById("video-glow-canvas");
    (l && ((l.style.display = "none"), l.classList.remove("glow-active")),
      glowRAF &&
        (cancelAnimationFrame(glowRAF), (glowRAF = null)),
      glowNavbar &&
        glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0"),
      document.body.appendChild(miniShell),
      (document.body.style.paddingBottom = "120px"),
      t && (t.style.display = "none"),
      n && (n.style.display = "none"),
      o && (o.style.display = "none"));
    let a = document.getElementById("temp-index-content");
    if (a)
      ((a.style.display = "block"),
        window.history.pushState({ miniPlayer: !0 }, "", "index.php"),
        attachMiniPlayerVideoCardListeners(a));
    else {
      ((a = document.createElement("div")),
        (a.id = "temp-index-content"),
        (a.className = "w-full"));
      const e =
        document.querySelector("footer") ?? document.body.lastElementChild;
      document.body.insertBefore(a, e);
      try {
        const e = await fetch("index.php"),
          t = await e.text(),
          n = new DOMParser()
            .parseFromString(t, "text/html")
            .querySelector("main");
        n &&
          ((a.innerHTML = n.outerHTML),
          window.history.pushState({ miniPlayer: !0 }, "", "index.php"),
          window.lucide && window.lucide.createIcons(),
          window.htmx && htmx.process(a),
          attachMiniPlayerVideoCardListeners(a));
      } catch (e) {
        console.error("Gagal memuat index:", e);
      }
    }
  }
}),
  window.addEventListener(
    "keydown",
    (e) => {
      if (!["INPUT", "TEXTAREA"].includes(document.activeElement.tagName))
        return isMiniPlayerActive && "f" === e.key.toLowerCase()
          ? (e.preventDefault(), void e.stopPropagation())
          : void ("i" === e.key.toLowerCase() && toggleMiniPlayer());
    },
    !0,
  ),
  window.addEventListener(
    "dblclick",
    (e) => {
      isMiniPlayerActive &&
        e.target.closest("#main-video-wrapper") &&
        (e.preventDefault(), e.stopPropagation());
    },
    !0,
  ),
  window.addEventListener("popstate", (e) => {
    isMiniPlayerActive &&
      window.location.href === watchUrl &&
      toggleMiniPlayer();
  }));
const _vttSpriteCache = {};
function refreshVttSprites(e) {
  if (!player) return;
  ((player.config.previewThumbnails.src = e),
    (player.config.previewThumbnails.enabled = !0),
    player.previewThumbnails &&
      ((player.previewThumbnails.thumbnails = []),
      (player.previewThumbnails.loaded = !1),
      "function" == typeof player.previewThumbnails.load &&
        player.previewThumbnails.load()));
  const t = (e) => {
    (document
      .querySelectorAll(".plyr__preview-thumb__image-container")
      .forEach((t) => {
        t.style.backgroundImage = `url("${e}")`;
      }),
      document
        .querySelectorAll(
          ".plyr__preview-thumb__image-container img, .plyr__preview-scrubbing img",
        )
        .forEach((t) => {
          t.src = e;
        }));
  };
  _vttSpriteCache[e]
    ? t(_vttSpriteCache[e])
    : fetch(e)
        .then((e) => e.text())
        .then((n) => {
          const o = n.match(/([\w-]+\.(jpg|png|webp|jpeg))/i);
          if (o) {
            const n = e.substring(0, e.lastIndexOf("/") + 1) + o[1];
            ((_vttSpriteCache[e] = n), t(n));
          }
        })
        .catch((e) => console.error("Gagal refresh VTT sprites:", e));
}
const _sisiIndicators = { rewind: null, forward: null },
  _sisiHideTimeouts = { rewind: null, forward: null },
  _sisiRippleCounts = { rewind: 0, forward: 0 };
function tampilkanSisiIndikator(e, t) {
  const n = document.querySelector(".plyr");
  if (!n) return;
  if (!_sisiIndicators[e] || !_sisiIndicators[e].parentNode) {
    const t = document.createElement("div");
    ((t.className = `meel-seek-indicator meel-seek-${e}`),
      n.appendChild(t),
      (_sisiIndicators[e] = t));
  }
  const o = _sisiIndicators[e],
    l =
      "rewind" === e
        ? '<svg class="meel-seek-icon" viewBox="0 0 24 24"><path d="M11 18V6l-8.5 6 8.5 6zm.5-6l8.5 6V6l-8.5 6z"/></svg>'
        : '<svg class="meel-seek-icon" viewBox="0 0 24 24"><path d="M4 18l8.5-6L4 6v12zm9-12v12l8.5-6L13 6z"/></svg>';
  ((o.innerHTML = `${l}<span class="meel-seek-label">${t}</span>`),
    o.classList.remove("meel-seek-active"),
    o.offsetWidth,
    o.classList.add("meel-seek-active"),
    clearTimeout(_sisiHideTimeouts[e]),
    (_sisiHideTimeouts[e] = setTimeout(() => {
      o.classList.remove("meel-seek-active");
    }, 800)));
}
function tampilkanIndikator(e) {}
function updateSearchExcludeId(e) {
  (["v-search-watch", "v-search-mobile"].forEach((t) => {
    const n = document.getElementById(t);
    n &&
      (n.setAttribute("hx-get", `search_video.php?exclude=${e}`),
      window.htmx && htmx.process(n));
  }),
    document
      .querySelectorAll('button[hx-include="#v-search-watch"]')
      .forEach((t) => {
        (t.setAttribute("hx-get", `search_video.php?exclude=${e}`),
          window.htmx && htmx.process(t));
      }));
}
((window.toggleDescription = function () {
  const e = document.getElementById("desc-text"),
    t = document.getElementById("btn-read-more");
  if (!e || !t) return;
  const n = e.classList.toggle("line-clamp-5");
  t.textContent = n ? "Selengkapnya" : "Lebih Sedikit";
}),
  (window.toggleReply = function (e) {
    const t = document.getElementById(e);
    if (t && (t.classList.toggle("hidden"), !t.classList.contains("hidden"))) {
      const e = t.querySelector('input[type="text"]');
      e && e.focus();
    }
  }));
