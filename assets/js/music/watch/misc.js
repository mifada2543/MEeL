/* ============================================================
 * misc.js — window.toggleLoop, stub toggleVisualizer/toggleEqualizer
 * (implementasi asli didefinisikan ulang di player-core.js saat
 * DOMContentLoaded), dan shortcut keyboard global
 * (L=loop, E=equalizer, V=visualizer, I=mini-player).
 * Fungsi komentar (toggleReply, meelConfirmHtmx, toggleCommentSection)
 * kini berada di assets/js/shared/comment.js.
 * Depends on: state.js, loop-ui.js, audio-state.js, shared/keyboard.js
 * ============================================================ */

window.toggleLoop = function () {
  const e = !("true" === localStorage.getItem("meel_global_loop"));
  (localStorage.setItem("meel_global_loop", String(e)),
    player && ((player.loop = e), saveAudioState()),
    updateLoopUI());
};
window.toggleVisualizer = function () {};
window.toggleEqualizer = function () {};
document.addEventListener("keydown", (e) => {
  // Guard: input/textarea, modifier (Ctrl/Alt/Meta), auto-repeat —
  // kini di shared/keyboard.js (meelKeyShortcutIgnored)
  if (window.meelKeyShortcutIgnored?.(e)) return;
  const n = e.key.toLowerCase();
  "l" === n
    ? (e.preventDefault(), e.stopPropagation(), window.toggleLoop())
    : "e" === n
      ? (e.preventDefault(), window.toggleEqualizer?.())
      : "v" === n
        ? window.toggleVisualizer?.()
        : "i" === n &&
          (e.preventDefault(),
          window.goBackToLibrary
            ? window.goBackToLibrary()
            : window.toggleMiniPlayer?.());
});
