/* ============================================================
 * format-time.js — Util bersama format waktu mm:ss (formatTime).
 * Dipindah dari music/shared/utils.js agar bisa dipakai lintas
 * modul: music mini-player (index/watch/view_playlist) & video
 * (resume-modal). Depends on: —
 *
 * CATATAN: formatTime kini global. Jangan dimuat bersamaan dengan
 * drive/upload.js atau admin/catur.js yang punya fungsi lokal
 * senama (halaman-halamannya memang tidak saling memuat).
 * ============================================================ */

function formatTime(e) {
  if (!e || isNaN(e)) return "0:00";
  const t = Math.floor(e / 60),
    n = Math.floor(e % 60);
  return `${t}:${String(n).padStart(2, "0")}`;
}
