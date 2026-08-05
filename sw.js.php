<?php
/**
 * sw.js.php — Service Worker MEeL (DIBANGKITKAN DINAMIS oleh PHP).
 *
 * File ini di-serve sebagai /sw.js lewat rewrite di .htaccess:
 *   RewriteRule ^sw\.js$ sw.js.php [L]
 *
 * Mengapa dinamis:
 *  - Daftar precache CSS modul diambil otomatis dari setiap manifest.php
 *    di subfolder assets/css — menambah folder modul baru TIDAK perlu
 *    mengubah file ini.
 *  - SW_VERSION dihitung dari hash isi semua aset precache (SwPrecache::version)
 *    → setiap perubahan konten otomatis menaikkan versi SW (update + purge cache).
 *
 * Output DIJAGA DETERMINISTIK (tanpa timestamp) agar browser tidak menganggap
 * SW berubah pada setiap kunjungan — update SW hanya terjadi saat konten asli
 * benar-benar berubah.
 *
 * @license GPL v3
 */
require_once __DIR__ . '/modules/core/SwPrecache.php';

$sw_precache_urls = SwPrecache::all();
$sw_version       = SwPrecache::version();

// Wajib sebelum output apa pun: browser menolak SW yang MIME-nya bukan JS.
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
?>
/**
 * MEeL-HUB — Service Worker (dibangkitkan dari sw.js.php)
 *
 * Cache strategies:
 *  - STATIC_ASSETS: Cache-first (CSS, JS, fonts, images) — pre-cached on install
 *  - PAGES: Network-first (HTML pages) — fallback to cache, then offline page
 *  - API: Network-only (dynamic data)
 *
 * @license GPL v3
 */

const SW_VERSION = <?php echo json_encode($sw_version) ?>;
const STATIC_CACHE = 'meel-static-' + SW_VERSION;
const PAGE_CACHE   = 'meel-pages-' + SW_VERSION;
const PAGE_CACHE_MAX = 100; // batas entri cache halaman (cegah membengkak)

// URL absolut halaman offline — dihitung dari scope SW (self.registration.scope),
// jadi portabel saat project di-deploy ke root ATAU subfolder (tidak hardcoded /MEeL/).
const OFFLINE_URL = new URL('err/offline.php', self.registration.scope).href;

// ─── FILES TO PRE-CACHE ON INSTALL ───────────────────────────────────────────
// Daftar DINAMIS: aset tetap + semua modul CSS dari assets/css/*/manifest.php.
// Paths are relative to the SW scope (project root).
const PRECACHE_URLS = <?php echo json_encode($sw_precache_urls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?>;

// ─── INSTALL ─────────────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
  console.log('[SW] Install ' + SW_VERSION);

  // Pre-cache static assets
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => {
      return cache.addAll(PRECACHE_URLS).catch((err) => {
        console.warn('[SW] Pre-cache warning:', err.message);
      });
    }).then(() => {
      // Activate immediately — don't wait for reload
      return self.skipWaiting();
    })
  );
});

// ─── ACTIVATE ────────────────────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
  console.log('[SW] Activate ' + SW_VERSION);

  // Clean up old caches
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => {
            return name.startsWith('meel-') &&
                   name !== STATIC_CACHE &&
                   name !== PAGE_CACHE;
          })
          .map((name) => {
            console.log('[SW] Delete old cache:', name);
            return caches.delete(name);
          })
      );
    }).then(() => {
      // Navigation preload: minta respons navigasi paralel dengan boot SW
      // → first paint lebih cepat (tidak menunggu SW aktif dulu).
      if (self.registration.navigationPreload) {
        return self.registration.navigationPreload.enable().catch(() => {});
      }
    }).then(() => {
      // Claim all clients immediately
      return self.clients.claim();
    })
  );
});

// ─── FETCH ───────────────────────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') return;

  // Skip non-origin requests (CDN, external)
  if (url.origin !== location.origin) return;

  // ── API / Dynamic endpoints → Network only ──
  if (isApiRequest(url)) {
    return;
  }

  // ── Media streaming & download → Network only ──
  // Video/audio/arsip bisa ratusan MB — melewati SW malah bikin cache meledak
  // dan buffering/seek bermasalah. Selalu ambil langsung dari network.
  if (isStreamingMedia(url)) {
    return;
  }

  // ── Avatar profil (profile/upload/) → Network-first ──
  // URL avatar TIDAK pernah berubah (mis. user_1.webp) padahal isinya sering
  // diganti. Cache-first bikin foto lama (mis. hasil tes berwarna biru) terus
  // tampil sampai hard refresh — pakai network-first agar foto baru langsung
  // muncul, dengan fallback ke cache saat offline.
  if (url.pathname.includes('/profile/upload/')) {
    event.respondWith(networkFirst(request, PAGE_CACHE));
    return;
  }

  // ── Static assets (CSS, JS, fonts, images) ──
  if (isStaticAsset(url)) {
    // Aset dengan cache-buster (?v=filemtime) = immutable → cache-first.
    // Aset TANPA ?v= (URL sama, tapi isi bisa berubah setelah deploy/edits)
    // → stale-while-revalidate: sajikan cache (cepat) + refresh background,
    //   supaya perubahan file langsung terlihat tanpa hard refresh.
    if (url.searchParams.has('v')) {
      event.respondWith(cacheFirst(request, STATIC_CACHE));
    } else {
      event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
    }
    return;
  }

  // ── HTML pages → Network-first (pakai navigationPreload bila tersedia) ──
  if (isPageRequest(request)) {
    event.respondWith(networkFirst(request, PAGE_CACHE, event.preloadResponse));
    return;
  }

  // ── Everything else → Network-first ──
  event.respondWith(networkFirst(request, PAGE_CACHE));
});

