/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
/* ────────────────────────────────────────────────────────────────
 * up/main.js — Entry point folder up/ (halaman upload_advanced.php).
 *
 * Memuat semua sibling JS di folder ini secara SINKRON & berurutan
 * via document.write, sehingga urutan load & global scope terjaga
 * tanpa harus menulis banyak <script> di halaman.
 *
 * PENTING:
 *  - main.js HARUS di-include TANPA atribut defer/async — document.write
 *    dari script async/defer diabaikan browser.
 *  - Versi (?v=) pada URL main.js diteruskan ke tiap sibling agar
 *    cache-busting (SW cache-first + Cache-Control immutable) tetap
 *    berfungsi. Halaman mem-pass versi = filemtime aset.
 * ──────────────────────────────────────────────────────────────── */
(function () {
  'use strict';

  // document.write hanya aman saat parser masih membaca dokumen.
  if (document.readyState !== 'loading') return;

  // Direktori main.js sendiri (base path untuk sibling)
  var src =
    (document.currentScript && document.currentScript.src) ||
    (function () {
      var s = document.getElementsByTagName('script');
      return s[s.length - 1] ? s[s.length - 1].src : '';
    })();
  var base = src.substring(0, src.lastIndexOf('/') + 1);
  // Teruskan versi dari URL main.js (?v=...) ke semua sibling
  var m = src.match(/[?&]v=([^&]+)/);
  var qs = m ? '?v=' + encodeURIComponent(m[1]) : '';

  var files = [
    'init.js',
    'url-preview.js',
    'submit.js'
  ];

  for (var i = 0; i < files.length; i++) {
    document.write('<script src="' + base + files[i] + qs + '"><\/script>');
  }
})();
