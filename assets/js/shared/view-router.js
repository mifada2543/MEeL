/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* view-router.js — AJAX partial-navigation antara music/watch.php dan
 * music/index.php, dipakai HANYA untuk transisi mini<->full player
 * (goBackToLibrary <-> expandPlayerFromMiniPlayer). Tujuannya: dokumen
 * TIDAK PERNAH benar-benar reload selama user masih di area musik, supaya
 * assets/js/shared/audio-engine.js yang persisten tidak pernah dibuat ulang.
 *
 * CATATAN PENTING (kenapa tidak sekadar swap <main>):
 * - Layout watch.php (#player-container di DALAM <main>) dan index.php
 *   (#mini-player-index di LUAR <main>) cukup beda, jadi router ini
 *   men-swap SELURUH isi <body> (kecuali node ber-atribut
 *   data-meel-persist, yaitu root audio-engine) supaya kedua arah transisi
 *   konsisten.
 * - state.js (watch) & shared/mini-player.js (index) memakai `let`/`const`
 *   di top-level scope. Kalau file itu di-<script>-kan dua kali dalam satu
 *   dokumen, browser throw "Identifier sudah dideklarasikan" dan mematikan
 *   seluruh JS di halaman. Makanya setiap script di-load lewat
 *   loadScriptOnce() yang di-guard per-src — bundle JS tiap view HANYA
 *   dieksekusi SEKALI seumur dokumen, walau viewnya dikunjungi berkali-kali.
 * - Fungsi bootstrap UI tiap halaman (window.meelInitWatchPlayer,
 *   window.bootPlayerIndex) TETAP dipanggil ulang SETIAP kali landing di
 *   view itu (idempotent by design) karena elemen DOM-nya baru tiap swap.
 */
(function () {
  "use strict";

  // ── Dedupe + cache-busting script (BUG FIX: fix tidak "nyangkut" di
  //    browser user) ───────────────────────────────────────────────────
  // Halaman memuat <script> DENGAN query cache-buster `?v=<mtime>` (dari
  // PHP), tapi DIRECT_SCRIPTS/bundle di router ini memakai src TANPA query.
  // Masalah 1 (duplikasi): kalau dedupe memakai URL penuh (ikut query),
  // router memuat ulang script yang sama → deklarasi `let`/`const` top-level
  // dieksekusi dua kali → SyntaxError "Identifier ... has already been
  // declared" + listener dobel. Masalah 2 (cache stale): assets/.htaccess
  // menyetel `Cache-Control: public, max-age=31536000, immutable` — URL
  // tanpa query itu akan dilayani SELAMANYA dari cache 1 tahun, jadi
  // browser bisa terus mengeksekusi KONTEN LAMA walau file di server sudah
  // diperbaiki (persis gejala "bug sudah di-fix tapi masih muncul").
  //
  // Solusi: (1) dedupe berdasarkan PATHNAME (abaikan query) → script yang
  // sudah ada di dokumen awal (dengan ?v= segar) tidak pernah dimuat ulang;
  // (2) script yang BELUM ada di dokumen di-fetch dengan query cache-buster
  // `_meel=<ts>` yang STABIL per page-session → selalu konten terbaru,
  // cache immutable 1 tahun tidak relevan, dan tetap dieksekusi sekali saja.
  function toPathname(absSrc) {
    try {
      return new URL(absSrc, window.location.href).pathname;
    } catch (e) {
      return String(absSrc);
    }
  }

  // Src <script> yang sudah ada di dokumen awal dianggap sudah "loaded"
  // (dibandingkan per pathname, supaya bentuk ?v= dan tanpa query dianggap
  // file yang sama).
  var loadedScriptSrcs = new Set();

  // PENTING: seeding dilakukan DUA KALI. Saat router ini dieksekusi (script
  // di akhir <body>), tag <script> yang berada SETELAH router di HTML —
  // mis. shared/mini-player.js di index.php, watch/main.js beserta bundle
  // document.write-nya — belum diparse DOM-nya, jadi belum terlihat oleh
  // querySelectorAll. Seeding tambahan di DOMContentLoaded menjamin semua
  // script awal (termasuk yang via document.write) masuk ke Set sebelum
  // transisi AJAX pertama (yang selalu dipicu aksi user, jadi selalu
  // setelah DOMContentLoaded).
  function seedLoadedScripts() {
    Array.prototype.forEach.call(
      document.querySelectorAll("script[src]"),
      function (s) {
        loadedScriptSrcs.add(toPathname(s.src));
      },
    );
  }
  seedLoadedScripts();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", seedLoadedScripts);
  } else {
    seedLoadedScripts();
  }

  // Cache-buster stabil per page-session (bukan per transisi), supaya
  // loadScriptOnce tetap no-op untuk URL yang sama pada transisi berikutnya.
  var SESSION_TS = Date.now();

  function loadScriptOnce(absSrc) {
    var key = toPathname(absSrc);
    if (loadedScriptSrcs.has(key)) return Promise.resolve();
    loadedScriptSrcs.add(key);
    // Paksa fresh fetch: tanpa query unik, cache immutable 1 tahun akan
    // melayani versi lama selamanya.
    var buster = absSrc.indexOf("?") === -1 ? "?_meel=" : "&_meel=";
    var srcWithBuster = absSrc + buster + SESSION_TS;
    return new Promise(function (resolve, reject) {
      var s = document.createElement("script");
      s.src = srcWithBuster;
      s.onload = function () {
        resolve();
      };
      s.onerror = function () {
        loadedScriptSrcs.delete(key);
        reject(new Error("Gagal memuat script: " + absSrc));
      };
      document.body.appendChild(s);
    });
  }

  function toAbsolute(relSrc) {
    return new URL(relSrc, window.location.href).href;
  }

  // Set href stylesheet yang SUDAH ada di dokumen saat ini (absolute URL).
  var loadedStyleHrefs = new Set(
    Array.prototype.map.call(
      document.querySelectorAll('link[rel="stylesheet"][href]'),
      function (l) {
        return l.href;
      },
    ),
  );

  // BUG FIX: watch.php dan index.php TIDAK memuat <link rel="stylesheet">
  // yang sama persis (mis. plyr.css & comment.css cuma ada di watch.php;
  // index/main.css cuma ada di index.php). view-router cuma pernah swap
  // <body>, <head> tidak pernah disentuh — jadi kalau user landing
  // (full reload) di salah satu halaman lalu AJAX pindah ke halaman lain,
  // stylesheet khusus halaman tujuan bisa hilang total (mis. Plyr tampil
  // tanpa CSS-nya). Fungsi ini menambahkan <link> yang ADA di halaman
  // hasil fetch tapi BELUM ada di <head> dokumen saat ini. Stylesheet yang
  // sudah tidak relevan sengaja TIDAK dihapus (aman, cuma sedikit boros,
  // jauh lebih aman daripada resiko flash-of-unstyled-content).
  function ensureViewStyles(doc) {
    var links = doc.querySelectorAll('link[rel="stylesheet"][href]');
    for (var i = 0; i < links.length; i++) {
      var absHref = toAbsolute(links[i].getAttribute("href"));
      if (loadedStyleHrefs.has(absHref)) continue;
      loadedStyleHrefs.add(absHref);
      var link = document.createElement("link");
      link.rel = "stylesheet";
      link.href = absHref;
      document.head.appendChild(link);
    }
  }

  // Daftar <script src> "langsung" yang wajib ada per view, HARUS SINKRON
  // dengan urutan <script> di music/watch.php & music/index.php.
  var DIRECT_SCRIPTS = {
    watch: [
      "../assets/js/compatibilitas/plyr.min.js",
      "../assets/js/shared/keyboard.js",
      "../assets/js/shared/temp-index.js",
      "../assets/js/shared/plyr-config.js",
      "../assets/js/shared/format-time.js",
      "../assets/js/shared/resume-modal.js",
      "../assets/js/shared/mini-player-popstate.js",
      "../assets/js/shared/audio-engine.js",
      "../assets/js/shared/view-router.js",
    ],
    index: [
      "../assets/js/shared/format-time.js",
      "../assets/js/shared/keyboard.js",
      "../assets/js/compatibilitas/plyr.min.js",
      "../assets/js/shared/plyr-config.js",
      "../assets/js/shared/audio-engine.js",
      "../assets/js/shared/view-router.js",
      "../assets/js/music/shared/mini-player.js",
    ],
  };
  var BUNDLE_GLOBAL = {
    watch: "MEEL_WATCH_BUNDLE",
    index: "MEEL_INDEX_BUNDLE",
  };
  var BUNDLE_LOADER_SRC = {
    watch: "../assets/js/music/watch/main.js",
    index: "../assets/js/music/index/main.js",
  };

  async function ensureViewScripts(viewType) {
    var directList = DIRECT_SCRIPTS[viewType] || [];
    for (var i = 0; i < directList.length; i++) {
      await loadScriptOnce(toAbsolute(directList[i]));
    }
    // Muat loader bundle (watch/main.js atau index/main.js). Kalau sudah
    // pernah dimuat, loadScriptOnce() no-op — tapi window.MEEL_*_BUNDLE
    // dari load sebelumnya sudah tetap ada di memory (global, tidak hilang).
    await loadScriptOnce(toAbsolute(BUNDLE_LOADER_SRC[viewType]));
    var bundleInfo = window[BUNDLE_GLOBAL[viewType]];
    if (bundleInfo && Array.isArray(bundleInfo.files)) {
      for (var j = 0; j < bundleInfo.files.length; j++) {
        await loadScriptOnce(bundleInfo.files[j]);
      }
    }
  }

  // Re-set window.MEEL_MUSIC_CONFIG / window.MEEL_INDEX_CONFIG dari HTML
  // hasil fetch — dieksekusi ulang tiap transisi supaya config match track
  // yang sedang dibuka (aman/idempotent, cuma assignment object literal).
  function applyInlineConfig(doc, viewType) {
    var varName = viewType === "watch" ? "MEEL_MUSIC_CONFIG" : "MEEL_INDEX_CONFIG";
    var scripts = doc.querySelectorAll("script:not([src])");
    for (var i = 0; i < scripts.length; i++) {
      var text = scripts[i].textContent || "";
      if (text.indexOf("window." + varName) !== -1) {
        try {
          (0, eval)(text);
        } catch (e) {
          console.error("❌ view-router: gagal apply " + varName + ":", e);
        }
        return;
      }
    }
  }

  /**
   * Navigasi AJAX ke `url` (halaman watch.php atau index.php lain) tanpa
   * reload dokumen. `viewType` = 'watch' | 'index'.
   * `onAfterSwap(doc)` dipanggil setelah DOM & script siap, sebelum
   * pushState — dipakai caller untuk mount() audio-engine & panggil
   * bootstrap idempotent halaman (meelInitWatchPlayer / bootPlayerIndex).
   */
  window.meelNavigateView = async function (url, viewType, options) {
    options = options || {};
    try {
      var res = await fetch(url, { credentials: "same-origin" });
      if (!res.ok) throw new Error("HTTP " + res.status);
      var html = await res.text();
      var doc = new DOMParser().parseFromString(html, "text/html");

      // 0. Bug fix: pastikan <head> punya semua stylesheet yang dibutuhkan
      //    halaman tujuan (lihat komentar di ensureViewStyles di atas).
      ensureViewStyles(doc);

      // 1. Lepas node persisten (root audio-engine) dari body saat ini,
      //    simpan di memory (BUKAN dihapus) supaya <audio> tidak pernah
      //    kehilangan koneksi jaringan/posisi playback-nya.
      var persisted = Array.prototype.slice.call(
        document.body.querySelectorAll("[data-meel-persist]"),
      );
      var holder = document.createDocumentFragment();
      persisted.forEach(function (n) {
        holder.appendChild(n);
      });

      // 2. Ganti seluruh isi <body> dengan markup halaman baru.
      document.body.innerHTML = "";
      Array.prototype.forEach.call(doc.body.children, function (node) {
        // <script src> asli dari HTML fetch TIDAK auto-eksekusi lewat
        // importNode — sengaja diabaikan di sini, dimuat lewat
        // ensureViewScripts() supaya guarded (anti duplikasi deklarasi).
        if (node.tagName === "SCRIPT" && node.src) return;
        document.body.appendChild(document.importNode(node, true));
      });

      // 3. Kembalikan node persisten ke body (posisi sementara; caller akan
      //    mount() ke slot yang benar lewat onAfterSwap).
      document.body.appendChild(holder);

      document.title = doc.title;
      applyInlineConfig(doc, viewType);

      // 4. Pastikan bundle JS untuk view ini sudah/lagi dimuat (sekali saja).
      await ensureViewScripts(viewType);

      // 5. Riwayat URL & judul, supaya tombol back & reload tetap benar.
      if (options.pushState !== false) {
        window.history.pushState({ meelView: viewType }, "", url);
      }

      // BUG FIX: onAfterSwap (mis. bootPlayerIndex()/meelInitWatchPlayer())
      // dijalankan DULU, baru lucide.createIcons()/htmx.process. Sebelumnya
      // urutan ini terbalik — kalau onAfterSwap menambah elemen data-lucide
      // baru ke DOM (mis. saat bootPlayerIndex merender ulang bagian
      // tertentu), elemen itu tidak pernah ke-render jadi ikon karena
      // lucide.createIcons() sudah lebih dulu jalan sebelum elemen itu ada.
      if (typeof options.onAfterSwap === "function") {
        options.onAfterSwap(doc);
      }

      if (window.lucide) window.lucide.createIcons();
      if (window.htmx) window.htmx.process(document.body);
      return true;
    } catch (err) {
      console.error("❌ view-router: navigasi AJAX gagal, fallback ke reload penuh:", err);
      // Fallback aman: kalau AJAX gagal (mis. jaringan putus), tetap
      // navigasi biasa supaya user tidak stuck.
      window.location.href = url;
      return false;
    }
  };
})();