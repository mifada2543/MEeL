/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* ============================================================
 * mini-player.js — Mode mini-player music (Spotify-style) untuk
 * halaman music/watch.php. Dipisah dari player-core.js agar
 * konsisten dengan pola video (watch/mini-player.js).
 * Semua fungsi global di sini dipanggil dari HTML onclick
 * (toggleMiniPlayer, miniPlayPause, miniSeek, miniNext, miniPrev,
 * goBackToLibrary) dan player-core.js (updateMiniPlayerUI).
 * Depends on: state.js (player, isMiniPlayerActive, miniEls,
 * watchUrl, isNavigating, skipResumeModalOnce), audio-state.js
 * (saveAudioState), shared/format-time.js (formatTime),
 * shared/temp-index.js (meelLoadTempIndex),
 * shared/mini-player-popstate.js (meelMiniPlayerPopstate)
 * ============================================================ */
// Track state paused terakhir untuk update ikon play/pause
let _mpPrevPaused = null;
// ── Update UI mini-player ──────────
window.updateMiniPlayerUI = function () {
  if (!isMiniPlayerActive) return;
  miniEls ||
    (miniEls = {
      playBtn: document.getElementById("mini-play-btn"),
      progressFill: document.getElementById("mini-progress-fill"),
      currentTime: document.getElementById("mini-current-time"),
      duration: document.getElementById("mini-duration"),
    });
  const { playBtn: e, progressFill: t, currentTime: n, duration: a } = miniEls;
  e &&
    player.paused !== _mpPrevPaused &&
    ((_mpPrevPaused = player.paused),
    (e.innerHTML = player.paused
      ? '<i data-lucide="play"  style="width:18px;height:18px;"></i>'
      : '<i data-lucide="pause" style="width:18px;height:18px;"></i>'),
    "undefined" != typeof lucide && lucide.createIcons());
  const o = player.duration ? (player.currentTime / player.duration) * 100 : 0;
  (t && (t.style.width = o + "%"),
    n && (n.textContent = formatTime(player.currentTime)),
    a && (a.textContent = formatTime(player.duration)));
};
// ── Toggle mini-player ──────
window.toggleMiniPlayer = async function () {
  const e = document.getElementById("player-container"),
    t = document.querySelector(
      'div[class*="grid-cols-1"][class*="lg:grid-cols-3"]',
    ),
    n = t?.querySelector('div[class*="lg:col-span-2"]'),
    a = n?.nextElementSibling;
  if (isMiniPlayerActive)
    ((isMiniPlayerActive = !1),
      e && ((e.style.maxHeight = ""), (e.style.overflow = "")),
      document
        .getElementById("temp-index-content")
        ?.style.setProperty("display", "none"),
      t &&
        ((t.style.display = "grid"),
        t.classList.add(
          "grid",
          "grid-cols-1",
          "lg:grid-cols-3",
          "gap-6",
          "lg:gap-8",
        )),
      n?.classList.add("lg:col-span-2", "space-y-5"),
      a && (a.style.display = "block"),
      window.history.pushState({}, "", watchUrl));
  else {
    ((isMiniPlayerActive = !0),
      (skipResumeModalOnce = !0),
      a && (a.style.display = "none"),
      e && ((e.style.maxHeight = "120px"), (e.style.overflow = "hidden")),
      t &&
        (t.classList.remove(
          "grid",
          "grid-cols-1",
          "lg:grid-cols-3",
          "gap-6",
          "lg:gap-8",
        ),
        (t.style.cssText = "display:flex;flex-direction:column")));
    await window.meelLoadTempIndex({ container: t });
  }
};
setInterval(() => {
  isMiniPlayerActive && saveAudioState();
}, 5e3);
// ── Kontrol mini-player ──
window.miniPlayPause = function () {
  player &&
    (window.meelHealthAlertActive ||
      (player.paused ? player.play() : player.pause(),
      window.updateMiniPlayerUI()));
};
window.miniSeek = function (e) {
  if (!player) return;
  if (window.meelHealthAlertActive) return;
  const t = e.currentTarget.getBoundingClientRect();
  player.currentTime = ((e.clientX - t.left) / t.width) * player.duration;
};
window.miniNext = function () {
  if (window.meelHealthAlertActive || isNavigating) return;
  isNavigating = true;
  const e = window.MEEL_MUSIC_CONFIG?.nextSongUrl;
  if (e) (saveAudioState(), (window.location.href = e));
  else {
    const e = document.querySelector(".rekomendasi-item");
    if (e) window.location.href = e.href;
    else isNavigating = false; // reset jika tidak ada tujuan
  }
};
window.miniPrev = function () {
  player &&
    (window.meelHealthAlertActive ||
      (player.currentTime > 3
        ? (player.currentTime = 0)
        : window.history.length > 1
          ? window.history.back()
          : (player.currentTime = 0)));
};
window.goBackToLibrary = function () {
  saveAudioState();
  var e = window.MEEL_MUSIC_CONFIG?.playlistId;
  (player?.destroy(),
    (window.location.href =
      e && e > 0 ? "index.php?playlist_id=" + e : "index.php"));
};
function _attachMiniPlayerDom() {
  document
    .getElementById("player-container")
    ?.addEventListener("click", (e) => {
      e.target.closest(".plyr__controls") ||
        e.target.closest(".mp-controls") ||
        e.target.closest("button") ||
        (isMiniPlayerActive && (e.preventDefault(), window.toggleMiniPlayer()));
    });
  window.meelMiniPlayerPopstate({
    isActive: () => isMiniPlayerActive,
    watchUrl: () => watchUrl,
    onExit: () => window.toggleMiniPlayer(),
  });
  "undefined" != typeof lucide && lucide.createIcons();
}
if (document.readyState === "loading")
  document.addEventListener("DOMContentLoaded", _attachMiniPlayerDom);
else _attachMiniPlayerDom();
