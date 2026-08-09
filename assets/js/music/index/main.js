/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* index/main.js — Entry point folder index/ (halaman music/index.php). */
(function () {
  'use strict';
  var src =
    (document.currentScript && document.currentScript.src) ||
    (function () {
      var s = document.getElementsByTagName('script');
      return s[s.length - 1] ? s[s.length - 1].src : '';
    })();
  var base = src.substring(0, src.lastIndexOf('/') + 1);
  var m = src.match(/[?&]v=([^&]+)/);
  var qs = m ? '?v=' + encodeURIComponent(m[1]) : '';
  var files = [
    'library-ui.js',
    'load-more.js',
    'index.js'
  ];
  // Dipakai oleh assets/js/shared/view-router.js supaya bundle ini bisa
  // dimuat ulang (SEKALI per page-session) tanpa duplikasi daftar file.
  // Catatan: shared/mini-player.js dimuat terpisah (bukan lewat loader
  // ini) — router memuatnya sendiri lewat daftar <script> langsung.
  window.MEEL_INDEX_BUNDLE = {
    base: base,
    qs: qs,
    files: files.map(function (f) {
      return base + f + qs;
    }),
  };
  if (document.readyState !== 'loading') return;
  for (var i = 0; i < files.length; i++) {
    document.write('<script src="' + base + files[i] + qs + '"><\/script>');
  }
})();
