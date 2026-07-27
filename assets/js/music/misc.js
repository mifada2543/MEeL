/* ============================================================
 * misc.js — window.toggleLoop, stub toggleVisualizer/toggleEqualizer
 * (implementasi asli didefinisikan ulang di player-core.js saat
 * DOMContentLoaded), toggleReply, dan shortcut keyboard global
 * (L=loop, E=equalizer, V=visualizer, I=mini-player).
 * Depends on: state.js, loop-ui.js, audio-state.js
 * ============================================================ */

window.toggleLoop = function () {
  const e = !("true" === localStorage.getItem("meel_global_loop"));
  (localStorage.setItem("meel_global_loop", String(e)),
    player && ((player.loop = e), saveAudioState()),
    updateLoopUI());
};
window.toggleVisualizer = function () {};
window.toggleEqualizer = function () {};
window.toggleReply = function (e) {
    const t = document.getElementById(e);
    if (!t) return;
    t.classList.toggle("hidden");
    const n = t.querySelector('input[type="text"]');
    n && !t.classList.contains("hidden") && n.focus();
};
document.addEventListener("keydown", (e) => {
    const t = e.target.tagName.toLowerCase();
    if ("input" === t || "textarea" === t) return;
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    const n = e.key.toLowerCase();
    "l" === n
      ? (e.preventDefault(), window.toggleLoop())
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
