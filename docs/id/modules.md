# 🏗️ Modul & Arsitektur

Dokumentasi mendalam tentang arsitektur modul, class diagram, dan business logic layer MEeL-HUB.

---

## 📋 Daftar Isi

- [Arsitektur Aplikasi](#arsitektur-aplikasi)
- [Core Modules (`modules/`)](#core-modules-modules)
- [Media Pipeline](#media-pipeline)
- [Autentikasi Flow](#autentikasi-flow)
- [Upload & Transcoding Flow](#upload--transcoding-flow)
- [Arsitektur ProgressObserver](#arsitektur-progressobserver)
- [Konvensi Keamanan Filesystem (tanpa @)](#konvensi-keamanan-filesystem-tanpa-)

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
│   ├── base_url.php        # Perhitungan base URL terpusat (meel_base_url_path)
│   ├── activity_logger.php # Activity logging, IP banning, session kick
│   ├── GarbageCollector.php# Auto-cleanup temp files & guests
│   ├── RateLimiter.php     # File-based API rate limiter
│   ├── ProgressObserver.php # Kontrak event progress (interface + adapter callable)
│   ├── BrowserProgressObserver.php # Presenter browser — event progress → overlay/JS
│   ├── CommentRenderer.php # Render komentar nested
│   ├── japanese.php        # Pemrosesan teks Jepang (MeCab)
│   └── SwPrecache.php      # Generator precache PWA (service worker)
├── media/                  # Modul query media
│   ├── MediaLibrary.php    # Query DB, pagination, BookRepository, BookUploader
│   ├── MediaViewer.php     # View tracking, komentar, rekomendasi
│   ├── MediaInteraction.php# Like/dislike, hapus komentar
│   └── SearchEngine.php    # FULLTEXT search dengan sanitizer + filtering
├── exceptions/             # Class exception
│   ├── ProcessException.php
│   ├── DownloadException.php
│   └── TranscodeException.php
├── transcoder/
│   └── FfmpegUtils.php     # Trait: probeDuration(), generateSpriteAndVTT()
└── autoload.php            # PSR-4-like autoloader

# ── Di ROOT PROJECT (bukan di modules/) ────────────────────────────────
sw.js.php                   # Generator service worker — disajikan sebagai /sw.js via rewrite .htaccess
```

---

### 1. `modules/media/MediaLibrary.php`

**Class:** `MediaLibrary`, `BookRepository`, `BookUploader`

Fungsi utama query database untuk katalog media — dengan **pagination metadata dan cache getCounts()**:

```php
public function searchVideo(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0);
public function searchMusic(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0);
public function searchBooks(string $q, string $type = 'all', int $offset = 0, int $limit = 24);
```

**Resilience search:** `searchVideo()`, `searchMusic()`, dan `searchBooks()`
membungkus query FULLTEXT-nya dengan `try/catch (\mysqli_sql_exception)` —
query boolean-mode yang malformed jatuh ke hasil kosong, bukan error 500.

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

**Class:** `Transcoder` (menggunakan `FfmpegUtils` trait) — engine download URL & transcoding.
**Business logic murni — tanpa output HTML/JS:** progress dilaporkan lewat
`ProgressObserver` (lihat [Arsitektur ProgressObserver](#arsitektur-progressobserver)),
sehingga engine yang sama berjalan bersih di browser, script CLI, cron, maupun endpoint API.

```php
class Transcoder {
    public function __construct(\mysqli $db_connection, int $session_user_id,
                                callable|ProgressObserver|null $progressListener = null);
    public function setProgressListener(callable|ProgressObserver|null $listener): void;
    public function terminateAllProcesses(): void;   // Hook shutdown yang graceful
    public function processDownload(string $url, string $type): string;
    public function encodeMusic($temp_file, $title, $artist, $album, $duration, $description);
    public function transcodeVideo(int $video_id, string $format): array;
}
```

Fitur:
- RAM disk priority (`/dev/shm/meel/`) dengan fallback otomatis
- Per-platform format resolution (YouTube H.264+AAC, NicoNico, TikTok)
- Real-time progress via event `ProgressObserver` — overlay browser
  (`partials/ui.php` + JS `meel*`) dirender oleh `BrowserProgressObserver`, bukan class ini
- **Finalisasi transaksional** — `finalizeVideo()` memindahkan file HLS ke USB HDD
  *dan* memasukkan record database di dalam satu transaksi MySQL. Kegagalan apa pun
  di-rollback dan otomatis menghapus folder HLS/thumbnail yang sudah tersalin ke
  HDD (tidak ada file yatim di storage)
- **Terminasi proses berbasis PID** — proses anak (yt-dlp, ffmpeg) di-spawn via
  `proc_open()` dan dilacak dengan PID/process-group; timeout dihentikan dengan
  `posix_kill()` (SIGTERM → grace period → SIGKILL) menggantikan pencocokan string
  `pkill -f`. Caller mendaftarkan `terminateAllProcesses()` sebagai shutdown function
- Cached directory size via `dir_size()`
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
- `base_url(string): string` — Dynamic base URL (fallback via `meel_base_url_path()`, lihat `base_url.php`)
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

**Fungsi:** `render_comments()` — render komentar nested dengan 2 tema (video/music); `comment_preview()` — preview komentar terbaru untuk header kolom komentar.

### 10. `modules/core/GarbageCollector.php`

**Class:** `GarbageCollector` (static methods) — auto-cleanup:
- Temp files di RAM disk (`/dev/shm/meel/*`) dan project `temp/`
- Guest accounts (>2 jam) dengan throttle (1x/jam)
- Room catur multiplayer terbengkalai via `cleanChessRooms()` (throttle 1x/jam):
  - Lobby basi: `black_joined = 0` dan dibuat >24 jam yang lalu
  - Game ditinggalkan di tengah: sudah dimulai, **tanpa event terminal** (`resign`/`draw_accept`/`disconnect`/`game_over`) dan tanpa aktivitas >7 hari (beserta riwayat `moves`)
  - **Game yang sudah selesai TIDAK pernah dihapus** — riwayatnya dipertahankan
- Expired rate limit cache via `RateLimiter::cleanup()`
- Timeboxed execution (max 3 detik)
- Static helper `removeFile()`/`removeDirectory()` dengan guard `is_writable()`
  proaktif — subtree milik user lain (mis. `temp/cache/` milik proses lain)
  dilewati dengan error log, bukan warning PHP

### 11. `modules/core/RateLimiter.php`

File-based rate limiter dengan `flock()` safety. Role-based (admin = unlimited, member = 2x).

| Endpoint | Max/Window | Keterangan |
|---|:---:|---|
| `like` | 30/menit | HTMX 429 HTML response |
| `comment` | 10/menit | Flash message redirect |
| `upload` | 3/jam | — |
| `transcode` | 5/jam | — |
| `api` | 60/menit | Generic fallback |

### 12. `modules/exceptions/`

Tiga class exception yang extends `\RuntimeException`:

| Class | Deskripsi | Method Ekstra |
|---|---|---|
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

    // Helper filesystem — tanpa @ suppression (lihat Konvensi Keamanan Filesystem)
    protected function ensureDir(string $dir, int $perms = 0755): bool;
    protected function removeFile(string $path): void;
    protected function removeDir(string $dir): void;
    protected function moveFile(string $src, string $dst): bool;  // Aman lintas device (RAM → HDD)
    protected function cleanupDir(string $dir): void;             // Alias removeDir() (backward compat)
}
```

Helper `moveFile()` membandingkan device ID `stat()` sebelum mencoba
`rename()`: pemindahan dari RAM disk (`/dev/shm`) ke USB HDD adalah kasus
lintas-device yang *normal*, sehingga kegagalan `EXDEV` yang diduga dilewati
sama sekali dan fallback copy+unlink berjalan tanpa warning menyesatkan.

### 14. `modules/core/japanese.php`

Pemrosesan teks Jepang:
```php
function getRomajiName(string $text): string;           // Kana → Romaji untuk filename
function analyzeJapaneseText(string $text): array;       // MeCab analysis → [romaji, english]
function getMecabPath(): string;                         // MeCab binary resolver
```

### 15. `modules/core/bootstrap.php`

Bootstrap terpusat: auto-detect `MEEL_ENV`, konfigurasi error reporting per environment, set `MEEL_BASE_URL`, default timezone.

### 15a. `modules/core/base_url.php`

Perhitungan **base URL terpusat** — satu-satunya sumber kebenaran untuk path base URL proyek (relatif terhadap `DOCUMENT_ROOT`):

```php
function meel_base_url_path(): string;   // Root proyek relatif DOCUMENT_ROOT (mis. "/MEeL")
```

Dipakai oleh `bootstrap.php` (fallback `MEEL_BASE_URL`), `auth/config.php`, `auth/config.example.php`, dan fallback `base_url()` di `helpers.php`. Dihitung dari lokasi file ini (`dirname(__DIR__, 2)`), bukan dari `dirname(SCRIPT_NAME)` — sehingga konsisten untuk semua halaman di subdirektori (admin/, video/, dll).

### 16. `modules/media/SearchEngine.php`

**Class:** `SearchEngine` — FULLTEXT search engine (video, music, books) dengan sanitizer query:

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

**Perilaku kunci:**
- `sanitizeQuery()` bersifat **public static** — dipakai semua entry point search
  sehingga sintaks FULLTEXT selalu valid (tidak ada `mysqli_sql_exception` pada input malformed).
- `parseParams()` membaca `$_GET['search']` + `$_GET['offset']`; offset ikut
  dalam **cache key**, sehingga pagination tidak pernah menyajikan halaman basi.
- `MIN_SEARCH_QUERY = 3` — query lebih pendek diabaikan (efisiensi index).

### 17. `modules/autoload.php`

PSR-4-like via `spl_autoload_register()`. Auto-load class dari `modules/core/`, `modules/media/`, `drive/`, dll.

### 18. WatchController (`controllers/api/WatchController.php`)

```php
class VideoWatchController { public function getViewData(): array; }
class MusicWatchController { public function getViewData(): array; public function requireMedia(): void; }
```

### 19. Migration System (`database/migrate.php`)

| Versi | Perubahan |
|---|---|
| **v1** | FULLTEXT index (video, music, books) |
| **v2** | Performance index (upload_date) |
| **v3** | Sinkronisasi struktural |
| **v4** | Foreign key constraints |
| **v5** | title VARCHAR → TEXT |
| **v6** | activity_log table |
| **v7** | UNIQUE INDEX (username) + schema sync |
| **v8** | Role column `varchar(20)`, hapus duplicate UNIQUE KEY, sync defaults |
| **v9** | **MFA columns:** `mfa_secret`, `mfa_backup_codes`, `mfa_enabled` |
| **v10** | Index komposit `(video_id, created_at)` & `(music_id, created_at)` pada `comments` |
| **v11** | Unique key `interactions` dipecah: `(user_id, video_id)` & `(user_id, music_id)` — NULL di unique key gabungan tidak mencegah like duplikat |

### 20. MFA System

Multi-Factor Authentication (TOTP) melindungi akun user:

| File | Fungsi |
|---|---|
| `auth/mfa_setup.php` | Setup MFA — generate secret, scan QR/barcode, verifikasi TOTP, backup codes |
| `auth/mfa_verify.php` | Verifikasi TOTP setelah login — rate limit 10 percobaan gagal, lock 5 menit |
| `admin/mfa_reset.php` | Admin reset MFA user yang kehilangan akses Authenticator |
| `controllers/system/mfa.php` | Backend controller — AJAX verify, regenerate backup codes, email backup |

**Flow:** `login.php` → cek `mfa_enabled` → redirect `mfa_verify.php` → valid TOTP → set session penuh

**Helper functions** (di `modules/core/helpers.php`):
```php
function generate_mfa_secret(): string;      // Base32 random secret
function generate_totp(string $secret): string;// TOTP kode 6 digit
function verify_totp(string $secret, string $code): bool; // Verifikasi dengan window ±1
function generate_backup_codes(): array;      // 8 backup codes (SHA256 hashed)
function verify_backup_code(string $stored, string $code): array; // Verify + consume code
```

### 21. Chess Multiplayer (`arcade/chess/`)

Multiplayer catur real-time via LAN:

| File | Fungsi |
|---|---|
| `index.php` | Board catur dengan drag-and-drop, timer, chat, sound effects |
| `controller/create_room.php` | Buat ruang baru, return room code |
| `controller/join_room.php` | Gabung ruang dengan kode |
| `controller/get_move.php` | Ambil langkah lawan + flag `opponent_online` (polling) |
| `controller/save_move.php` | Simpan langkah dengan validasi legal move |
| `controller/check_room_status.php` | Cek status ruang (waiting/playing/ended) |
| `controller/game_action.php` | Resign / tawaran seri / terima / tolak / `disconnect_win` / `game_over` (checkmate & stalemate) |
| `controller/chess_helpers.php` | Helper bersama: `chess_opponent_online()` (deteksi offline) |

**Alur multiplayer (color picker):**

```
Klik "Multiplayer LAN" → konfirmasi SweetAlert
  → overlay "Pilih Warna" (papan disembunyikan & terkunci)
      ├── Putih = createRoom() → state "Menunggu Lawan" + room code
      │        → lawan join → overlay tertutup → polling mulai
      └── Hitam = joinRoom() (prompt kode) → sync papan → overlay tertutup
```

**Deteksi disconnect lawan:**
- `get_move.php` mengembalikan `opponent_online` berdasarkan `users.last_activity` (diperbarui di setiap request oleh `activity_logger`).
- Ambang offline: `CHESS_OPPONENT_OFFLINE_SECONDS` (default 90 detik) — di atas throttle timer tab background browser.
- Aksi `disconnect_win` di `game_action.php`: klaim kemenangan, **server memverifikasi ulang** lawan benar-benar offline sebelum mencatat event terminal `disconnect`.
- Aksi `game_over` di `game_action.php`: client mencatat checkmate/stalemate (hanya bisa dideteksi di sisi client) agar GC mempertahankan game yang sudah selesai.

**Security guards (semua controller):**
- Wajib login — respons JSON `401` + `login_required: true` (JS `arcade/chess/assets/js/api.js` redirect ke login).
- Semua aksi POST wajib `csrf_token` valid (403 jika tidak).
- Token CSRF tidak pernah disimpan ke `moves.move_data`.
- `admin/catur.php?auto_cleanup=1` juga wajib `csrf_token` (dikirim JS via `window.MEEL_ADMIN_CSRF`).

### Admin Activity Log Viewer

`admin/activity_log.php` — filter, pagination (50/halaman), stats cards, color-coded badges, manual cleanup.

### 22. PWA Service Worker (`sw.js.php` + `modules/core/SwPrecache.php`)

Service worker **dibangkitkan dinamis oleh PHP** — panduan lengkap di
[`pwa.md`](pwa.md).

| Komponen | Peran |
|---|---|
| `modules/core/SwPrecache.php` | `baseAssets()` + `moduleAssets()` (semua `assets/css/*/manifest.php`) → `all()`; `version()` = hash konten → update SW otomatis |
| `sw.js.php` | Skrip SW lengkap, `Content-Type: application/javascript`, output deterministik |
| `.htaccess` | `RewriteRule ^sw\.js$ sw.js.php [L]` — URL `/sw.js` dipertahankan |

Menambah folder modul baru (`assets/css/<folder>/manifest.php`) otomatis
menambahkan CSS-nya ke precache — **tanpa perubahan SW manual**.

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
Cek MFA (mfa_enabled)
  ↓
Aktif? → Simpan mfa_temp_uid → Redirect ke mfa_verify.php
  ↓ Tidak
Set session variables (user_id, username, role)
  ↓
Update last_session_id
  ↓
Redirect ke index.php
```

### MFA Verification Flow

```
POST mfa_verify.php
  ↓
Rate limit: max 10 gagal, lock 5 menit
  ↓
Verifikasi TOTP 6 digit
  ↓
Gagal? → Increment fail count
  ↓ Valid
Set session lengkap (user_id, username, role)
  ↓
Set mfa_verified = true
  ↓
Hapus mfa_temp_uid dari session
  ↓
Redirect ke index.php
```

---

## Arsitektur ProgressObserver

`Transcoder` adalah class **business-layer murni** — tidak pernah meng-echo HTML/JS.
Progress dilaporkan sebagai event terstruktur ke `ProgressObserver`, sehingga engine
yang sama berjalan bersih di browser, script CLI, cron, maupun endpoint API tanpa
mencemari output buffer.

### File

| File | Peran |
|---|---|
| `modules/core/ProgressObserver.php` | Interface `ProgressObserver` + adapter `CallableProgressObserver` |
| `modules/core/BrowserProgressObserver.php` | Presenter browser: memetakan event ke overlay MEeL (`partials/ui.php`) + panggilan JS `meel*` |

### Penggunaan

```php
// CLI / cron / API — tanpa output sama sekali
$tc = new Transcoder($conn, $uid);

// Overlay streaming browser (upload_advanced.php, transcode.php)
require_once 'modules/core/BrowserProgressObserver.php';
$tc = new Transcoder($conn, $uid, new BrowserProgressObserver());

// Observer kustom atau callable mentah
$tc = new Transcoder($conn, $uid, function (string $stage, array $data): void {
    fwrite(STDERR, "[{$stage}] " . json_encode($data) . PHP_EOL);
});
```

### Kontrak event — `ProgressObserver::onProgress(string $stage, array $data)`

| Stage | Payload | Arti |
|---|---|---|
| `download_start` | `['url' => string]` | Unduh dimulai (titik injeksi overlay) |
| `transcode_start` | `[]` | Transcode dimulai (titik injeksi overlay) |
| `phase` | `['phase' => string]` | Pergantian fase overlay (`transcode`, `sprite`, ...) |
| `download_progress` | `['pct' => int]` + opsional `eta`, `speed`, `size`, `frag` | Progress yt-dlp |
| `transcode_progress` | `['pct' => int, 'label' => ?string]` | Progress FFmpeg |
| `sprite_progress` | `['pct' => int, 'label' => ?string]` | Progress sprite/VTT |
| `done` | `['title' => string, 'url' => string]` | Video selesai difinalisasi |
| `done_transcode` | `['title' => string, 'download_link' => string]` | Transcode audio selesai |
| `redirect` | `['url' => string]` | Navigasi browser (music → `post_encode.php`) |
| `error` | `['message' => string]` | Error fatal yang ditampilkan ke user |

**Jaminan:**
- Exception observer ditangkap dan di-log di dalam `emit()` — tidak pernah merambat
  ke pipeline media (tidak ada proses yatim atau file setengah pindah).
- Tanpa observer terpasang, `emit()` adalah no-op — nol polusi output buffer.
- Download musik mengembalikan string berawalan `REDIRECT:` sehingga *caller* yang
  memutuskan kelanjutan (tidak ada `exit` di business layer).

---

## Konvensi Keamanan Filesystem (tanpa @)

Setelah audit engine pemrosesan media, codebase **tidak pernah memakai operator
`@` (error suppression) pada operasi filesystem** (`unlink`, `rmdir`, `mkdir`,
`copy`, `rename`, `fopen`, `scandir`, `file_put_contents`, ...). `@` yang serampangan
menyembunyikan kegagalan permission/IO yang nyata — mis. USB HDD yang ter-mount
read-only, atau folder temp milik proses lain — dan membuat debugging mustahil.

Setiap akses filesystem mengikuti tiga aturan:

1. **Cek keberadaan & permission secara proaktif** — guard dengan `is_file()`,
   `is_dir()`, `is_readable()`, `is_writable()` sebelum menyentuh filesystem.
   Ingat: `unlink`/`rmdir` butuh **parent directory yang writable**, bukan hanya
   file yang ada.
2. **Cek nilai balik** — perlakukan `false`/`null` sebagai kegagalan dan log via
   `error_log()` (atau logger khusus) termasuk path yang terlibat.
3. **Pakai helper bersama, bukan `@` inline** — gunakan helper terpusat di bawah
   daripada menebar suppression ad-hoc.

### Helper filesystem bersama

| Helper | Lokasi | Tujuan |
|---|---|---|
| `ensureDir()` | trait `FfmpegUtils` | Pembuatan ala `mkdir -p` dengan logging |
| `removeFile()` | trait `FfmpegUtils` | unlink ber-guard (keberadaan + parent writable) |
| `removeDir()` | trait `FfmpegUtils` | Pembersihan dir datar (glob → removeFile → rmdir) |
| `moveFile()` | trait `FfmpegUtils` | Pindah lintas-device dengan cek device `stat()` |
| `cleanupDir()` | trait `FfmpegUtils` | Alias `removeDir()` (backward compat) |
| `GarbageCollector::removeFile()` | `GarbageCollector.php` | unlink statis ber-guard |
| `GarbageCollector::removeDirectory()` | `GarbageCollector.php` | Pembersihan rekursif ber-guard (melewati subtree non-writable, `rmdir` hanya saat kosong) |
| `meel_write_cache_file()` | `helpers/storage.php` | Tulis cache ber-guard dengan `LOCK_EX` |

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
