# ⚙️ MEeL Configuration

Reference guide for all configuration files and parameters in MEeL-HUB.

---

## 📋 Table of Contents

- [Main Configuration Files](#main-configuration-files)
- [Database (`auth/settings.php`)](#database-authsettingsphp)
- [Session & Security](#session--security)
- [Media Storage Paths](#media-storage-paths)
- [Transcoder Configuration](#transcoder-configuration)
- [Uploader Configuration](#uploader-configuration)
- [System Configuration](#system-configuration)
- [Rate Limiting](#rate-limiting)

---

## Main Configuration Files

| File | Purpose | Key Variables |
|---|---|---|
| `auth/config.php` | Entry point: bootstrap, session, CSRF, headers | (init logic only) |
| `auth/settings.php` | **Pure data**: DB credentials + **centralized paths** | `$server`, `$username`, `$password`, `$db`, `MEEL_HDD_*` |
| `auth/config.example.php` | Entry point template (copy to config.php) | Same as config.php |
| `auth/settings.example.php` | Config data template (copy to settings.php) | Same as settings.php |
| `database/schema.sql` | Standalone database schema | — |
| `modules/core/Transcoder.php` | FFmpeg, yt-dlp, CPU threads | `FFMPEG_THREADS` |
| `modules/core/Uploader.php` | Upload paths, FFmpeg | `$ffmpeg_bin`, `$ffprobe_bin` |
| `modules/core/helpers.php` | **Shim** — requires `helpers/main.php` + `modules/auth/loader.php` (backward-compat) | — |
| `modules/core/helpers/*.php` | Per-domain utilities (main, storage, audio, url) | `dir_size()`, `check_disk_space()`, `get_audio_mime_type()`, `resolve_binary()`, `log_drive_operation()` |
| `modules/auth/helpers/user.php` | User & role helpers | `get_user_role()`, `get_user_usage()`, `invalidate_user_role_cache()` |
| `modules/core/System.php` | Queue management | Rate limit constants |
| `modules/core/GarbageCollector.php` | Auto-cleanup temp files + guests + chess rooms + rate limits | `STALE_SECONDS`, `GUEST_STALE_HOURS`, `ROOM_LOBBY_STALE_HOURS`, `ROOM_GAME_STALE_HOURS`, `CHESS_CLEANUP_INTERVAL` |
| `modules/auth/RateLimiter.php` | File-based API rate limiter | Per-endpoint limits (30 likes/min, 10 comments/min, etc.) |
| `modules/core/japanese.php` | Japanese text processing (MeCab + transliterator) | `getRomajiName()`, `analyzeJapaneseText()` |
| `modules/core/activity_logger.php` | Activity logging, IP banning, session kick | `get_real_ip()`, `log_activity()`, `validate_and_format_ip()` |
| `modules/core/bootstrap.php` | Bootstrap (env detection, error reporting, timezone) | `MEEL_ENV`, error log config |
| `modules/core/base_url.php` | Centralized base URL computation (`meel_base_url_path()`) | `MEEL_BASE_URL` (via `bootstrap.php`/`config.php`) |
| `modules/transcoder/FfmpegUtils.php` | **Trait** for FFmpeg utilities | `resolveBinary()`, `probeDuration()`, `generateSpriteAndVTT()` |
| `modules/autoload.php` | PSR-4-like autoloader | List of scanned directories |
| `modules/core/SwPrecache.php` | PWA precache generator (service worker) | `baseAssets()`, `moduleAssets()`, `all()`, `version()` |
| `sw.js.php` | Dynamic service worker generator (served as `/sw.js`) | `SW_VERSION`, `PRECACHE_URLS` (auto) |
| `database/migrate.php` | Database migration v1–v12 | FULLTEXT index, FK, activity_log, UNIQUE KEY, MFA, comments indexes, interactions unique keys, chess room identity |

---

## Database (`auth/settings.php`)

### Database Connection

DB credentials live in **`auth/settings.php`** (pure data). `auth/config.php` requires it and creates the connection:

```php
// File: auth/settings.php — can be copied from settings.example.php

$server   = "localhost";   // Database host
$username = "root";        // Database username
$password = "";            // Database password
$db       = "MEeL";        // Database name

// auth/config.php — entry point creates the connection:
$conn = new mysqli($server, $username, $password, $db);
$conn->set_charset('utf8mb4'); // charset koneksi dipaksa utf8mb4
```

### Error Handling

If database credentials are empty, the system displays an educational error message.

---

## Session & Security

### Session Configuration

```php
// In auth/config.php
$timeout = 43200;     // 12 hour session timeout
ini_set('session.gc_maxlifetime', $timeout);

// Secure cookie flags (auto-detect HTTPS / X-Forwarded-Proto)
$secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

session_set_cookie_params([
    'lifetime' => $timeout,
    'path'     => '/',
    'secure'   => $secure_cookie,  // hanya terkirim via HTTPS
    'httponly' => true,            // tidak bisa dibaca JavaScript
    'samesite' => 'Lax',           // proteksi CSRF level-1
]);
session_name('meel');  // Session cookie name: "meel"
session_start();
```

> `auth/auth_helpers.php` (`auth_boot_session()`) menggunakan parameter cookie yang sama.

### Trusted Proxy (`MEEL_TRUST_PROXY_HEADERS`)

**File:** `auth/settings.example.php` (dan `auth/settings.php`)

```php
// false = (default, aman) hanya pakai REMOTE_ADDR
// true  = percaya header proxy (hanya jika di belakang proxy terpercaya)
define('MEEL_TRUST_PROXY_HEADERS', false);
```

Header `HTTP_X_FORWARDED_FOR` / `HTTP_CF_CONNECTING_IP` hanya boleh dipercaya
jika request benar-benar lewat proxy/CDN yang Anda kendalikan (Cloudflare,
Nginx reverse proxy). Jika diset `true` padahal server diakses langsung,
attacker bisa memalsukan IP untuk mem-bypass IP-ban atau membanjiri activity log.

### Charset Koneksi (`utf8mb4`)

```php
// auth/config.php & auth/config.example.php
$conn->set_charset('utf8mb4');
```

Koneksi MySQL dipaksa `utf8mb4` agar cocok dengan schema — emoji, aksara
Jepang, dan teks multibyte tersimpan/terbaca dengan benar.

### CSRF Protection

```php
// Auto-generated token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Verification function (defined in modules/auth/helpers/csrf.php)
// Uses hash_equals() for timing-attack safety
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
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

> ⚠️ This function requires the PHP `intl` extension.

---

## Media Storage Paths (CENTRALIZED)

All media storage paths are **centralized** in `auth/settings.php` through `MEEL_HDD_*` constants. Change just **one line** to relocate storage.

### Main Path Configuration

```php
// File: auth/settings.php — ★ Just change MEEL_HDD_BASE, everything else follows
define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');

// Derived paths (automatically follow MEEL_HDD_BASE)
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');
```

### Storage Directory Structure

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

### File: `modules/core/Transcoder.php`

```php
// ─── HARDWARE CONSTANTS ───────────────────────────────────
private const FFMPEG_THREADS        = 8;

// HLS segment duration (seconds)
private const HLS_SEGMENT_DURATION  = 10;

// Download timeout (seconds)
private const DOWNLOAD_TIMEOUT      = 900;
```

> ⚠️ **Change:** Sprite constants `SPRITE_TILE_W/H/COLS` have been **removed**.
> Sprite dimensions (160×90, 5 columns) are now hardcoded in `modules/transcoder/FfmpegUtils.php`
> (`generateSpriteAndVTT()`: `$w = 160; $h = 90; $cols = 5;`) together with the dynamic interval.

### Sprite Interval (Dynamic)

```php
if ($duration > 3600) $interval = 300;   // > 1 hour → every 5 minutes
elseif ($duration > 1800) $interval = 180;   // > 30 min → every 3 minutes
elseif ($duration > 300)  $interval = 60;    // > 5 min → every 1 minute
else                       $interval = 10;    // ≤ 5 min → every 10 seconds
```

---

## Uploader Configuration

### File: `modules/core/Uploader.php`

### Upload Limits

```php
// Admin: 200MB per file, 60 minute duration
$max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
$max_dur  = ($this->user_role === 'admin') ? 3600 : 300; // 300 seconds = 5 minutes
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

### File: `modules/core/System.php`

```php
// Queue Processing
// Maximum 2 simultaneous processes (download + transcode)
$active = count($this->getActiveQueues());
return $active >= 2; // isServerBusy()
```

---

## Rate Limiting

### File: `modules/auth/RateLimiter.php`

File-based rate limiter for API endpoints:

| Endpoint | Limit | Window | File |
|---|:---:|:---:|---|
| Like/Dislike | 30 | 1 minute | `controllers/api/like.php` |
| Comment | 10 | 1 minute | `controllers/api/delete_comment.php`, `WatchController.php` |
| Upload | 3 | 1 hour | — |
| Transcode | 5 | 1 hour | —
| API Generic | 60 | 1 minute | — |

**Configuration:** Edit directly in `modules/auth/RateLimiter.php`:
```php
private static array $limits = [
    'like'      => ['requests' => 30, 'window' => 60],
    'comment'   => ['requests' => 10, 'window' => 60],
    'upload'    => ['requests' => 3,  'window' => 3600],
    'transcode' => ['requests' => 5,  'window' => 3600],
    'api'       => ['requests' => 60, 'window' => 60],
];
```

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
