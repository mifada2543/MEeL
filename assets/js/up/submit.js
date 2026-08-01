/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
/* ────────────────────────────────────────────────────────────────
 * up/submit.js — Handler submit form Advanced Upload: transisi
 * tombol + memicu overlay fase download (dari engine/), lalu
 * membiarkan form submit biasa berjalan (PHP streaming).
 *
 * Dipanggil via onsubmit="return startAdvancedUpload(this)".
 * Bergantung pada window.meelPhase dari assets/js/engine/.
 * ──────────────────────────────────────────────────────────────── */

function startAdvancedUpload(form) {
  var urlInput = document.getElementById('url-input');
  if (!urlInput) return false;
  var url = urlInput.value.trim();
  if (!url) return false;

  // Transisi tombol
  var btn = document.getElementById('submit-btn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<div style="width:14px;height:14px;border:1.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:meel-spin .7s linear infinite;"></div> Memproses...';
  }

  // Tampilkan overlay pada fase download
  if (typeof meelPhase === 'function') meelPhase('download');

  return true; // Biarkan form submit biasa (PHP streaming)
}
