# 🏗️ Modul & Arsitektur

Dokumentasi mendalam tentang arsitektur modul, class diagram, dan business logic layer MEeL-HUB.

---

## 📋 Daftar Isi

- [Arsitektur Aplikasi](#arsitektur-aplikasi)
- [Core Modules (`modules/`)](#core-modules-modules)
- [Media Pipeline](#media-pipeline)
- [Autentikasi Flow](#autentikasi-flow)
- [Upload & Transcoding Flow](#upload--transcoding-flow)

---

## Arsitektur Aplikasi

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
│  │  Uploader · Transcoder · System · activity_logger     │  │
│  └──────────────────────┬────────────────────────────────┘  │
│                         │                                   │
│  ┌──────────────────────▼────────────────────────────────┐  │
│  │              Database (MySQL/MariaDB)                  │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## Core Modules (`modules/core/`, `modules/media/`, `modules/exceptions/`, `modules/transcoder/`)

### 📁 Struktur Direktori

```
modules/
├── core/                   # Core business logic
│   ├── System.php          # Queue management, storage monitoring
│   ├── Uploader.php        # Upload file lokal (video + music)
│   ├── Transcoder.php      # Engine download yt-dlp & transcoding
│   ├── helpers.php         # Fungsi utilitas global
│   ├── bootstrap.php       # Environment detection & error reporting
│   ├── activity_logger.php # Activity logging, IP banning, session kick
│   ├── GarbageCollector.php# Auto-cleanup temp files & guests
│   ├── RateLimiter.php     # File-based API rate limiter
│   ├── CommentRenderer.php # Render komentar nested
│   └── japanese.php        # Pemrosesan teks Jepang (MeCab)
├── media/                  # Modul query media
│   ├── MediaLibrary.php    # Query DB, pagination, BookRepository, BookUploader
│   ├── MediaViewer.php     # View tracking, komentar, rekomendasi
│   ├── MediaInteraction.php# Like/dislike, hapus komentar
│   └── SearchEngine.php    # FULLTEXT search dengan parameter filtering
├── exceptions/             # Class exception
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

Fungsi utama query database untuk katalog media — dengan **pagination metadata dan cache getCounts()**:

### 2. `modules/media/MediaViewer.php`

**Class:** `MediaViewer` — view tracking, komentar, rekomendasi.

### 3. `modules/media/MediaInteraction.php`

**Class:** `MediaInteraction` — like/dislike dan hapus komentar.

### 4. `modules/core/Uploader.php`

**Class:** `Uploader` (menggunakan `FfmpegUtils` trait) — upload file lokal dengan:
- Validasi magic bytes (MP4/WebM/MKV)
- Active upload limit (max 3 simultan)
- Pre-flight disk space check (`require_disk_space()`)
- RAM disk staging (`/dev/shm`) untuk HLS
- Atomic DB transaction dengan rollback + file cleanup

### 5. `modules/core/Transcoder.php`

**Class:** `Transcoder` (menggunakan `FfmpegUtils` trait) — download URL & transcoding:
- RAM disk priority (`/dev/shm/meel/`) dengan fallback
- Per-platform format resolution
- Real-time progress overlay
- Cached directory size
- Thumbnail sprite + VTT

### 6. `modules/core/System.php`

**Class:** `System` — queue management, storage monitoring, rate limiting.

### 7. `modules/core/activity_logger.php`

Activity logging & IP Banning:
- `get_real_ip()` — Anti-Cloudflare masking
- `validate_and_format_ip()` — Normalize IP
- `get_access_method()` — Direct/Proxy/Cloudflare
- `get_connection_protocol()` — IPv4 vs IPv6
- `log_activity(...)` — INSERT INTO activity_log

Fitur: Guest auto-registration (ON DUPLICATE KEY UPDATE), session kick detection, stream.php throttling, device/page detection, IPv4-mapped IPv6.

### 8. `modules/core/helpers.php`

Semua fungsi dibungkus `function_exists()` guard:
- `resolve_binary(array): string` — Binary path (MEEL_*_PATH override)
- `base_url(string): string` — Dynamic base URL
- `detectProtocol(): string` — HTTPS + Cloudflare
- `time_ago($timestamp)` — Waktu relatif (ID)
- `format_bytes($bytes)` — Ukuran file readable
- `music_thumbnail_url($thumbnail)` — Resolve thumbnail
- `get_user_usage($username)` — Usage drive
- `get_user_role(mysqli, int): string` — 3-level cache
- `invalidate_user_role_cache()`
- `get_csrf_token()`, `verify_csrf_token()`
- `check_disk_space(int, string): array`, `require_disk_space(...)`
- `dir_size(string, int): float` — Cached directory size
- `get_audio_mime_type(string): string`
- `get_audio_format_label(string): string`
- `get_audio_format_description(string): string`
- `log_drive_operation(...)`

### 9. `modules/core/CommentRenderer.php`

**Fungsi:** `render_comments()`, `render_video_comments()`, `render_music_comments()` — render komentar nested dengan 2 tema (video/music).

### 10. `modules/core/GarbageCollector.php`

**Class:** `GarbageCollector` (static methods) — auto-cleanup:
- Temp files di RAM disk (`/dev/shm/meel/*`) dan project `temp/`
- Guest accounts (>2 jam) dengan throttle (1x/jam)
- Expired rate limit cache via `RateLimiter::cleanup()`
- Timeboxed execution (max 3 detik)

### 11. `modules/core/RateLimiter.php`

File-based rate limiter dengan `flock()` safety. Role-based (admin = unlimited, member = 2x).

| Endpoint | Max/Window | Keterangan |
|----------|:----------:|-----------|
| `like` | 30/menit | HTMX 429 HTML response |
| `comment` | 10/menit | Flash message redirect |
| `upload` | 3/jam | — |
| `transcode` | 5/jam | — |
| `api` | 60/menit | Generic fallback |

### 12. `modules/exceptions/`

Tiga class exception yang extends `\RuntimeException`:

| Class | Deskripsi | Method Ekstra |
|-------|-----------|---------------|
| `ProcessException` | Gagal proses eksternal (FFmpeg, yt-dlp) | `getCommand()`, `getExitCode()`, `getOutput()` |
| `DownloadException` | Gagal download URL | `getUrl()`, `getStage()` (validation/metadata/download) |
| `TranscodeException` | Gagal transcoding FFmpeg | `getInput()`, `getOutput()`, `getFfmpegLog()` |

### 13. `modules/transcoder/FfmpegUtils.php` (Trait)

Digunakan oleh `Uploader` dan `Transcoder`:
```php
trait FfmpegUtils {
    protected function resolveBinary(array $candidates): string;
    protected function probeDuration(string $file): int;
    protected function generateSpriteAndVTT(string $video, string $work_folder): void;
}
```

### 14. `modules/core/japanese.php`

Pemrosesan teks Jepang:
```php
function getRomajiName(string $text): string;           // Kana → Romaji untuk filename
function analyzeJapaneseText(string $text): array;       // MeCab analysis → [romaji, english]
function getMecabPath(): string;                         // MeCab binary resolver
```

### 15. `modules/core/bootstrap.php`

Bootstrap terpusat: auto-detect `MEEL_ENV`, konfigurasi error reporting per environment, set `MEEL_BASE_URL`, default timezone.

### 16. `modules/media/SearchEngine.php`

**Class:** `SearchEngine` — FULLTEXT search dengan parameter filtering.

### 17. `modules/autoload.php`

PSR-4-like via `spl_autoload_register()`. Auto-load class dari `modules/core/`, `modules/media/`, `drive/`, dll.

### 18. WatchController (`controllers/api/WatchController.php`)

```php
class VideoWatchController { public function getViewData(): array; }
class MusicWatchController { public function getViewData(): array; public function requireMedia(): void; }
```

### 19. Migration System (`database/migrate.php`)

| Versi | Perubahan |
|-------|-----------|
| **v1** | FULLTEXT index |
| **v2** | Performance index |
| **v3** | Sinkronisasi struktural |
| **v4** | Foreign key constraints |
| **v5** | title VARCHAR → TEXT |
| **v6** | activity_log table |
| **v7** | UNIQUE INDEX on username |

### Admin Activity Log Viewer

`admin/activity_log.php` — filter, pagination (50/halaman), stats cards, color-coded badges, manual cleanup.

---

## Media Pipeline

### Video Pipeline

```
Upload → FFmpeg Transcode → HLS (.m3u8 + .ts)
                                ↓
                          Sprite Generator
                                ↓
                         VTT Thumbnails
                                ↓
                         Move to HDD
                                ↓
                          DB Insert
```

### Audio Pipeline

```
Upload/Download → FFmpeg Encode → Opus (.ogg)
                                      ↓
                            Thumbnail Extraction
                                (ID3 → JPG)
                                      ↓
                               DB Insert
```

### Download URL Pipeline

```
URL Input → yt-dlp Metadata → Download → Type Check
                                            ↓
                              ┌──────────────┴──────────────┐
                              ↓                             ↓
                          Video                         Music
                              ↓                             ↓
                     FFmpeg HLS                    FFmpeg Opus
                     (codec copy)                   (libopus)
                              ↓                             ↓
                      Sprite + VTT                  Cover Art
                              ↓                             ↓
                         DB Insert                    DB Insert
```

---

## Autentikasi Flow

```
Request → auth.php
  ↓
Session exists? → Tidak → Redirect ke login.php
  ↓ Ya
Validasi last_session_id
  ↓
Berbeda? → Ya → Session Destroy → Redirect ke /err/revoked.php
  ↓ Tidak
Update last_activity
  ↓
Lanjutkan ke halaman yang diminta
```

### Login Flow

```
POST login
  ↓
Verify CSRF token
  ↓
Validasi username & password
  ↓
Gagal 5x? → Lock 5 menit
  ↓ Berhasil
Set session variables
  ↓
Update last_session_id
  ↓
Redirect ke index.php
```

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
