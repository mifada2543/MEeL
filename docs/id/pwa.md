# 📱 PWA — Progressive Web App

Dokumentasi lapisan PWA MEeL: service worker dinamis, strategi cache, web app manifest, dan perilaku offline.

---

## 📋 Daftar Isi

- [Ringkasan Arsitektur](#ringkasan-arsitektur)
- [Service Worker Dinamis (`sw.js.php`)](#service-worker-dinamis-swjphp)
- [Generator Precache (`SwPrecache`)](#generator-precache-swprecache)
- [SW_VERSION Otomatis](#sw_version-otomatis)
- [Strategi Cache](#strategi-cache)
- [Menambah Folder Modul Baru (Tanpa Perawatan)](#menambah-folder-modul-baru-tanpa-perawatan)
- [Web App Manifest](#web-app-manifest)
- [Dukungan Offline](#dukungan-offline)
- [Alur Update (Auto-Reload)](#alur-update-auto-reload)
- [Persyaratan Deployment](#persyaratan-deployment)
- [Debugging & Troubleshooting](#debugging--troubleshooting)

---

## Ringkasan Arsitektur

MEeL adalah **PWA yang dapat di-install** secara penuh:

```
Browser
  │  register('/sw.js')        ← URL tetap, isi dinamis (PHP)
  ▼
Apache .htaccess rewrite  ──►  sw.js.php (PHP generator)
                                   │
                                   ├── SwPrecache::all()      → PRECACHE_URLS
                                   └── SwPrecache::version()  → SW_VERSION
```

| Komponen | File | Fungsi |
|----------|------|--------|
| Service Worker (dinamis) | `sw.js.php` | Skrip SW lengkap, dibangkitkan per request dari `SwPrecache` |
| Generator precache | `modules/core/SwPrecache.php` | Membangun daftar precache + versi otomatis |
| Web app manifest | `assets/manifest.json` | Metadata instalasi (nama, ikon, shortcuts) |
| Ikon | `assets/MEeL-{180,192,512}.png` | Ikon asli 192×192, 512×512 (+maskable), 180×180 |
| Halaman offline | `err/offline.php` | Fallback saat halaman tidak ada di cache |
| Registrasi | `partials/head.php` | Register SW + cek update + auto-reload |

---

## Service Worker Dinamis (`sw.js.php`)

`sw.js.php` membangkitkan seluruh service worker secara dinamis. Browser tetap
meminta **`/sw.js`** (URL dipertahankan lewat rewrite `.htaccess`), sehingga
scope PWA dan kode registrasi tidak pernah berubah:

```apache
# .htaccess (root)
RewriteRule ^sw\.js$ sw.js.php [L]
```

Header respons yang dihasilkan:

```http
Content-Type: application/javascript; charset=utf-8
Cache-Control: no-cache, no-store, must-revalidate
```

> ⚠️ Generator **wajib** mengeluarkan `Content-Type: application/javascript` —
> browser menolak service worker yang disajikan sebagai `text/html`.

### Kenapa dinamis?

- **Daftar precache** diambil dari `assets/css/*/manifest.php` yang sebenarnya
  — menambah folder modul **tidak perlu mengubah apa pun** di service worker.
- **`SW_VERSION`** dihitung dari hash isi semua aset precache — perubahan
  konten apa pun otomatis menaikkan versi, memicu update SW + purge cache lama.
- Outputnya **deterministik** (tanpa timestamp): konten identik menghasilkan
  output identik, jadi browser tidak meng-install ulang SW setiap kunjungan.

---

## Generator Precache (`SwPrecache`)

**File:** `modules/core/SwPrecache.php` (terdaftar di `modules/autoload.php`)

| Method | Mengembalikan | Deskripsi |
|--------|---------------|-----------|
| `baseAssets()` | `string[]` | Aset tetap: CSS hub, tailwind, font, plyr, library JS, ikon, manifest, halaman offline |
| `moduleAssets()` | `string[]` | **Semua** modul CSS dari setiap `assets/css/*/manifest.php` (video, music, books, drive, admin, engine, up) |
| `all()` | `string[]` | Daftar precache lengkap = `baseAssets()` + `moduleAssets()` |
| `version()` | `string` | `v2-<hash10>` — hash konten semua input (aset + manifest + kode generator) |

Path root dihitung dengan `dirname(__DIR__, 2)` — **bukan** konstanta
`MEEL_ROOT` (hanya ada di lingkungan test), sehingga `sw.js.php` bekerja di
produksi tanpa bootstrap tambahan.

### Cara kerja `version()`

```php
$parts = [];
foreach (SwPrecache::all() as $rel) {
    $abs = <root> . '/' . $rel;
    $parts[] = is_file($abs) ? md5_file($abs) : 'MISSING:' . $rel; // file hilang = hash beda
}
foreach (glob(<root> . '/assets/css/*/manifest.php') as $manifest) {
    $parts[] = md5_file($manifest);
}
$parts[] = md5_file(__FILE__); // perubahan generator → versi berubah
return 'v2-' . substr(md5(implode('|', $parts)), 0, 10);
```

Perubahan apa pun pada file precache → versi baru → nama cache baru
(`meel-static-v2-<hash>`) → `activate` membersihkan cache lama.

---

## SW_VERSION Otomatis

Karena `SW_VERSION` diturunkan dari konten, skenario berikut terjadi otomatis:

| Skenario | SW_VERSION | Hasil |
|----------|------------|-------|
| Edit aset CSS/JS | berubah | SW install ulang, cache lama ter-purge, aset baru ter-precache |
| Tambah folder modul baru (`assets/css/<folder>/manifest.php` + CSS) | berubah | Folder otomatis masuk precache saat kunjungan berikutnya |
| Hapus file yang di-precache | berubah (penanda `MISSING:`) | Memaksa update — test CI menangkap file hilang secara permanen |
| Tidak ada perubahan konten | sama | Tidak ada update SW (output deterministik) |

---

## Strategi Cache

| Kategori | Strategi | Deskripsi |
|----------|----------|-----------|
| **Halaman HTML** | Network-first | Halaman segar saat online; fallback cache; lalu `err/offline.php` |
| **Aset berversi** (`?v=filemtime`) | Cache-first | Immutable — sajikan dari cache, fetch hanya saat miss |
| **Aset tanpa versi** (tanpa `?v=`) | Stale-while-revalidate | Sajikan cache seketika + refresh di background — tidak perlu hard-refresh setelah deploy |
| **API / controller** | Network-only | Tidak pernah di-cache: `controllers/`, `auth/`, `partials/engine/`, `controller/` (polling catur), `search_*`, `load_more`, `like.php`, `comment.php`, `delete_comment`, `admin_data`, `playlist_action`, `stream.php`, `read_pdf`, `download*`, `post_encode` |
| **File media** (mp4/webm/mp3/pdf/zip…) | Network-only | Streaming/download tidak pernah melewati SW (hindari cache meledak) |
| **Avatar** (`profile/upload/`) | Network-first | Foto profil langsung ter-update (URL tidak pernah berubah) |

### Batas ukuran cache halaman

Cache halaman (`meel-pages-*`) dibatasi maksimal **100 entri** via `trimCache()`
— halaman dinamis seperti `watch.php?id=X` tidak bisa membengkak tanpa batas.

### Navigation preload

Diaktifkan saat `activate` dan dipakai strategi network-first untuk navigasi —
respons navigasi mulai berjalan paralel dengan boot SW, mempercepat first paint.

---

## Menambah Folder Modul Baru (Tanpa Perawatan)

1. Buat file CSS, mis. `assets/css/arcade/base.css`, `arcade/cards.css`
2. Buat `assets/css/arcade/manifest.php`:
   ```php
   <?php
   /** assets/css/arcade/manifest.php — Daftar modul CSS folder arcade/ */
   return [
       'base.css',
       'cards.css',
   ];
   ```
3. Muat di halaman Anda via `manifest.php` (pola sama seperti `video/`, `music/`, …)

Selesai — pada kunjungan berikutnya SW otomatis memasukkan folder baru dan
menaikkan `SW_VERSION`. **Tidak perlu mengubah `sw.js.php` atau `SwPrecache.php`.**

> Jika Anda menambah `manifest.php` baru untuk folder yang CSS-nya TIDAK ingin
> di-precache, ingat desain saat ini meng-precache **semua** folder yang punya
> `manifest.php` (dipaksa oleh `CssManifestTest`).

---

## Web App Manifest

**File:** `assets/manifest.json`

| Properti | Nilai | Catatan |
|----------|-------|---------|
| `name` / `short_name` | MEeL — Media Hub Platform | |
| `icons` | 192×192 (any), 512×512 (any), 512×512 (**maskable**) | File asli — syarat prompt install Chrome |
| `start_url` | `../index.php?source=pwa` | Relatif terhadap manifest |
| `display` | `standalone` (+ `display_override`) | Jendela aplikasi full-screen |
| `theme_color` / `background_color` | `#05070c` | Tema gelap |
| `scope` / `id` | `/MEeL/` | Spesifik deployment — sesuaikan jika di-host di root |
| `shortcuts` | Video, Music, Books, Drive | Pintasan klik-kanan / long-press |

### Ikon

| File | Ukuran | Digunakan Untuk |
|------|--------|-----------------|
| `assets/MEeL.png` | 500×500 | Favicon, gambar OG |
| `assets/MEeL-192.png` | 192×192 | Manifest, shortcuts |
| `assets/MEeL-512.png` | 512×512 | Manifest (any + maskable) |
| `assets/MEeL-180.png` | 180×180 | Apple touch icon |

Dibuat dari `MEeL.png` dengan ImageMagick:
```bash
convert MEeL.png -resize 192x192 MEeL-192.png
convert MEeL.png -resize 512x512 MEeL-512.png
convert MEeL.png -resize 180x180 MEeL-180.png
```

### Meta iOS / Android standalone

`partials/head.php` mengeluarkan:

```html
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MEeL">
<link rel="apple-touch-icon" sizes="180x180" href=".../assets/MEeL-180.png">
```

---

## Dukungan Offline

- Halaman yang pernah dikunjungi saat online di-cache (network-first) → tersedia offline.
- Aset ter-precache (semua modul CSS + JS inti) bekerja offline.
- `err/offline.php` di-precache dan disajikan saat halaman yang diminta tidak punya cache.
- URL fallback offline dihitung dari `self.registration.scope` (`OFFLINE_URL`),
  sehingga bekerja baik di root maupun deployment sub-folder.

---

## Alur Update (Auto-Reload)

`partials/head.php` mendaftarkan SW dengan `updateViaCache: 'none'` dan memanggil
`reg.update()` setiap load. Saat SW baru ter-install (SW memakai
`skipWaiting()` + `clients.claim()`):

1. `updatefound` → `installed` → flag `meel_pwa_update` diset di `sessionStorage`
   (hanya saat halaman sudah dikontrol — tidak ada reload saat install pertama).
2. `controllerchange` terpicu saat SW baru mengambil alih.
3. Jika flag ter-set → hapus flag → `window.location.reload()` sekali.

Hasilnya: setelah deploy, user otomatis mendapat aplikasi baru pada kunjungan
berikutnya — tanpa refresh manual, tanpa mismatch JS lama vs HTML baru.

---

## Persyaratan Deployment

> ⚠️ PWA sekarang bergantung pada **mod_rewrite** dan pemrosesan `.htaccess`.

Karena `sw.js` dibangkitkan oleh PHP, hosting harus:

- Mengaktifkan `mod_rewrite` (Apache) atau dukungan rewrite setara.
- Memproses `.htaccess` (`AllowOverride All` atau minimal `FileInfo`).
- Menjalankan `sw.js.php` dengan PHP.

Jika `.htaccess` dinonaktifkan di host, `/sw.js` mengembalikan 404, SW tidak
terdaftar, dan situs tetap berfungsi — tetapi fitur PWA (install, offline) menurun.

**Verifikasi SW tersaji dengan benar:**

```bash
curl -sI http://host-anda/MEeL/sw.js | grep -i content-type
# Content-Type: application/javascript; charset=utf-8   ← benar
```

---

## Debugging & Troubleshooting

### Service worker tidak ter-update

- Cek `SW_VERSION` pada file yang disajikan berubah saat Anda mengedit aset:
  ```bash
  curl -s http://localhost/MEeL/sw.js | grep SW_VERSION
  ```
- Output harus **identik byte** antara dua request tanpa perubahan:
  ```bash
  curl -s http://localhost/MEeL/sw.js | md5sum
  ```
- Di Chrome DevTools → Application → Service Workers → "Update on reload".

### Instalasi precache gagal

Jika `cache.addAll()` gagal, install mencatat `[SW] Pre-cache warning: ...`.
Jalankan unit test untuk menemukan entri yang hilang:

```bash
vendor/bin/phpunit --filter CssManifestTest tests/unit/CssManifestTest.php
```

### Penolakan "Content-Type text/html"

Pastikan request `/sw.js` di-rewrite ke `sw.js.php` (cek `.htaccess`) dan PHP
tidak mengeluarkan output sebelum pemanggilan `header()`.

### Log console

SW mencatat siklus hidupnya: `[SW] Install v2-...`, `[SW] Activate v2-...`,
`[SW] Delete old cache: ...`.

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Indeks Dokumentasi</a></sub>
</div>
