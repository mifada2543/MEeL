/* misc.js — window.toggleLoop, stub toggleVisualizer/toggleEqualizer */
window.toggleLoop = function () {
  const e = !("true" === localStorage.getItem("meel_global_loop"));
  (localStorage.setItem("meel_global_loop", String(e)),
    player && ((player.loop = e), saveAudioState()),
    updateLoopUI());
};
window.toggleVisualizer = function () {};
window.toggleEqualizer = function () {};
document.addEventListener("keydown", (e) => {
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
