/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* ────────────────────────────────────────────────────────────────
 * shared/mini-player-popstate.js — Helper listener popstate untuk keluar dari mode mini-player saat Back/Forward browser.
 * Dipakai BERSAMA oleh modul video (watch/mini-player.js) & music (watch/mini-player.js) — sebelumnya blok 3 baris diduplikasi di kedua file.
 * ──────────────────────────────────────────────────────────────── */
window.meelMiniPlayerPopstate = function (options) {
  const opts = options || {};
  const isActive =
    opts.isActive ||
    function () {
      return false;
    };
  const getWatchUrl =
    opts.watchUrl ||
    function () {
      return "";
    };
  const onExit = opts.onExit || function () {};
  window.addEventListener("popstate", (e) => {
    if (!isActive()) return;
    const backToMiniEntry = !!(e.state && e.state.miniPlayer);
    if (backToMiniEntry || window.location.href === getWatchUrl()) {
      onExit();
    }
  });
};
