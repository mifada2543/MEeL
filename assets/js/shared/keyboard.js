/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
/* ────────────────────────────────────────────────────────────────
 * Dipakai BERSAMA oleh modul video & music (misc.js watch, dan
 * mini-player.js music) — sebelumnya guard diduplikasi di tiap file.
 * ──────────────────────────────────────────────────────────────── */
window.meelKeyShortcutIgnored = function (e) {
  const t = (e.target?.tagName || "").toLowerCase();
  if ("input" === t || "textarea" === t) return true;
  if (e.ctrlKey || e.altKey || e.metaKey) return true;
  if (e.repeat) return true;
  return false;
};
