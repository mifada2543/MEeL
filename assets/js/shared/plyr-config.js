/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* ────────────────────────────────────────────────────────────────
 * shared/plyr-config.js — Konfigurasi Plyr yang dipakai BERSAMA oleh modul video (watch/state.js → plyrOptions) & music (watch/player-core.js → new Plyr). Sebelumnya iconUrl, speed, keyboard, tooltips diduplikasi di kedua file — kini satu sumber kebenaran via MEEL_PLYR_COMMON.
 *
 * Catatan: key spesifik per modul (controls, settings, i18n, fullscreen,
 * previewThumbnails, clickToPlay, mediaMetadata) TIDAK di sini — tetap
 * didefinisikan di masing-masing file lalu di-spread MEEL_PLYR_COMMON.
 * ──────────────────────────────────────────────────────────────── */
window.MEEL_PLYR_COMMON = {
  iconUrl: "../assets/plyr.svg",
  speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
  keyboard: { focused: true, global: true },
  tooltips: { controls: true, seek: true },
};
