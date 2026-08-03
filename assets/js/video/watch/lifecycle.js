/* ============================================================
 * lifecycle.js — Listener siklus hidup halaman: bfcache (pageshow), visibilitychange (pause/resume glow), DOMContentLoaded (init), dan integrasi HTMX (beforeSwap/afterSwap) untuk auto-recovery.
 * Depends on: state.js, player-init.js, recovery.js, mini-player.js
 * ============================================================ */
(window.addEventListener("pageshow", (e) => {
  if (e.persisted) {
    window.location.reload();
  }
}),
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
      if (glowRAF) {
        cancelAnimationFrame(glowRAF);
        glowRAF = null;
      }
      const e = player?.elements?.container;
      if (e && e._fsGlowPause) e._fsGlowPause();
    } else {
      if (
        glowEnabled &&
        videoElement &&
        !videoElement.paused &&
        !videoElement.ended &&
        !glowRAF &&
        glowStartFn
      ) {
        glowStartFn();
      }
      const e = player?.elements?.container;
      if (
        e &&
        e._fsGlowStart &&
        videoElement &&
        !videoElement.paused &&
        !videoElement.ended
      ) {
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
      const n = document.getElementById("meel-reconnect-indicator");
      (n && n.remove(), initPlayer());
      if (isMiniPlayerActive) {
        const o = document.getElementById("main-video-wrapper");
        o && o.classList.add("mini-player-mode");
        const l = document.getElementById("mini-player-shell");
        if (l && o && o.parentNode !== l) {
          const a = l.querySelector("#mini-expand-btn");
          a ? l.insertBefore(o, a) : l.prepend(o);
        }
      }
    }
    if (isMiniPlayerActive) {
      const t = document.getElementById("temp-index-content");
      t && attachMiniPlayerVideoCardListeners(t);
    }
  }));
