<?php


require_once __DIR__ . '/modules/core/SwPrecache.php';

$sw_precache_urls = SwPrecache::all();
$sw_version       = SwPrecache::version();

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
?>











const SW_VERSION = <?php echo json_encode($sw_version) ?>;
const STATIC_CACHE = 'meel-static-' + SW_VERSION;
const PAGE_CACHE   = 'meel-pages-' + SW_VERSION;
const PAGE_CACHE_MAX = 100; 



const OFFLINE_URL = new URL('err/offline.php', self.registration.scope).href;




const PRECACHE_URLS = <?php echo json_encode($sw_precache_urls, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?>;


self.addEventListener('install', (event) => {
  console.log('[SW] Install ' + SW_VERSION);

  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => {
      return cache.addAll(PRECACHE_URLS).catch((err) => {
        console.warn('[SW] Pre-cache warning:', err.message);
      });
    }).then(() => {
      
      return self.skipWaiting();
    })
  );
});


self.addEventListener('activate', (event) => {
  console.log('[SW] Activate ' + SW_VERSION);

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
      
      
      if (self.registration.navigationPreload) {
        return self.registration.navigationPreload.enable().catch(() => {});
      }
    }).then(() => {
      
      return self.clients.claim();
    })
  );
});


self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET') return;

  
  if (url.origin !== location.origin) return;

  
  if (isApiRequest(url)) {
    return;
  }

  
  if (isStreamingMedia(url)) {
    return;
  }

  
  if (url.pathname.includes('/profile/upload/')) {
    event.respondWith(networkFirst(request, PAGE_CACHE));
    return;
  }

  
  if (url.pathname.includes('/arcade/chess/assets/js/')) {
    event.respondWith(networkFirst(request, PAGE_CACHE));
    return;
  }

  
  if (isStaticAsset(url)) {
    
    if (url.searchParams.has('v')) {
      event.respondWith(cacheFirst(request, STATIC_CACHE));
    } else {
      event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
    }
    return;
  }

  
  if (isPageRequest(request)) {
    event.respondWith(networkFirst(request, PAGE_CACHE, event.preloadResponse));
    return;
  }

  
  event.respondWith(networkFirst(request, PAGE_CACHE));
});







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
    
    if (request.destination === 'document') {
      return caches.match(OFFLINE_URL);
    }
    
    return new Response('', { status: 200, headers: { 'Content-Type': 'text/plain' } });
  }
}







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





async function networkFirst(request, cacheName, preloadPromise) {
  try {
    
    
    let network = null;
    if (preloadPromise) {
      const preload = await preloadPromise.catch(() => null);
      if (preload && preload.ok) network = preload;
    }
    if (!network) network = await fetch(request);

    if (network.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, network.clone());
      
      
      trimCache(cacheName, PAGE_CACHE_MAX);
    }
    return network;
  } catch (err) {
    const cached = await caches.match(request);
    if (cached) return cached;

    
    if (request.destination === 'document') {
      return caches.match(OFFLINE_URL);
    }

    return new Response('', { status: 503, headers: { 'Content-Type': 'text/plain' } });
  }
}



function isStaticAsset(url) {
  return /\.(css|js|woff2?|ttf|otf|eot|png|jpg|jpeg|gif|webp|svg|ico|webmanifest)$/i.test(url.pathname);
}

function isApiRequest(url) {
  
  
  const p = url.pathname;
  
  
  return p.includes('/controllers/') ||
         p.includes('/auth/') ||
         p.includes('/partials/engine/') ||
         p.includes('/controller/') ||      
         p.includes('/api/') ||
         p.includes('/system/') ||
         p.includes('/search_') ||
         p.includes('load_more') ||
         p.includes('like.php') ||
         p.includes('comment.php') ||
         p.includes('delete_comment') ||
         p.includes('admin_data') ||
         p.includes('playlist_action') ||
         p.includes('stream.php') ||        
         p.includes('read_pdf') ||
         p.includes('download') ||          
         p.includes('post_encode') ||
         p.includes('download_transcode') ||
         
         /\/(api|system)\/[^/]+\/?$/.test(p) ||      
         /\/(music|video|drive|books)\/stream\/?$/.test(p) || 
         /\/(music|video|books)\/search\/?$/.test(p) ||
         /\/(music|video)\/load-more\/?$/.test(p) ||
         /\/music\/playlist-action\/?$/.test(p) ||
         /\/books\/read-pdf\/?$/.test(p) ||
         /\/admin\/(actions|data)\/?$/.test(p) ||
         /\/profile\/(edit|manage-action)\/?$/.test(p);
}

function isStreamingMedia(url) {
  
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
    
    await cache.delete(keys[0]);
  }
}
