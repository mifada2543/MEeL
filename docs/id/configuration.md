# ⚙️ Konfigurasi MEeL

Panduan referensi untuk semua file konfigurasi dan parameter di MEeL-HUB.

---

## 📋 Daftar Isi

- [File Konfigurasi Utama](#file-konfigurasi-utama)
- [Database (`auth/config.php`)](#database-authconfigphp)
- [Session & Security](#session--security)
- [Media Storage Paths](#media-storage-paths)
- [Transcoder Configuration](#transcoder-configuration)
- [Uploader Configuration](#uploader-configuration)
- [System Configuration](#system-configuration)
- [Rate Limiting](#rate-limiting)

---

## File Konfigurasi Utama

| File | Tujuan | Variabel Kunci |
|------|--------|----------------|
| `auth/config.php` | Database, session, CSRF, **path terpusat** | `$server`, `$username`, `$password`, `$db`, `MEEL_HDD_*` |
| `auth/config.example.php` | Template konfigurasi | Sama dengan config.php |
| `database/schema.sql` | Skema database standalone | — |
| `modules/Transcoder.php` | FFmpeg, yt-dlp, CPU threads | `FFMPEG_THREADS` |
| `modules/Uploader.php` | Upload paths, FFmpeg | `$ffmpeg_bin`, `$ffprobe_bin` |
| `modules/helpers.php` | HDD check path + berbagai utilitas | `MEEL_HDD_BASE`, `get_user_role()`, `get_audio_mime_type()`, dll |
| `modules/System.php` | Queue management | Rate limit constants |
| `modules/GarbageCollector.php` | Auto-cleanup temp files + guest + rate limit | `STALE_SECONDS`, `GUEST_STALE_HOURS` |
| `modules/RateLimiter.php` | **Baru!** File-based API rate limiter | Per-endpoint limits (30 likes/min, 10 comments/min, dll.) |
| `modules/autoload.php` | PSR-4-like autoloader | Daftar direktori yang di-scan |
| `database/migrate.php` | Database migration v1–v7 | FULLTEXT index, FK, activity_log, UNIQUE KEY |

---

## Database (`auth/config.php`)

### Koneksi Database

```php
// File: auth/config.php bisa diambil dari example

$server   = "localhost";   // Host database
$username = "root";        // Username database
$password = "";            // Password database
$db       = "MEeL";        // Nama database

$conn = new mysqli($server, $username, $password, $db);
```

### Error Handling

Jika kredensial database kosong, sistem akan menampilkan pesan error edukatif:

```
[MEeL SYSTEM ERROR]
Wah, tampaknya kamu terlalu terburu-buru!
Kamu belum mengisi konfigurasi database di file 'auth/config.php'.
```

---

## Session & Security

### Session Configuration

```php
// Di auth/config.php
$timeout = 43200;     // 12 jam session timeout
ini_set('session.gc_maxlifetime', $timeout);
session_set_cookie_params($timeout, "/");
session_name('meel');  // Session cookie name: "meel"
session_start();
```

### CSRF Protection

```php
// Auto-generated token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Verifikasi function
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || 
            !isset($_SESSION['csrf_token']) || 
            $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            return false;
        }
    }
    return true;
}
```

### Session Timeout Check

```php
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > $timeout) {  // 12 jam
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();
```

### Transliterator (Romaji Conversion)

```php
function getRomajiName($text) {
    $rule = "Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
    $transliterator = Transliterator::create($rule);
    $text = $transliterator->transliterate($text);
    $clean = preg_replace('/[^a-z0-9\-]/u', '-', $text);
    $clean = preg_replace('/-+/', '-', trim($clean, '-'));
    return $clean ?: 'untitled-media';
}
```

> ⚠️ Fungsi ini membutuhkan ekstensi PHP `intl`.

---


## Media Storage Paths (TERPUSAT)

Semua path penyimpanan media **terpusat** di `auth/config.php` melalui konstanta `MEEL_HDD_*`. Cukup ubah **satu baris** untuk memindahkan lokasi penyimpanan.

### Konfigurasi Path Utama

```php
// File: auth/config.php — ★ Cukup ubah MEEL_HDD_BASE, sisanya otomatis
define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');

// Path turunan (otomatis mengikuti MEEL_HDD_BASE)
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');
```

### Cara Mengubah

1. Tentukan path mount HDD Anda: `df -h` atau `lsblk`
2. Edit `auth/config.php`:
   ```php
   define('MEEL_HDD_BASE', '/media/[username]/MEeL/media');
   ```
3. Selesai! Semua modul (video, music, books, drive) otomatis menggunakan path baru.

### Konfigurasi X-Sendfile (Akselerasi Streaming)

> Tersedia di: `auth/config.php`

```php
define('MEEL_USE_XSENDFILE', false);
```

X-Sendfile mempercepat streaming file besar seperti FLAC (33MB+) dengan
membiarkan Apache mengirim file langsung dari disk (zero-copy), tanpa PHP
membaca file sama sekali.

**Dampak performa berdasarkan hasil tes (FLAC 33MB):**

| Metrik | Tanpa X-Sendfile (PHP chunking) | Dengan X-Sendfile |
|--------|--------------------------------|-------------------|
| Full file 33MB | 0.020 detik | ~0.010 detik (2x lebih cepat) |
| Range request 256KB | 0.011 detik | ~0.003 detik (3x lebih cepat) |
| RAM server per request | ~33MB | 0 bytes |
| PHP process blocking | Ya, sampai stream selesai | Tidak, langsung exit |

**Cara aktivasi:** Lihat panduan di [`installation.md`](installation.md) bagian "Aktifkan mod_xsendfile".

### Contoh untuk Berbagai Skenario

| Skenario | Nilai `MEEL_HDD_BASE` |
|----------|----------------------|
| HDD eksternal | `/media/username/MEeL/media` |
| Lokal SSD | `/var/www/meel-storage/media` |
| Development (fallback) | `__DIR__ . '/../storage/media'` |
| Docker volume | `/data/media` |

### ⚠️ Penting
Jika `MEEL_HDD_BASE` tidak sesuai dengan mount point, aplikasi akan redirect ke `err/maintance.php`. Buka halaman tersebut sebagai admin untuk diagnosa lengkap.

### Struktur Direktori Media

```
/media/[user]/MEeL/media/
├── video/
│   ├── upload/
│   │   ├── video/
│   │   │   ├── [folder_name]/
│   │   │   │   ├── [folder_name].m3u8      # HLS playlist
│   │   │   │   ├── [folder_name]_000.ts    # HLS segments
│   │   │   │   ├── thumb_sprite.jpg        # Sprite thumbnail
│   │   │   │   └── thumbnails.vtt          # VTT timeline
│   │   └── thumbnail/
│   │       └── [video]_thumb.jpg
├── music/
│   ├── upload/
│   │   ├── file/
│   │   │   └── [song].ogg
│   │   └── thumbnail/
│   │       └── [song].thumb.webp
└── books/
    └── upload/
        ├── manga/
        ├── pdf/
        └── thumbnail/
```

---

## Transcoder Configuration

### File: `modules/Transcoder.php`

> ⚠️ **Perubahan:** Konstanta `HDD_BASE`, `HDD_VIDEO_DIR`, `HDD_THUMB_DIR` telah **dipindahkan** ke `auth/config.php` menjadi `MEEL_HDD_*`.

```php
// ─── KONSTANTA HARDWARE ───────────────────────────────────
private const FFMPEG_THREADS        = 8;

// Sprite thumbnail dimensions
private const SPRITE_TILE_W         = 160;
private const SPRITE_TILE_H         = 90;
private const SPRITE_COLS           = 5;

// HLS segment duration (detik)
private const HLS_SEGMENT_DURATION  = 10;

// Download timeout (detik)
private const DOWNLOAD_TIMEOUT      = 900;

// PATH STORAGE — sekarang lihat auth/config.php (MEEL_HDD_*)
// private const HDD_BASE = "..."; // DIPINDAHKAN
```

### Binary Path Resolution

```php
// Transcoder.php - Auto-detect FFmpeg path
$this->ffmpeg_bin  = $this->resolveBinary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
$this->ffprobe_bin = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

// Uploader.php
$this->ffmpeg_bin  = $this->resolveBinary(['/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg', 'ffmpeg']);
$this->ffprobe_bin = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);
```

### yt-dlp Configuration

```php
$this->base_cmd = "export PATH=/usr/local/bin:/usr/bin:/bin; "
    . " /usr/local/bin/yt-dlp --js-runtime node:/usr/bin/node"
    . " --no-warnings --restrict-filenames"
    . " --user-agent " . escapeshellarg($this->user_agent)
    . " --referer " . escapeshellarg("https://www.youtube.com/")
    . " --cookies " . escapeshellarg($this->cookies_path) . " ";
```

### Video Format Resolution

```php
// YouTube: prefer H.264 + AAC/M4A untuk stream-copy
return "bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080][vcodec^=avc1]";

// NicoNico: format standar
return "bestvideo[height<=1080]+bestaudio/best";

// TikTok/others
return "bestvideo+bestaudio/best";

// Fallback
return "bestvideo[height<=1080]+bestaudio/best";
```

### Sprite Interval (Dinamis)

```php
if ($duration > 3600) $interval = 300;   // > 1 jam → tiap 5 menit
elseif ($duration > 1800) $interval = 180;   // > 30 menit → tiap 3 menit
elseif ($duration > 300)  $interval = 60;    // > 5 menit → tiap 1 menit
else                       $interval = 10;    // ≤ 5 menit → tiap 10 detik
```

---

## Uploader Configuration

### File: `modules/Uploader.php`

> ⚠️ **Perubahan:** `$base_dir` sekarang mengambil path dari `MEEL_HDD_VIDEO_UPLOAD` (didefinisikan di `auth/config.php`).

```php
$this->base_dir = defined('MEEL_HDD_VIDEO_UPLOAD')
    ? MEEL_HDD_VIDEO_UPLOAD
    : "/path/to/your/media/video/upload/"; // fallback
```

### Upload Limits

```php
// Admin: 200MB per file, 60 menit durasi
$max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
$max_dur  = ($this->user_role === 'admin') ? 3600 : 300; // 300 detik = 5 menit
```

### Allowed File Formats

```php
// Video
$allowed_ext = ['mp4', 'webm', 'mkv'];

// Music
$allowed_ext = ['mp3', 'opus', 'ogg', 'm4a', 'wav', 'flac'];
```

---

## System Configuration

### File: `modules/System.php`

```php
// Queue Processing
// Maksimal 2 proses simultan (download + transcode)
$active = count($this->getActiveQueues());
return $active >= 2; // isServerBusy()
```

### Upload Rate Limiting (System.php)

```php
// Default: 2 upload per jam (non-admin)
$max_upload = 2;

// Drive: 10 upload per jam
if ($type === 'drive_files') {
    $max_upload = 10;
}

// Admin: unlimited
if ($user_role === 'admin') return ['allowed' => true];
```

### API Rate Limiting (RateLimiter.php)

`modules/RateLimiter.php` — file-based rate limiter untuk endpoint API:

| Endpoint | Limit | Window | File |
|----------|:-----:|:------:|------|
| Like/Dislike | 30 | 1 menit | `controllers/api/like.php` |
| Comment | 10 | 1 menit | `controllers/api/delete_comment.php`, `WatchController.php` |
| Upload | 3 | 1 jam | — |
| Transcode | 5 | 1 jam | — |
| API Generic | 60 | 1 menit | — |

**Konfigurasi:** Edit langsung di `modules/RateLimiter.php`:
```php
private static array $limits = [
    'like'    => ['requests' => 30, 'window' => 60],
    'comment' => ['requests' => 10, 'window' => 60],
    'upload'  => ['requests' => 3,  'window' => 3600],
    'transcode' => ['requests' => 5, 'window' => 3600],
    'api'     => ['requests' => 60, 'window' => 60],
];
```

**Storage:** File JSON di `temp/ratelimit/` dengan `flock()` untuk race condition safety. Auto-cleanup via `GarbageCollector::run()`.

---

## Cookies & yt-dlp Authentication

File `cookies.txt` di root proyek digunakan untuk autentikasi yt-dlp:

```php
// Path: /opt/lampp/htdocs/MEeL/cookies.txt
$this->cookies_path = $this->base_path . "/cookies.txt";
```

### Cara Mendapatkan Cookies.txt

1. Install ekstensi browser "Get cookies.txt LOCALLY"
2. Login ke YouTube (atau platform lain) di browser
3. Klik ekstensi → Export cookies
4. Simpan file sebagai `cookies.txt` di root proyek

---

## Environment Variables

```php
// Di upload_advanced.php (override environment)
putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
putenv("PATH=/usr/local/bin:/usr/bin:/bin");
```

```php
// Di Transcoder.php
private const ENV_PREFIX = "export LD_LIBRARY_PATH=''; "
    . "export PATH=/usr/local/bin:/usr/bin:/bin; "
    . "export LC_ALL=en_US.UTF-8; ";
```

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
