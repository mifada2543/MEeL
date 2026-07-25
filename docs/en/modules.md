# 🏗️ Modules & Architecture

In-depth documentation of module architecture, class diagrams, and business logic layer of MEeL-HUB.

---

## 📋 Table of Contents

- [Application Architecture](#application-architecture)
- [Core Modules (`modules/`)](#core-modules-modules)
- [Media Pipeline](#media-pipeline)
- [Authentication Flow](#authentication-flow)
- [Upload & Transcoding Flow](#upload--transcoding-flow)

---

## Application Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     Browser (User)                          │
├─────────────────────────────────────────────────────────────┤
│              TailwindCSS · HTMX · Plyr.js                   │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP / AJAX
┌──────────────────────▼──────────────────────────────────────┐
│              Apache Web Server (mod_rewrite)                │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────┐ ┌──────────┐ ┌──────────┐ ┌────────────────┐  │
│  │ Video   │ │ Music    │ │ Books    │ │ Cloud Drive    │  │
│  │ Module  │ │ Module   │ │ Module   │ │ Module         │  │
│  └────┬────┘ └────┬─────┘ └────┬─────┘ └───────┬────────┘  │
│       │           │            │               │           │
│  ┌────▼───────────▼────────────▼───────────────▼────────┐  │
│  │              Core Modules (modules/)                  │  │
│  │  MediaLibrary · MediaViewer · MediaInteraction        │  │
│  │  Uploader · Transcoder · System · RateLimiter         │  │
│  └──────────────────────┬────────────────────────────────┘  │
│                         │                                   │
│  ┌──────────────────────▼────────────────────────────────┐  │
│  │              Database (MySQL/MariaDB)                  │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## Core Modules (`modules/core/`, `modules/media/`, `modules/exceptions/`, `modules/transcoder/`)

### 📁 Directory Structure

```
modules/
├── core/                   # Core business logic
│   ├── System.php          # Queue management, storage monitoring
│   ├── Uploader.php        # Local file upload (video + music)
│   ├── Transcoder.php      # yt-dlp download & transcoding engine
│   ├── helpers.php         # Global utility functions
│   ├── bootstrap.php       # Environment detection & error reporting
│   ├── activity_logger.php # Activity logging, IP banning, session kick
│   ├── GarbageCollector.php# Auto-cleanup temp files & guests
│   ├── RateLimiter.php     # File-based API rate limiter
│   ├── CommentRenderer.php # Nested comment rendering
│   └── japanese.php        # Japanese text processing (MeCab)
├── media/                  # Media query modules
│   ├── MediaLibrary.php    # DB queries, pagination, BookRepository, BookUploader
│   ├── MediaViewer.php     # View tracking, comments, recommendations
│   ├── MediaInteraction.php# Like/dislike, comment deletion
│   └── SearchEngine.php    # FULLTEXT search with parameter filtering
├── exceptions/             # Exception classes
│   ├── ProcessException.php
│   ├── DownloadException.php
│   └── TranscodeException.php
├── transcoder/
│   └── FfmpegUtils.php     # Trait: probeDuration(), generateSpriteAndVTT()
└── autoload.php            # PSR-4-like autoloader
```

---

### 1. `modules/media/MediaLibrary.php`

**Class:** `MediaLibrary`, `BookRepository`, `BookUploader`

Database query functions for media catalog — with **pagination metadata**:

```php
class MediaLibrary {
    protected function paginateResult($result, int $total, int $page, int $perPage): array;
    public function getCounts(): array;
    public static function clearCountsCache(): void;
    public function getVideosWithMeta(int $page = 1, int $perPage = 15): array;
    public function getVideos(int $limit, int $offset);
    public function countVideos(): int;
    public function searchVideo(string $q, int $exclude, bool $sidebar, int $offset);
    public function getMusicListWithMeta(string $format, string $artist, int $page = 1, int $perPage = 10): array;
    public function getMusicList(string $format, string $artist, int $limit, int $offset);
    public function countMusic(string $format, string $artist): int;
    public function getArtists();
    public function searchMusic(string $q, int $exclude, bool $sidebar);
    public function getUserPlaylists(int $user_id);
}
```

**Pagination Metadata Array:**
```php
[
    'data'        => mysqli_result,
    'total'       => 100,
    'page'        => 1,
    'per_page'    => 15,
    'total_pages' => 7,
    'from'        => 1,
    'to'          => 15,
]
```

### 2. `modules/media/MediaViewer.php`

**Class:** `MediaViewer` — view tracking, comments, recommendations:

```php
class MediaViewer {
    public function recordView();
    public function getMediaData();
    public function getUserInteraction();
    public function addComment($post_data);
    public function getComments();
    public function getRecommendations($limit);
    public function getPlaylistQueue($playlist_id);
}
```

### 3. `modules/media/MediaInteraction.php`

**Class:** `MediaInteraction` — like/dislike and comment deletion.

### 4. `modules/core/Uploader.php`

**Class:** `Uploader` (uses `FfmpegUtils` trait) — local file uploads with:
- Magic bytes validation (MP4/WebM/MKV)
- Active upload limit (max 3 simultaneous)
- Pre-flight disk space check via `require_disk_space()`
- RAM disk staging (`/dev/shm`) for HLS transcoding
- Atomic DB transaction with rollback + file cleanup

```php
class Uploader {
    public function processMusic($post, $files, $base_dir);
    public function processVideo($post, $files, $upload_dir = "");
}
```

### 5. `modules/core/Transcoder.php`

**Class:** `Transcoder` (uses `FfmpegUtils` trait) — URL download & transcoding:

```php
class Transcoder {
    public function processDownload(string $url, string $type): string;
    public function encodeMusic($temp_file, $title, $artist, $album, $duration, $description);
    public function transcodeVideo(int $video_id, string $format): array;
}
```

Features:
- RAM disk priority (`/dev/shm/meel/`) with automatic fallback
- Per-platform format resolution (YouTube H.264+AAC, NicoNico, TikTok)
- Real-time progress overlay with yt-dlp streaming
- Cached directory size via `dir_size()`
- Thumbnail sprite + VTT generation

### 6. `modules/core/System.php`

**Class:** `System` — queue management, monitoring, rate limiting.

### 7. `modules/core/activity_logger.php`

Activity logging & IP Banning:

```php
function get_real_ip();              // Anti-Cloudflare masking
function validate_and_format_ip();   // Normalize IP
function get_access_method();        // Direct/Proxy/Cloudflare
function get_connection_protocol();  // IPv4 vs IPv6 detection
function log_activity(...);          // INSERT INTO activity_log (audit trail)
```

**Features:**
- Guest auto-registration with `ON DUPLICATE KEY UPDATE`
- Session kick detection (last_session_id mismatch)
- Device & page detection ("Browsing Music Library", "Watching: ..., "Listening: ...")
- Stream.php throttling (avoids DB query on every range request)
- IP ban check with admin bypass
- IPv4-mapped IPv6 support (`::ffff:192.168.x.x`)

### 8. `modules/core/helpers.php`

Global utility functions — all wrapped in `function_exists()` guard:

```php
function resolve_binary(array $candidates): string;     // Binary path discovery (with MEEL_*_PATH constant override)
function base_url(string $path = ''): string;           // Dynamic base URL
function detectProtocol(): string;                       // HTTPS detection with Cloudflare support
function time_ago($timestamp);                           // Relative time (ID locale)
function format_bytes($bytes);                           // Human-readable file size
function music_thumbnail_url($thumbnail);                // Thumbnail path resolver
function get_user_usage($username);                      // User drive usage
function get_user_role(mysqli $conn, int $user_id): string;   // Role with 3-level cache
function invalidate_user_role_cache();                   // Force refresh role cache
function get_csrf_token();                               // Get CSRF token
function verify_csrf_token($token = null);               // Verify CSRF
function check_disk_space(int $required, string $path);  // Disk capacity check
function require_disk_space(...);                        // Pre-flight disk guard
function dir_size(string $path, int $cache_ttl = 300): float;  // Cached directory size
function get_audio_mime_type(string $ext): string;       // MIME type resolver
function get_audio_format_label(string $ext): string;    // Format label
function get_audio_format_description(string $ext): string;
function log_drive_operation(...);                       // Drive audit trail
```

### 9. `modules/core/CommentRenderer.php`

**Functions:** `render_comments()`, `render_video_comments()`, `render_music_comments()`

Nested comment rendering with 2 themes (video/music).

### 10. `modules/core/GarbageCollector.php`

**Class:** `GarbageCollector` (static methods) — auto-cleanup:
- Temp files in RAM disk (`/dev/shm/meel/*`) and project `temp/`
- Guest accounts (>2 hours inactive) with throttle (1x/hour)
- Expired rate limit cache via `RateLimiter::cleanup()`
- Timeboxed execution (max 3 seconds)

### 11. `modules/core/RateLimiter.php`

File-based rate limiter with `flock()` safety. Role-based limits (admin = unlimited, member = 2x).

| Endpoint | Max/Window | Notes |
|----------|:----------:|-------|
| `like` | 30/min | HTMX 429 HTML response |
| `comment` | 10/min | Flash message redirect |
| `upload` | 3/hour | — |
| `transcode` | 5/hour | — |
| `api` | 60/min | Generic fallback |

### 12. `modules/exceptions/`

Three typed exception classes extending `\RuntimeException`:

```php
class ProcessException extends \RuntimeException {      // External process failure
    public function getCommand(): string;
    public function getExitCode(): int;
    public function getOutput(): ?string;
}

class DownloadException extends \RuntimeException {      // URL download failure
    public function getUrl(): string;
    public function getStage(): ?string;  // 'validation' | 'metadata' | 'download'
}

class TranscodeException extends \RuntimeException {    // FFmpeg transcoding failure
    public function getInput(): string;
    public function getOutput(): ?string;
    public function getFfmpegLog(): ?string;
}
```

### 13. `modules/transcoder/FfmpegUtils.php` (Trait)

Used by both `Uploader` and `Transcoder`:

```php
trait FfmpegUtils {
    protected function resolveBinary(array $candidates): string;
    protected function probeDuration(string $file): int;
    protected function generateSpriteAndVTT(string $video, string $work_folder): void;
}
```

### 14. `modules/core/japanese.php`

Japanese text processing:

```php
function getRomajiName(string $text): string;           // Kana → Romaji for filenames
function analyzeJapaneseText(string $text): array;       // MeCab analysis → [romaji, english]
function getMecabPath(): string;                         // MeCab binary resolver
```

Uses MeCab + PHP `Transliterator` (`intl` extension). Custom kana-to-romaji mapping for better accuracy.

### 15. `modules/core/bootstrap.php`

Centralized bootstrap for all entry points:
- Auto-detect `MEEL_ENV` (development/production/maintenance)
- Configures error reporting and logging per environment
- Sets `MEEL_BASE_URL` constant
- Default timezone (Asia/Jakarta)

### 16. `modules/media/SearchEngine.php`

**Class:** `SearchEngine` — FULLTEXT search with parameter filtering:

```php
class SearchEngine {
    public function searchVideo(array $params): array;
    public function searchMusic(array $params): array;
}
```

### 17. Migration System (`database/migrate.php`)

| Version | Changes |
|-------|-----------|
| **v1** | FULLTEXT index for video, music, books search |
| **v2** | Performance index (upload_date) |
| **v3** | Structural synchronization (idempotent) |
| **v4** | Foreign key constraints |
| **v5** | title VARCHAR → TEXT |
| **v6** | activity_log table |
| **v7** | UNIQUE INDEX on users.username |

### Admin Activity Log Viewer

`admin/activity_log.php` — audit trail viewer with:
- Filter by action type, username/IP, date range
- Pagination (50/page)
- Stats cards (7-day activity, unique users, total entries)
- Color-coded action badges (login=blue, upload=green, ban=red)
- Manual log cleanup (7–365 days) with CSRF

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
