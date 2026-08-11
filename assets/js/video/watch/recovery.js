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
  const wrapper = document.getElementById("main-video-wrapper");
  if (!wrapper) return;
  const existing = document.getElementById("meel-reconnect-indicator");
  existing && existing.remove();
  const indicator = document.createElement("div");
  indicator.id = "meel-reconnect-indicator";
  indicator.className =
    "absolute inset-0 bg-[#080a0f]/95 flex flex-col items-center justify-center z-[100] text-white gap-3 p-4 text-center rounded-none sm:rounded-none";
  indicator.innerHTML =
    '\n    <div class="animate-spin h-8 w-8 border-4 border-red-600 border-t-transparent rounded-full"></div>\n    <div class="text-sm font-bold uppercase tracking-wider text-white">Sambungan Media Terputus</div>\n    <div class="text-xs text-gray-500">Mencoba menghubungkan kembali secara otomatis...</div>\n  ';
  wrapper.appendChild(indicator);
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
function registerHlsErrorListener(hlsInstance) {
  hlsInstance.on(Hls.Events.ERROR, function (_event, data) {
    if (!data.fatal) return;
    console.warn("Fatal HLS error encountered:", data.type);
    switch (data.type) {
      case Hls.ErrorTypes.NETWORK_ERROR:
        triggerPlayerRecovery();
        break;
      case Hls.ErrorTypes.MEDIA_ERROR:
        hlsInstance.recoverMediaError();
        break;
      default:
        triggerPlayerRecovery();
    }
  });
}
function registerVideoErrorListener(videoEl) {
  if (!videoEl || videoEl.dataset.meelErrorRegistered) return;
  videoEl.dataset.meelErrorRegistered = "1";
  videoEl.addEventListener("error", () => {
    const mediaError = videoEl.error;
    if (!mediaError) return;
    console.warn(
      "HTML5 video error:",
      mediaError.message || "Unknown",
      "code:",
      mediaError.code,
    );
    if ([2, 3, 4].includes(mediaError.code)) triggerPlayerRecovery();
  });
  videoEl.addEventListener("stalled", () => {
    console.warn("Video stalled, memulai waiting timeout...");
    startWaitingTimeout();
  });
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
