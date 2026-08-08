/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* shared/upload-progress.js — Animasi progress-bar upload manual */
window.meelUploadProgress = function (options) {
  const status = document.getElementById("overlay-status");
  const bar = document.getElementById("progress-bar");
  const pct = document.getElementById("overlay-pct");
  const phases = (options && options.phases) || [];
  if (!phases.length) return;
  const baseDelay = Math.max(0, (options && options.baseDelay) || 0);
  const phaseDelay = baseDelay / phases.length;
  let phaseIdx = 0;
  function advancePhase() {
    if (phaseIdx >= phases.length) return;
    const p = phases[phaseIdx];
    if (status) status.textContent = p.msg;
    if (bar) bar.style.width = p.pctVal + "%";
    if (pct) pct.textContent = p.pctVal + "%";
    phaseIdx++;
    if (phaseIdx < phases.length) {
      setTimeout(advancePhase, phaseDelay);
    }
  }
  advancePhase();
};