// ─── STRATEGIES ──────────────────────────────────────────────────────────────

/**
 * Cache-first: serve from cache, fallback to network.
 * Good for static assets that rarely change.
 */
async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request);
  if (cached) return cached;

  try {
    const network = await fetch(request);
    if (network.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, network.clone());
    }
    return network;
  } catch (err) {
    // If asset fails to load, return cached offline fallback for pages
    if (request.destination === 'document') {
      return caches.match(OFFLINE_URL);
    }
    // For other assets, return a transparent placeholder
    return new Response('', { status: 200, headers: { 'Content-Type': 'text/plain' } });
  }
}

/**
 * Stale-while-revalidate: serve from cache (fast), refresh in background.
 * For unversioned static assets whose URL stays the same but content can
 * change after deploy (e.g. main.js edited during development).
 * Prevents "must hard-refresh to see changes" + stale-JS-against-new-HTML.
 */
async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  const networkPromise = fetch(request)
    .then((response) => {
      if (response.ok) cache.put(request, response.clone());
      return response;
    })
    .catch(() => null);

  if (cached) return cached;
  const network = await networkPromise;
  if (network) return network;
  return new Response('', { status: 503, headers: { 'Content-Type': 'text/plain' } });
}

/**
 * Network-first: try network, fallback to cache, then offline.
 * Good for HTML pages that change frequently.
 */
async function networkFirst(request, cacheName, preloadPromise) {
  try {
    // Kalau ada (navigasi), pakai hasil navigationPreload dulu — respons
    // sudah berjalan paralel dengan boot SW, jadi lebih cepat dari fetch().
    let network = null;
    if (preloadPromise) {
      const preload = await preloadPromise.catch(() => null);
      if (preload && preload.ok) network = preload;
    }
    if (!network) network = await fetch(request);

    if (network.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, network.clone());
      // Batasi ukuran cache halaman agar tidak membengkak tanpa batas
      // (mis. halaman watch.php?id=X yang terus berganti).
      trimCache(cacheName, PAGE_CACHE_MAX);
    }
    return network;
  } catch (err) {
    const cached = await caches.match(request);
    if (cached) return cached;

    // Offline fallback for page requests
    if (request.destination === 'document') {
      return caches.match(OFFLINE_URL);
    }

    return new Response('', { status: 503, headers: { 'Content-Type': 'text/plain' } });
  }
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────

function isStaticAsset(url) {
  return /\.(css|js|woff2?|ttf|otf|eot|png|jpg|jpeg|gif|webp|svg|ico|webmanifest)$/i.test(url.pathname);
}

function isApiRequest(url) {
  // pathname absolut (/MEeL/controllers/... ATAU /controllers/... di root) —
  // pakai includes('/.../') agar cocok untuk subdir maupun root deployment.
  const p = url.pathname;
  return p.includes('/controllers/') ||
         p.includes('/auth/') ||
         p.includes('/partials/engine/') ||
         p.includes('/controller/') ||      // polling catur (arcade/chess/controller/)
         p.includes('/api/') ||
         p.includes('/search_') ||
         p.includes('load_more') ||
         p.includes('like.php') ||
         p.includes('comment.php') ||
         p.includes('delete_comment') ||
         p.includes('admin_data') ||
         p.includes('playlist_action') ||
         p.includes('stream.php') ||        // streaming audio (Range request)
         p.includes('read_pdf') ||
         p.includes('download') ||          // drive/download.php, download_transcode.php
         p.includes('post_encode') ||
         p.includes('download_transcode');
}

function isStreamingMedia(url) {
  // File media & arsip — tidak pernah disentuh SW (network-only).
  return /\.(mp4|webm|mkv|avi|mov|m4v|mp3|m4a|ogg|oga|wav|flac|aac|opus|pdf|epub|zip|rar|7z|tar|gz)$/i.test(url.pathname);
}

function isPageRequest(request) {
  return request.destination === 'document' ||
         request.headers.get('Accept')?.includes('text/html');
}

async function trimCache(cacheName, maxEntries) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length > maxEntries) {
    // Buang entri terlama (urutan insert) satu per satu.
    await cache.delete(keys[0]);
  }
}
