# ⚙️ MEeL Configuration

Reference guide for all configuration files and parameters in MEeL-HUB.

---

## 📋 Table of Contents

- [Main Configuration Files](#main-configuration-files)
- [Database (`auth/config.php`)](#database-authconfigphp)
- [Session & Security](#session--security)
- [Media Storage Paths](#media-storage-paths)
- [Transcoder Configuration](#transcoder-configuration)
- [Uploader Configuration](#uploader-configuration)
- [System Configuration](#system-configuration)
- [Rate Limiting](#rate-limiting)

---

## Main Configuration Files

| File | Purpose | Key Variables |
|------|--------|----------------|
| `auth/config.php` | Database, session, CSRF, **centralized paths** | `$server`, `$username`, `$password`, `$db`, `MEEL_HDD_*` |
| `auth/config.example.php` | Config template | Same as config.php |
| `database/schema.sql` | Standalone database schema | — |
| `modules/Transcoder.php` | FFmpeg, yt-dlp, CPU threads | `FFMPEG_THREADS` |
| `modules/Uploader.php` | Upload paths, FFmpeg | `$ffmpeg_bin`, `$ffprobe_bin` |
| `modules/helpers.php` | HDD check path + various utilities | `MEEL_HDD_BASE`, `get_user_role()`, `get_audio_mime_type()`, etc |
| `modules/System.php` | Queue management | Rate limit constants |
| `modules/GarbageCollector.php` | Auto-cleanup temp files + guests + rate limits | `STALE_SECONDS`, `GUEST_STALE_HOURS` |
| `modules/RateLimiter.php` | File-based API rate limiter | Per-endpoint limits (30 likes/min, 10 comments/min, etc.) |
| `modules/autoload.php` | PSR-4-like autoloader | List of scanned directories |
| `database/migrate.php` | Database migration v1–v7 | FULLTEXT index, FK, activity_log, UNIQUE KEY |

---

## Database (`auth/config.php`)

### Database Connection

```php
// File: auth/config.php — can be copied from example

$server   = "localhost";   // Database host
$username = "root";        // Database username
$password = "";            // Database password
$db       = "MEeL";        // Database name

$conn = new mysqli($server, $username, $password, $db);
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
session_set_cookie_params($timeout, "/");
session_name('meel');  // Session cookie name: "meel"
session_start();
```

### CSRF Protection

```php
// Auto-generated token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Verification function
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

All media storage paths are **centralized** in `auth/config.php` through `MEEL_HDD_*` constants. Change just **one line** to relocate storage.

### Main Path Configuration

```php
// File: auth/config.php — ★ Just change MEEL_HDD_BASE, everything else follows
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

### File: `modules/Transcoder.php`

```php
// ─── HARDWARE CONSTANTS ───────────────────────────────────
private const FFMPEG_THREADS        = 8;

// Sprite thumbnail dimensions
private const SPRITE_TILE_W         = 160;
private const SPRITE_TILE_H         = 90;
private const SPRITE_COLS           = 5;

// HLS segment duration (seconds)
private const HLS_SEGMENT_DURATION  = 10;

// Download timeout (seconds)
private const DOWNLOAD_TIMEOUT      = 900;
```

### Sprite Interval (Dynamic)

```php
if ($duration > 3600) $interval = 300;   // > 1 hour → every 5 minutes
elseif ($duration > 1800) $interval = 180;   // > 30 min → every 3 minutes
elseif ($duration > 300)  $interval = 60;    // > 5 min → every 1 minute
else                       $interval = 10;    // ≤ 5 min → every 10 seconds
```

---

## Uploader Configuration

### File: `modules/Uploader.php`

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

### File: `modules/System.php`

```php
// Queue Processing
// Maximum 2 simultaneous processes (download + transcode)
$active = count($this->getActiveQueues());
return $active >= 2; // isServerBusy()
```

---

## Rate Limiting

### File: `modules/RateLimiter.php`

File-based rate limiter for API endpoints:

| Endpoint | Limit | Window | File |
|----------|:-----:|:------:|------|
| Like/Dislike | 30 | 1 minute | `controllers/api/like.php` |
| Comment | 10 | 1 minute | `controllers/api/delete_comment.php`, `WatchController.php` |
| Upload | 3 | 1 hour | — |
| Transcode | 5 | 1 hour | — |
| API Generic | 60 | 1 minute | — |

**Configuration:** Edit directly in `modules/RateLimiter.php`:
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
