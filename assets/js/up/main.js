/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* ────────────────────────────────────────────────────────────────
 * up/main.js — Entry point folder up/ (halaman upload_advanced.php).
 * PENTING:
 *  - main.js HARUS di-include TANPA atribut defer/async — document.write
 *    dari script async/defer diabaikan browser.
 *  - Versi (?v=) pada URL main.js diteruskan ke tiap sibling agar
 *    cache-busting (SW cache-first + Cache-Control immutable) tetap
 *    berfungsi. Halaman mem-pass versi = filemtime aset.
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
  var files = ["init.js", "url-preview.js", "submit.js"];
  for (var i = 0; i < files.length; i++) {
    document.write('<script src="' + base + files[i] + qs + '"><\/script>');
  }
})();
