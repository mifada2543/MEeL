/**
 * MEeL-HUB — Service Worker v1.0
 *
 * Cache strategies:
 *  - STATIC_ASSETS: Cache-first (CSS, JS, fonts, images) — pre-cached on install
 *  - PAGES: Network-first (HTML pages) — fallback to cache, then offline page
 *  - API: Network-only (dynamic data)
 *
 * @license GPL v3
 */

const SW_VERSION = 'v1.1-20260729';
const STATIC_CACHE = 'meel-static-' + SW_VERSION;
const PAGE_CACHE   = 'meel-pages-' + SW_VERSION;

// ─── FILES TO PRE-CACHE ON INSTALL ───────────────────────────────────────────
// ─── FILES TO PRE-CACHE ON INSTALL ───────────────────────────────────────────
// Paths are relative to the SW scope (project root).
// e.g. if SW serves from /MEeL/, these resolve to /MEeL/assets/css/...
const PRECACHE_URLS = [
  // CSS
  'assets/css/index(hub).css',
  'assets/css/tailwind.min.css',
  'assets/css/font.css',
  'assets/css/plyr.css',
  'assets/css/video/main.css',
  'assets/css/music/main.css',
  'assets/css/books/main.css',
  'assets/css/drive/main.css',
  'assets/css/admin/main.css',
  'assets/css/shared/upload-form.css',
  'assets/css/up.css',
  'assets/css/introduction.css',
  // JS
  'assets/js/compatibilitas/lucide.js',
  'assets/js/compatibilitas/hls.js',
  // Font
  'assets/css/font/latin.woff2',
  // Assets
  'assets/MEeL.png',
  // Manifest
  'assets/manifest.json',
  // Offline page
  'err/offline.php',
];

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

  // ── Static assets (CSS, JS, fonts, images) → Cache-first ──
  if (isStaticAsset(url)) {
    event.respondWith(cacheFirst(request, STATIC_CACHE));
    return;
  }

  // ── HTML pages → Network-first ──
  if (isPageRequest(request)) {
    event.respondWith(networkFirst(request, PAGE_CACHE));
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
      return caches.match('err/offline.php');
    }
    // For other assets, return a transparent placeholder
    return new Response('', { status: 200, headers: { 'Content-Type': 'text/plain' } });
  }
}

/**
 * Network-first: try network, fallback to cache, then offline.
 * Good for HTML pages that change frequently.
 */
async function networkFirst(request, cacheName) {
  try {
    const network = await fetch(request);
    if (network.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, network.clone());
    }
    return network;
  } catch (err) {
    const cached = await caches.match(request);
    if (cached) return cached;

    // Offline fallback for page requests
    if (request.destination === 'document') {
      return caches.match('/MEeL/err/offline.php');
    }

    return new Response('', { status: 503, headers: { 'Content-Type': 'text/plain' } });
  }
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────

function isStaticAsset(url) {
  return /\.(css|js|woff2?|ttf|otf|eot|png|jpg|jpeg|gif|webp|svg|ico|webmanifest)$/i.test(url.pathname);
}

function isApiRequest(url) {
  // Skip API, controllers, AJAX endpoints
  return /^\/(controllers\/|auth\/login|auth\/register)/.test(url.pathname) ||
         url.pathname.includes('search_') ||
         url.pathname.includes('load_more') ||
         url.pathname.includes('like.php') ||
         url.pathname.includes('delete_comment') ||
         url.pathname.includes('admin_data');
}

function isPageRequest(request) {
  return request.destination === 'document' ||
         request.headers.get('Accept')?.includes('text/html');
}
