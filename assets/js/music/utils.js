/* ============================================================
 * utils.js — Util generik (format waktu mm:ss).
 * ============================================================ */

function formatTime(e) {
  if (!e || isNaN(e)) return "0:00";
  const t = Math.floor(e / 60),
    n = Math.floor(e % 60);
  return `${t}:${String(n).padStart(2, "0")}`;
}
