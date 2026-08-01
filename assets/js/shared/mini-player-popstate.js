/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
/* ────────────────────────────────────────────────────────────────
 * shared/mini-player-popstate.js — Helper listener popstate untuk
 * keluar dari mode mini-player saat Back/Forward browser.
 * Dipakai BERSAMA oleh modul video (watch/mini-player.js) & music
 * (watch/mini-player.js) — sebelumnya blok 3 baris diduplikasi di
 * kedua file.
 *
 * window.meelMiniPlayerPopstate(options) → void
 *   options.isActive : () => boolean — getter flag mini-player aktif
 *   options.watchUrl : () => string  — getter URL watch saat ini
 *   options.onExit   : () => void    — dipanggil saat Back/Forward
 *                        harus keluar dari mini-player (toggleMiniPlayer)
 *
 * Logika keluar (lebih benar daripada sekadar banding URL):
 *   1. e.state.miniPlayer === true — kembali ke entri history yang
 *      di-push dengan state { miniPlayer: true } oleh shared/temp-index.js
 *      (masuk mode) / mini-player video (ganti video saat mode aktif).
 *      Menangkap kasus ganti-video yang URL-nya TIDAK lagi sama dengan
 *      watchUrl — sebelumnya handler tidak melakukan apa-apa di kasus ini.
 *   2. fallback — URL aktif === watchUrl (entri watch asli, state null).
 * ──────────────────────────────────────────────────────────────── */
window.meelMiniPlayerPopstate = function (options) {
  const opts = options || {};
  const isActive = opts.isActive || function () { return false; };
  const getWatchUrl = opts.watchUrl || function () { return ""; };
  const onExit = opts.onExit || function () {};

  window.addEventListener("popstate", (e) => {
    if (!isActive()) return;
    const backToMiniEntry = !!(e.state && e.state.miniPlayer);
    if (backToMiniEntry || window.location.href === getWatchUrl()) {
      onExit();
    }
  });
};
