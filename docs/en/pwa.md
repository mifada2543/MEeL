# 📱 PWA — Progressive Web App

Documentation of MEeL's PWA layer: the dynamic service worker, cache strategies, web app manifest, and offline behavior.

---

## 📋 Table of Contents

- [Architecture Overview](#architecture-overview)
- [Dynamic Service Worker (`sw.js.php`)](#dynamic-service-worker-swjphp)
- [Precache Generator (`SwPrecache`)](#precache-generator-swprecache)
- [Automatic SW_VERSION](#automatic-sw_version)
- [Cache Strategies](#cache-strategies)
- [Adding a New Module Folder (Zero Maintenance)](#adding-a-new-module-folder-zero-maintenance)
- [Web App Manifest](#web-app-manifest)
- [Offline Support](#offline-support)
- [Update Flow (Auto-Reload)](#update-flow-auto-reload)
- [Deployment Requirements](#deployment-requirements)
- [Debugging & Troubleshooting](#debugging--troubleshooting)

---

## Architecture Overview

MEeL is a full **installable PWA**:

```
Browser
  │  register('/sw.js')        ← URL tetap, isi dinamis (PHP)
  ▼
Apache .htaccess rewrite  ──►  sw.js.php (PHP generator)
                                   │
                                   ├── SwPrecache::all()      → PRECACHE_URLS
                                   └── SwPrecache::version()  → SW_VERSION
```

| Component | File | Purpose |
|---|---|---|
| Service Worker (dynamic) | `sw.js.php` | Full SW script, generated per request from `SwPrecache` |
| Precache generator | `modules/core/SwPrecache.php` | Builds precache list + auto version |
| Web app manifest | `assets/manifest.json` | Install metadata (name, icons, shortcuts) |
| Icons | `assets/MEeL-{180,192,512}.png` | Real 192×192, 512×512 (+maskable), 180×180 |
| Offline page | `err/offline.php` | Fallback when a page is not cached |
| Registration | `partials/head.php` | SW register + update check + auto-reload |

---

## Dynamic Service Worker (`sw.js.php`)

`sw.js.php` generates the entire service worker on the fly. The browser still
requests **`/sw.js`** (the URL is kept via an `.htaccess` rewrite), so the PWA
scope and registration code never change:

```apache
# .htaccess (root)
RewriteRule ^sw\.js$ sw.js.php [L]
```

Generated response headers:

```http
Content-Type: application/javascript; charset=utf-8
Cache-Control: no-cache, no-store, must-revalidate
```

> ⚠️ The generator **must** emit `Content-Type: application/javascript` —
> browsers reject service workers served as `text/html`.

### Why dynamic?

- **Precache list** is derived from the actual `assets/css/*/manifest.php` files
  — adding a module folder requires **zero changes** to the service worker.
- **`SW_VERSION`** is derived from the content hash of every precached asset —
  any real content change automatically bumps the version, triggering a SW
  update and old-cache purge in the browser.
- The output is **deterministic** (no timestamps): identical content produces
  byte-identical output, so the browser does not re-install the SW on every visit.

---

## Precache Generator (`SwPrecache`)

**File:** `modules/core/SwPrecache.php` (registered in `modules/autoload.php`)

| Method | Returns | Description |
|---|---|---|
| `baseAssets()` | `string[]` | Fixed assets: hub CSS, tailwind, font, plyr, JS libs, icons, manifest, offline page |
| `moduleAssets()` | `string[]` | **All** CSS modules from every `assets/css/*/manifest.php` (video, music, books, drive, admin, engine, up) |
| `all()` | `string[]` | Full precache list = `baseAssets()` + `moduleAssets()` |
| `version()` | `string` | `v2-<hash10>` — content hash of all inputs (assets + manifests + generator code) |

The root path is computed with `dirname(__DIR__, 2)` — **not** the `MEEL_ROOT`
constant (which only exists in the test environment), so `sw.js.php` works in
production without any extra bootstrap.

### How `version()` works

```php
$parts = [];
foreach (SwPrecache::all() as $rel) {
    $abs = <root> . '/' . $rel;
    $parts[] = is_file($abs) ? md5_file($abs) : 'MISSING:' . $rel; // missing file = different hash
}
foreach (glob(<root> . '/assets/css/*/manifest.php') as $manifest) {
    $parts[] = md5_file($manifest);
}
$parts[] = md5_file(__FILE__); // generator changes → version changes
return 'v2-' . substr(md5(implode('|', $parts)), 0, 10);
```

Any change to any precached file → new version → new cache name
(`meel-static-v2-<hash>`) → `activate` purges old caches.

---

## Automatic SW_VERSION

Because `SW_VERSION` is content-derived, these scenarios happen automatically:

| Scenario | SW_VERSION | Result |
|---|---|---|
| Edit a CSS/JS asset | changes | SW re-installs, old caches purged, new assets pre-cached |
| Add a new module folder (`assets/css/<folder>/manifest.php` + CSS) | changes | Folder auto-added to precache on next visit |
| Delete a precached file | changes (`MISSING:` marker) | Forces an update — CI test catches the missing file permanently |
| No content change | same | No SW update (deterministic output) |

---

## Cache Strategies

| Category | Strategy | Description |
|---|---|---|
| **HTML pages** | Network-first | Fresh page when online; cache fallback; then `err/offline.php` |
| **Versioned assets** (`?v=filemtime`) | Cache-first | Immutable — serve from cache, fetch only on miss |
| **Unversioned assets** (no `?v=`) | Stale-while-revalidate | Serve cache instantly + refresh in background — no hard-refresh needed after deploy |
| **API / controllers** | Network-only | Never cached: `controllers/`, `auth/`, `partials/engine/`, `controller/` (chess polling), `search_*`, `load_more`, `like.php`, `comment.php`, `delete_comment`, `admin_data`, `playlist_action`, `stream.php`, `read_pdf`, `download*`, `post_encode` |
| **Media files** (mp4/webm/mp3/pdf/zip…) | Network-only | Streaming/downloads never touch the SW (avoids cache explosion) |
| **Avatars** (`profile/upload/`) | Network-first | Profile photos update immediately (URL never changes) |

### Page cache size limit

The page cache (`meel-pages-*`) is capped at **100 entries** via `trimCache()` —
dynamic pages such as `watch.php?id=X` cannot grow the cache without limit.

### Navigation preload

Enabled on `activate` and consumed by the network-first strategy for
navigations — the navigation response starts in parallel with the SW boot,
improving first paint.

---

## Adding a New Module Folder (Zero Maintenance)

1. Create the CSS files, e.g. `assets/css/arcade/base.css`, `arcade/cards.css`
2. Create `assets/css/arcade/manifest.php`:
   ```php
   <?php
   /** assets/css/arcade/manifest.php — Daftar modul CSS folder arcade/ */
   return [
       'base.css',
       'cards.css',
   ];
   ```
3. Load them in your page via `manifest.php` (same pattern as `video/`, `music/`, …)

That's it — on the next visit the SW auto-includes the new folder and bumps
`SW_VERSION`. **No changes to `sw.js.php` or `SwPrecache.php`.**

> If you add a **new `manifest.php`** for a folder whose CSS should NOT be
> pre-cached, keep in mind the current design pre-caches **all** folders that
> have a `manifest.php` (enforced by `CssManifestTest`).

---

## Web App Manifest

**File:** `assets/manifest.json`

| Property | Value | Notes |
|---|---|---|
| `name` / `short_name` | MEeL — Media Hub Platform | |
| `icons` | 192×192 (any), 512×512 (any), 512×512 (**maskable**) | Real files — required for Chrome install prompt |
| `start_url` | `../index.php?source=pwa` | Relative to manifest |
| `display` | `standalone` (+ `display_override`) | Full-screen app window |
| `theme_color` / `background_color` | `#05070c` | Dark theme |
| `scope` / `id` | `/MEeL/` | Deployment-specific — adjust if hosted at root |
| `shortcuts` | Video, Music, Books, Drive | Right-click / long-press launch shortcuts |

### Icons

| File | Size | Used for |
|---|---|---|
| `assets/MEeL.png` | 500×500 | Favicon, OG image |
| `assets/MEeL-192.png` | 192×192 | Manifest, shortcuts |
| `assets/MEeL-512.png` | 512×512 | Manifest (any + maskable) |
| `assets/MEeL-180.png` | 180×180 | Apple touch icon |

Generated from `MEeL.png` with ImageMagick:
```bash
convert MEeL.png -resize 192x192 MEeL-192.png
convert MEeL.png -resize 512x512 MEeL-512.png
convert MEeL.png -resize 180x180 MEeL-180.png
```

### iOS / Android standalone meta

`partials/head.php` emits:

```html
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MEeL">
<link rel="apple-touch-icon" sizes="180x180" href=".../assets/MEeL-180.png">
```

---

## Offline Support

- Pages visited while online are cached (network-first) → available offline.
- Pre-cached assets (all CSS modules + core JS) work offline.
- `err/offline.php` is pre-cached and served when a requested page has no cache.
- The offline fallback URL is computed from `self.registration.scope`
  (`OFFLINE_URL`), so it works both at root and in sub-folder deployments.

---

## Update Flow (Auto-Reload)

`partials/head.php` registers the SW with `updateViaCache: 'none'` and calls
`reg.update()` on every load. When a new SW installs (the SW uses
`skipWaiting()` + `clients.claim()`):

1. `updatefound` → `installed` → flag `meel_pwa_update` set in `sessionStorage`
   (only when the page is already controlled — no reload on first install).
2. `controllerchange` fires when the new SW takes control.
3. If the flag is set → remove it → `window.location.reload()` once.

Result: after a deploy, users automatically get the new app on their next page
load — no manual refresh, no stale JS vs new HTML mismatch.

---

## Deployment Requirements

> ⚠️ The PWA now depends on **mod_rewrite** and `.htaccess` processing.

Since `sw.js` is generated by PHP, the hosting must:

- Enable `mod_rewrite` (Apache) or equivalent rewrite support.
- Process `.htaccess` (`AllowOverride All` or at least `FileInfo`).
- Serve `sw.js.php` with PHP execution enabled.

If `.htaccess` is disabled on the host, `/sw.js` returns 404, the SW silently
never registers, and the site still works — but PWA features (install, offline)
degrade.

**Verify the SW is being served correctly:**

```bash
curl -sI http://your-host/MEeL/sw.js | grep -i content-type
# Content-Type: application/javascript; charset=utf-8   ← correct
```

---

## Debugging & Troubleshooting

### Service worker not updating

- Check `SW_VERSION` in the served file changes when you edit an asset:
  ```bash
  curl -s http://localhost/MEeL/sw.js | grep SW_VERSION
  ```
- Output must be **byte-identical** between two requests with no changes:
  ```bash
  curl -s http://localhost/MEeL/sw.js | md5sum
  ```
- In Chrome DevTools → Application → Service Workers → "Update on reload".

### Precache install failing

If `cache.addAll()` fails, install logs `[SW] Pre-cache warning: ...`. Run the
unit test to find missing entries:

```bash
vendor/bin/phpunit --filter CssManifestTest tests/unit/CssManifestTest.php
```

### "Content-Type text/html" rejection

Make sure the request to `/sw.js` is rewritten to `sw.js.php` (check `.htaccess`)
and that PHP runs without emitting output before the `header()` calls.

### Console logs

The SW logs lifecycle events: `[SW] Install v2-...`, `[SW] Activate v2-...`,
`[SW] Delete old cache: ...`.

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
