# 🏗️ Modules & Architecture

In-depth documentation of module architecture, class diagrams, and business logic layer of MEeL-HUB.

---

## 📋 Table of Contents

- [Application Architecture](#application-architecture)
- [Core Modules (`modules/`)](#core-modules-modules)
- [Media Pipeline](#media-pipeline)
- [Authentication Flow](#authentication-flow)
- [Upload & Transcoding Flow](#upload--transcoding-flow)
- [ProgressObserver Architecture](#progressobserver-architecture)
- [Filesystem Safety Convention (no @ suppression)](#filesystem-safety-convention-no--suppression)

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
│   ├── base_url.php        # Centralized base URL computation (meel_base_url_path)
│   ├── activity_logger.php # Activity logging, IP banning, session kick
│   ├── GarbageCollector.php# Auto-cleanup temp files & guests
│   ├── RateLimiter.php     # File-based API rate limiter
│   ├── ProgressObserver.php# Progress event contract (interface + callable adapter)
│   ├── BrowserProgressObserver.php # Browser presenter — progress events → overlay/JS
│   ├── CommentRenderer.php # Nested comment rendering
│   ├── japanese.php        # Japanese text processing (MeCab)
│   └── SwPrecache.php      # PWA precache generator (service worker)
├── media/                  # Media query modules
│   ├── MediaLibrary.php    # DB queries, pagination, BookRepository, BookUploader
│   ├── MediaViewer.php     # View tracking, comments, recommendations
│   ├── MediaInteraction.php# Like/dislike, comment deletion
│   └── SearchEngine.php    # FULLTEXT search with sanitizer + parameter filtering
├── exceptions/             # Exception classes
│   ├── ProcessException.php
│   ├── DownloadException.php
│   └── TranscodeException.php
├── transcoder/
│   └── FfmpegUtils.php     # Trait: probeDuration(), generateSpriteAndVTT()
└── autoload.php            # PSR-4-like autoloader

# ── Di ROOT PROJECT (bukan di modules/) ────────────────────────────────
sw.js.php                   # Service worker generator — served as /sw.js via .htaccess rewrite
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
    public function searchVideo(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0);
    public function getMusicListWithMeta(string $format, string $artist, int $page = 1, int $perPage = 10): array;
    public function getMusicList(string $format, string $artist, int $limit, int $offset);
    public function countMusic(string $format, string $artist): int;
    public function getArtists();
    public function searchMusic(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0);
    public function searchBooks(string $q, string $type = 'all', int $offset = 0, int $limit = 24);
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

**Search resilience:** `searchVideo()`, `searchMusic()`, and `searchBooks()`
wrap their FULLTEXT queries in `try/catch (\mysqli_sql_exception)` — a malformed
boolean-mode query falls back to an empty result instead of a 500 error.

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

**Class:** `Transcoder` (uses `FfmpegUtils` trait) — URL download & transcoding engine.
**Pure business logic — no HTML/JS output:** progress is reported through a
`ProgressObserver` (see [ProgressObserver Architecture](#progressobserver-architecture)),
so the same engine runs cleanly in browsers, CLI scripts, cron jobs, and API endpoints.

```php
class Transcoder {
    public function __construct(\mysqli $db_connection, int $session_user_id,
                                callable|ProgressObserver|null $progressListener = null);
    public function setProgressListener(callable|ProgressObserver|null $listener): void;
    public function terminateAllProcesses(): void;   // Graceful shutdown hook
    public function processDownload(string $url, string $type): string;
    public function encodeMusic($temp_file, $title, $artist, $album, $duration, $description);
    public function transcodeVideo(int $video_id, string $format): array;
}
```

Features:
- RAM disk priority (`/dev/shm/meel/`) with automatic fallback
- Per-platform format resolution (YouTube H.264+AAC, NicoNico, TikTok)
- Real-time progress via `ProgressObserver` events — the browser overlay
  (`partials/ui.php` + `meel*` JS) is rendered by `BrowserProgressObserver`, not by this class
- **Transactional finalization** — `finalizeVideo()` moves HLS files to the USB HDD
  *and* inserts the database record inside a single MySQL transaction. Any failure
  rolls back and automatically deletes the HLS folder/thumbnail already copied to
  the HDD (no orphaned files on storage)
- **PID-based process termination** — child processes (yt-dlp, ffmpeg) are spawned
  via `proc_open()` and tracked by PID/process-group; timeout aborts use
  `posix_kill()` (SIGTERM → grace period → SIGKILL) instead of `pkill -f` string
  matching. Callers register `terminateAllProcesses()` as a shutdown function
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
function base_url(string $path = ''): string;           // Dynamic base URL (fallback via meel_base_url_path(), see base_url.php)
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

**Functions:** `render_comments()` — nested comment rendering with 2 themes (video/music); `comment_preview()` — latest comment preview for the comment header.

### 10. `modules/core/GarbageCollector.php`

**Class:** `GarbageCollector` (static methods) — auto-cleanup:
- Temp files in RAM disk (`/dev/shm/meel/*`) and project `temp/`
- Guest accounts (>2 hours inactive) with throttle (1x/hour)
- Abandoned multiplayer chess rooms via `cleanChessRooms()` (throttle 1x/hour):
  - Stale lobby: `black_joined = 0` and created >24 hours ago
  - Abandoned mid-game: started, **no terminal event** (`resign`/`draw_accept`/`disconnect`/`game_over`) and no activity for >7 days (moves history removed too)
  - **Finished games are never deleted** — their history is preserved
- Expired rate limit cache via `RateLimiter::cleanup()`
- Timeboxed execution (max 3 seconds)
- Static `removeFile()`/`removeDirectory()` helpers with proactive `is_writable()`
  guards — subtrees owned by other users (e.g. `temp/cache/` owned by another
  process) are skipped with an error log instead of a PHP warning

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

    // Filesystem helpers — no @ suppression (see Filesystem Safety Convention)
    protected function ensureDir(string $dir, int $perms = 0755): bool;
    protected function removeFile(string $path): void;
    protected function removeDir(string $dir): void;
    protected function moveFile(string $src, string $dst): bool;  // Cross-device safe (RAM → HDD)
    protected function cleanupDir(string $dir): void;             // Alias of removeDir() (backward compat)
}
```

The `moveFile()` helper compares `stat()` device IDs before attempting
`rename()`: moving from the RAM disk (`/dev/shm`) to the USB HDD is the *normal*
cross-device case, so the expected `EXDEV` failure is skipped entirely and the
copy+unlink fallback runs without emitting a misleading warning.

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

### 15a. `modules/core/base_url.php`

Centralized **base URL computation** — the single source of truth for the project's base URL path (relative to `DOCUMENT_ROOT`):

```php
function meel_base_url_path(): string;   // Project root relative to DOCUMENT_ROOT (e.g. "/MEeL")
```

Used by `bootstrap.php` (`MEEL_BASE_URL` fallback), `auth/config.php`, `auth/config.example.php`, and the `base_url()` fallback in `helpers.php`. Computed from this file's location (`dirname(__DIR__, 2)`) rather than `dirname(SCRIPT_NAME)` — consistent for all pages in subdirectories (admin/, video/, etc.).

### 16. `modules/media/SearchEngine.php`

**Class:** `SearchEngine` — FULLTEXT search engine (video, music, books) with query sanitizer:

```php
class SearchEngine {
    public const VIDEO_LIMIT    = 20;
    public const MUSIC_LIMIT    = 20;
    public const MIN_SEARCH_QUERY = 3;   // Query pendek (< 3) tidak diproses
    public const MAX_SEARCH_QUERY = 255; // Batas panjang query

    public function __construct(mysqli $db_connection);
    public function parseParams(): array;                    // q (sanitized), offset, dll.
    public static function sanitizeQuery(string $q): string; // FULLTEXT-safe: buang operator murni, seimbangkan kutip, buang asterisk di awal token
    public function searchVideo(array $params): array;
    public function searchMusic(array $params): array;
    public static function clearCache(): void;
}
```

**Key behaviors:**
- `sanitizeQuery()` is **public static** — shared by every search entry point so
  the FULLTEXT syntax is always valid (no `mysqli_sql_exception` on malformed input).
- `parseParams()` reads `$_GET['search']` + `$_GET['offset']`; offset is included
  in the **cache key**, so pagination never serves a stale page.
- `MIN_SEARCH_QUERY = 3` — shorter queries are ignored (index efficiency).

### 17. `modules/autoload.php`

PSR-4-like autoloader via `spl_autoload_register()`. Auto-loads classes from `modules/core/`, `modules/media/`, `drive/`, etc.

### 18. WatchController (`controllers/api/WatchController.php`)

```php
class VideoWatchController { public function getViewData(): array; }
class MusicWatchController { public function getViewData(): array; public function requireMedia(): void; }
```

### 19. Migration System (`database/migrate.php`)

| Version | Changes |
|-------|-----------|
| **v1** | FULLTEXT index for video, music, books search |
| **v2** | Performance index (upload_date) |
| **v3** | Structural synchronization (idempotent) |
| **v4** | Foreign key constraints |
| **v5** | title VARCHAR → TEXT |
| **v6** | activity_log table |
| **v7** | UNIQUE INDEX on users.username + schema sync |
| **v8** | Role column `varchar(20)`, drop duplicate UNIQUE KEY, sync defaults |
| **v9** | **MFA columns:** `mfa_secret`, `mfa_backup_codes`, `mfa_enabled` |
| **v10** | Composite index `(video_id, created_at)` & `(music_id, created_at)` on `comments` |
| **v11** | `interactions` unique keys split: `(user_id, video_id)` & `(user_id, music_id)` — NULL in a combined unique key did not prevent duplicate likes |

### 20. MFA System

Multi-Factor Authentication (TOTP) protects user accounts:

| File | Function |
|------|--------|
| `auth/mfa_setup.php` | MFA Setup — generate secret, scan QR/barcode, verify TOTP, backup codes |
| `auth/mfa_verify.php` | TOTP verification after login — rate limit 10 failed attempts, lock 5 minutes |
| `admin/mfa_reset.php` | Admin reset MFA for users who lost Authenticator access |
| `controllers/system/mfa.php` | Backend controller — AJAX verify, regenerate backup codes, email backup |

**Flow:** `login.php` → check `mfa_enabled` → redirect `mfa_verify.php` → valid TOTP → set full session

**Helper functions** (in `modules/core/helpers.php`):
```php
function generate_mfa_secret(): string;      // Base32 random secret
function generate_totp(string $secret): string;// TOTP 6-digit code
function verify_totp(string $secret, string $code): bool; // Verify with window ±1
function generate_backup_codes(): array;      // 8 backup codes (SHA256 hashed)
function verify_backup_code(string $stored, string $code): array; // Verify + consume code
```

### 21. Chess Multiplayer (`arcade/chess/`)

Real-time LAN multiplayer chess:

| File | Function |
|------|--------|
| `index.php` | Chess board with drag-and-drop, timer, chat, sound effects |
| `controller/create_room.php` | Create new room, return room code |
| `controller/join_room.php` | Join room with code |
| `controller/get_move.php` | Fetch opponent's move + `opponent_online` flag (polling) |
| `controller/save_move.php` | Save move with legal move validation |
| `controller/check_room_status.php` | Check room status (waiting/playing/ended) |
| `controller/game_action.php` | Resign / draw offer / accept / decline / `disconnect_win` / `game_over` (checkmate & stalemate) |
| `controller/chess_helpers.php` | Shared helper: `chess_opponent_online()` (offline detection) |

**Multiplayer flow (color picker):**

```
Klik "Multiplayer LAN" → konfirmasi SweetAlert
  → overlay "Pilih Warna" (papan disembunyikan & terkunci)
      ├── Putih = createRoom() → state "Menunggu Lawan" + room code
      │        → lawan join → overlay tertutup → polling mulai
      └── Hitam = joinRoom() (prompt kode) → sync papan → overlay tertutup
```

**Disconnect detection:**
- `get_move.php` returns `opponent_online` based on `users.last_activity` (updated on every request by `activity_logger`).
- Offline threshold: `CHESS_OPPONENT_OFFLINE_SECONDS` (default 90s) — above background-tab timer throttling.
- `game_action.php` action `disconnect_win`: claim win, **server re-verifies** the opponent is actually offline before recording a `disconnect` terminal event.
- `game_action.php` action `game_over`: client records checkmate/stalemate (only detectable client-side) so the GC preserves finished games.

**Security guards (all controllers):**
- Wajib login — respons JSON `401` + `login_required: true` (JS `arcade/chess/assets/js/api.js` redirects to login).
- Semua aksi POST wajib `csrf_token` valid (403 jika tidak).
- Token CSRF tidak pernah disimpan ke `moves.move_data`.
- `admin/catur.php?auto_cleanup=1` juga wajib `csrf_token` (dikirim JS via `window.MEEL_ADMIN_CSRF`).

### Admin Activity Log Viewer

`admin/activity_log.php` — audit trail viewer with:
- Filter by action type, username/IP, date range
- Pagination (50/page)
- Stats cards (7-day activity, unique users, total entries)
- Color-coded action badges (login=blue, upload=green, ban=red)
- Manual log cleanup (7–365 days) with CSRF

### 22. PWA Service Worker (`sw.js.php` + `modules/core/SwPrecache.php`)

The service worker is **generated dynamically by PHP** — see the full guide in
[`pwa.md`](pwa.md).

| Component | Role |
|-----------|------|
| `modules/core/SwPrecache.php` | `baseAssets()` + `moduleAssets()` (all `assets/css/*/manifest.php`) → `all()`; `version()` = content hash → auto SW update |
| `sw.js.php` | Full SW script, `Content-Type: application/javascript`, deterministic output |
| `.htaccess` | `RewriteRule ^sw\.js$ sw.js.php [L]` — URL `/sw.js` preserved |

Adding a new module folder (`assets/css/<folder>/manifest.php`) automatically
adds its CSS to the precache — **no manual SW changes needed**.

---

## ProgressObserver Architecture

`Transcoder` is a **pure business-layer** class — it never echoes HTML/JS. Progress
is reported as structured events to a `ProgressObserver`, letting the same engine
run cleanly in browsers, CLI scripts, cron jobs, or API endpoints without polluting
output buffers.

### Files

| File | Role |
|------|------|
| `modules/core/ProgressObserver.php` | `ProgressObserver` interface + `CallableProgressObserver` adapter |
| `modules/core/BrowserProgressObserver.php` | Browser presenter: maps events to the MEeL overlay (`partials/ui.php`) + `meel*` JS calls |

### Usage

```php
// CLI / cron / API — no output at all
$tc = new Transcoder($conn, $uid);

// Browser streaming overlay (upload_advanced.php, transcode.php)
require_once 'modules/core/BrowserProgressObserver.php';
$tc = new Transcoder($conn, $uid, new BrowserProgressObserver());

// Custom observer or raw callable
$tc = new Transcoder($conn, $uid, function (string $stage, array $data): void {
    fwrite(STDERR, "[{$stage}] " . json_encode($data) . PHP_EOL);
});
```

### Event contract — `ProgressObserver::onProgress(string $stage, array $data)`

| Stage | Payload | Meaning |
|-------|---------|---------|
| `download_start` | `['url' => string]` | Download begins (overlay injection point) |
| `transcode_start` | `[]` | Transcode begins (overlay injection point) |
| `phase` | `['phase' => string]` | Overlay phase switch (`transcode`, `sprite`, ...) |
| `download_progress` | `['pct' => int]` + optional `eta`, `speed`, `size`, `frag` | yt-dlp progress |
| `transcode_progress` | `['pct' => int, 'label' => ?string]` | FFmpeg progress |
| `sprite_progress` | `['pct' => int, 'label' => ?string]` | Sprite/VTT progress |
| `done` | `['title' => string, 'url' => string]` | Video finalized |
| `done_transcode` | `['title' => string, 'download_link' => string]` | Audio transcode complete |
| `redirect` | `['url' => string]` | Browser navigation (music → `post_encode.php`) |
| `error` | `['message' => string]` | Fatal error shown to the user |

**Guarantees:**
- An observer exception is caught and logged inside `emit()` — it never propagates
  into the media pipeline (no orphaned processes or half-moved files).
- With no observer attached, `emit()` is a no-op — zero output-buffer pollution.
- Music downloads return a `REDIRECT:`-prefixed string so the *caller* decides how
  to continue (no `exit` call in the business layer).

---

## Filesystem Safety Convention (no @ suppression)

Following the media-processing engine audit, the codebase **never uses the `@`
error-suppression operator on filesystem operations** (`unlink`, `rmdir`, `mkdir`,
`copy`, `rename`, `fopen`, `scandir`, `file_put_contents`, ...). A blanket `@`
hides real permission/IO failures — e.g. a USB HDD mounted read-only, or a temp
folder owned by another process — and makes debugging impossible.

Every filesystem access follows three rules:

1. **Proactive existence & permission checks** — guard with `is_file()`, `is_dir()`,
   `is_readable()`, `is_writable()` before touching the filesystem. Remember that
   `unlink`/`rmdir` require a writable **parent** directory, not just an existing file.
2. **Return-value checks** — treat `false`/`null` returns as failures and log them
   via `error_log()` (or a dedicated logger) including the path involved.
3. **Shared helpers instead of inline `@`** — reuse the centralized helpers below
   rather than scattering ad-hoc suppression.

### Shared filesystem helpers

| Helper | Location | Purpose |
|--------|----------|---------|
| `ensureDir()` | `FfmpegUtils` trait | `mkdir -p`-style creation with logging |
| `removeFile()` | `FfmpegUtils` trait | Guarded unlink (existence + writable parent) |
| `removeDir()` | `FfmpegUtils` trait | Flat-dir cleanup (glob → removeFile → rmdir) |
| `moveFile()` | `FfmpegUtils` trait | Cross-device move with `stat()` device check |
| `cleanupDir()` | `FfmpegUtils` trait | Alias of `removeDir()` (backward compat) |
| `GarbageCollector::removeFile()` | `GarbageCollector.php` | Static guarded unlink |
| `GarbageCollector::removeDirectory()` | `GarbageCollector.php` | Recursive guarded cleanup (skips non-writable subtrees, `rmdir` only when empty) |
| `meel_write_cache_file()` | `helpers/storage.php` | Guarded cache write with `LOCK_EX` |

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
