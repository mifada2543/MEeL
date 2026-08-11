/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* engine/core.js — State bersama & kontrol fase utama overlay. */

// State bersama overlay (global — dipakai modul sibling)
var _segsBuilt = false;
var _errorTimeout = null;
// ─── KONTROL ANIMASI MERAYAP (TRICKLE EFFECT) FOR SPRITE ───
var meelSpriteTimer = null;
var meelSpriteCurrentPct = 0;
function startSpriteTrickle() {
  var b = document.getElementById("meel-sp-bar");
  var t = document.getElementById("meel-sp-pct");
  meelSpriteCurrentPct = 0;
  clearInterval(meelSpriteTimer);

  meelSpriteTimer = setInterval(function () {
    if (meelSpriteCurrentPct < 95) {
      meelSpriteCurrentPct += 1;
      if (b) b.style.width = meelSpriteCurrentPct + "%";
      if (t) t.textContent = meelSpriteCurrentPct + "% — Membuat Sprite VTT...";
    }
  }, 135);
}
// ─── FASE UTAMA SCRIPT ───
window.meelPhase = function (phase) {
  var overlay = document.getElementById("meel-overlay");
  if (overlay) overlay.style.display = "flex";
  var phases = ["download", "transcode", "sprite", "done", "error"];
  phases.forEach(function (p) {
    var el = document.getElementById("meel-phase-" + p);
    if (el) {
      el.classList.remove("active");
    }
  });
  var active = document.getElementById("meel-phase-" + phase);
  if (active) active.classList.add("active");
  if (phase === "transcode" && !_segsBuilt) {
    _segsBuilt = true;
    var row = document.getElementById("meel-segs");
    for (var i = 0; i < 16; i++) {
      var s = document.createElement("div");
      s.className = "meel-seg";
      s.id = "mseg" + i;
      row.appendChild(s);
    }
  }
  // Picu animasi merayap saat masuk fase sprite
  if (phase === "sprite" || phase === "sp") {
    startSpriteTrickle();
  } else {
    clearInterval(meelSpriteTimer);
  }
};
