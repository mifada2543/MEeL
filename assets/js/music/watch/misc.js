/* misc.js — window.toggleLoop, stub toggleVisualizer/toggleEqualizer */
window.toggleLoop = function () {
  // Basis toggle = state NYATA (audio.loop), bukan localStorage — kalau
  // keduanya sempat divergen (race lama), toggle tetap konsisten dengan
  // perilaku yang sedang berjalan. Semua representasi di-update atomik
  // lewat engine.setLoop() (media.loop + Plyr config + localStorage).
  const engine = window.meelGetAudioEngine ? window.meelGetAudioEngine() : null;
  const e = engine
    ? !engine.audio.loop
    : !("true" === localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP));
  if (engine) engine.setLoop(e);
  else localStorage.setItem(MEEL_KEYS.GLOBAL_LOOP, String(e));
  saveAudioState();
  updateLoopUI();
};
window.toggleVisualizer = function () {};
window.toggleEqualizer = function () {};
// Shortcut ini khusus watch.php. Karena watch.php & index.php sekarang bisa
// sama-sama "hidup" dalam satu dokumen (AJAX transition, tidak pernah
// reload), listener ini HARUS di-guard dengan window.__meelCurrentView
// supaya tidak dobel-fire bersamaan dengan shortcut 'i'/'l' milik
// index.php (lihat assets/js/music/shared/mini-player.js).
document.addEventListener("keydown", (e) => {
  if (window.meelKeyShortcutIgnored?.(e)) return;
  if (window.__meelCurrentView !== "watch") return;
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
