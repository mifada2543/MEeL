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

## Core Modules (`modules/`)

### 1. `MediaLibrary.php`

**Class:** `MediaLibrary`, `BookRepository`, `BookUploader`

Database query functions for media catalog — with **pagination metadata**:

```php
class MediaLibrary {
    // Pagination helper — wrap result + count into metadata
    protected function paginateResult($result, int $total, int $page, int $perPage): array;

    // Get total counts per media type (with file-based cache)
    public function getCounts(): array;
    public static function clearCountsCache(): void;
    
    // Video — with pagination metadata
    public function getVideosWithMeta(int $page = 1, int $perPage = 15): array;
    public function getVideos(int $limit, int $offset);
    public function countVideos(): int;
    public function searchVideo(string $q, int $exclude, bool $sidebar, int $offset);
    
    // Music — with pagination metadata
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
    'data'        => mysqli_result,  // Result set
    'total'       => 100,            // Total records
    'page'        => 1,              // Current page (1-based)
    'per_page'    => 15,             // Items per page
    'total_pages' => 7,              // Total pages
    'from'        => 1,              // First item on this page
    'to'          => 15,             // Last item on this page
]
```

### 2. `MediaViewer.php`

**Class:** `MediaViewer`

Handles view tracking, comments, and recommendations:

```php
class MediaViewer {
    public function recordView();           // Track unique view
    public function getMediaData();         // Get media + uploader info
    public function getUserInteraction();   // Like/dislike status
    public function addComment($post_data); // Add nested comment
    public function getComments();          // Get grouped comments
    public function getRecommendations($limit);  // Random recommendations
    public function getPlaylistQueue($playlist_id); // Music queue
}
```

### 3. `MediaInteraction.php`

**Class:** `MediaInteraction`

Like/dislike and comment deletion:

```php
class MediaInteraction {
    public function toggleLike($media_id, $media_type, $like_type);
    public function getUserInteractionStatus($media_id, $media_type);
    public function getLikesCount($table, $media_id);
    public function deleteComment($comment_id);
}
```

### 4. `Uploader.php`

**Class:** `Uploader`

Handles local file uploads:

```php
class Uploader {
    public function processMusic($post, $files, $base_dir);
    public function processVideo($post, $files, $upload_dir = "");
}
```

### 5. `Transcoder.php`

**Class:** `Transcoder`

Main engine for URL downloads (yt-dlp) and transcoding:

```php
class Transcoder {
    public function processDownload(string $url, string $type): string;
    public function encodeMusic($temp_file, $title, $artist, $album, $duration, $description);
    public function transcodeVideo(int $video_id, string $format): array;
}
```

### 6. `System.php`

**Class:** `System`

Queue management, storage monitoring, rate limiting:

```php
class System {
    public function getActiveQueues(): array;
    public function getTodayUploadStats(): array;
    public function getStorageUsage(): array;
    public function isServerBusy(): bool;
    public function checkRateLimit(int $user_id, string $type, string $user_role): array;
    public function cleanStuckQueues(): int;
    public function forceStopQueue(int $id, string $task_type): bool;
}
```

### 7. `activity_logger.php`

Activity logging & IP Banning system:

```php
function get_real_ip();              // Anti-Cloudflare masking
function validate_and_format_ip();   // Normalize IP
function get_access_method();        // Direct/Proxy/Cloudflare
```

### 8. `helpers.php`

Global utility functions:

```php
function time_ago($timestamp);
function format_bytes($bytes);
function music_thumbnail_url($thumbnail);
function get_user_usage($username);
function get_user_role(mysqli $conn, int $user_id): string;
function get_csrf_token();
function verify_csrf_token($token);
function check_disk_space(int $required_bytes, string $path): array;
function require_disk_space(int $required_bytes, string $path, string $label): void;
function log_drive_operation(...);
function get_audio_mime_type(string $ext): string;
function get_audio_format_label(string $ext): string;
function get_audio_format_description(string $ext): string;
```

### 9. `CommentRenderer.php`

**Functions:** `render_comments()`, `render_video_comments()`, `render_music_comments()`

### 10. `GarbageCollector.php`

**Class:** `GarbageCollector` (static methods)

```php
class GarbageCollector {
    public static function cleanGuests(mysqli $conn): int;
    public static function run(): void;  // Also calls RateLimiter::cleanup()
}
```

### 11. `RateLimiter.php`

**Class:** `RateLimiter` (static methods)

File-based rate limiter for API endpoint protection:

```php
class RateLimiter {
    public static function check(string $key, string $endpoint = 'api'): array;
    public static function getRemaining(string $key, string $endpoint): int;
    public static function cleanup(): int;
    public static function getStats(): array;
    public static function getLimitsConfig(): array;
}
```

**Endpoint Limits:**

| Endpoint | Max Requests | Window | Implemented In |
|----------|:-----------:|:------:|-------------------|
| `like` | 30 | 1 minute | `controllers/api/like.php` — returns 429 with HTMX HTML snippet |
| `comment` | 10 | 1 minute | `controllers/api/delete_comment.php`, `WatchController.php` |
| `upload` | 3 | 1 hour | — |
| `transcode` | 5 | 1 hour | — |
| `api` (generic) | 60 | 1 minute | — |

### 12. Exception Classes (`modules/exceptions/`)

```php
class ProcessException extends \RuntimeException { }
class DownloadException extends \RuntimeException { }
class TranscodeException extends \RuntimeException { }
```

### 13. Migration System (`database/migrate.php`)

| Version | Changes |
|-------|-----------|
| **v1** | FULLTEXT index for video, music, books search |
| **v2** | Performance index (upload_date) |
| **v3** | Structural synchronization |
| **v4** | Foreign key constraints (upload_queue, drive_files → users) |
| **v5** | title VARCHAR → TEXT |
| **v6** | activity_log table for audit trail |
| **v7** | UNIQUE INDEX on users.username |

### Admin Activity Log Viewer

Dedicated admin page at `admin/activity_log.php` for viewing and managing audit trails:
- Filter by action type, username/IP, date range
- Pagination (50 per page)
- Stats cards (7-day activity, unique users, total entries)
- Color-coded action badges
- Manual log cleanup (7–365 days) with CSRF protection

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
