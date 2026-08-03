/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* ────────────────────────────────────────────────────────────────
 * watch/main.js — Entry point folder watch/ (halaman music/watch.php).
 * PENTING:
 *  - main.js HARUS di-include TANPA atribut defer/async — document.write
 *    dari script async/defer diabaikan browser.
 *  - Versi (?v=) pada URL main.js diteruskan ke tiap sibling agar
 *    cache-busting (SW cache-first + Cache-Control immutable) tetap
 *    berfungsi. Halaman mem-pass versi = max filemtime seluruh folder.
 * ──────────────────────────────────────────────────────────────── */
(function () {
  "use strict";
  if (document.readyState !== "loading") return;
  var src =
    (document.currentScript && document.currentScript.src) ||
    (function () {
      var s = document.getElementsByTagName("script");
      return s[s.length - 1] ? s[s.length - 1].src : "";
    })();
  var base = src.substring(0, src.lastIndexOf("/") + 1);
  var m = src.match(/[?&]v=([^&]+)/);
  var qs = m ? "?v=" + encodeURIComponent(m[1]) : "";
  var files = [
    "state.js",
    "loop-ui.js",
    "audio-state.js",
    "equalizer.js",
    "misc.js",
    "mini-player.js",
    "player-core.js",
  ];
  for (var i = 0; i < files.length; i++) {
    document.write('<script src="' + base + files[i] + qs + '"><\/script>');
  }
})();
