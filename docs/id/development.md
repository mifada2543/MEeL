# 👨‍💻 Panduan Development & Kontribusi

Panduan untuk pengembang yang ingin berkontribusi atau memahami standar koding di MEeL-HUB.

---

## 📋 Daftar Isi

- [Lingkungan Development](#lingkungan-development)
- [Standar Koding](#standar-koding)
- [Struktur Database](#struktur-database)
- [Coding Conventions](#coding-conventions)
- [Testing](#testing)
- [Pull Request Guide](#pull-request-guide)
- [Troubleshooting Development](#troubleshooting-development)

---

## Lingkungan Development

### Setup Development

1. **Install dependencies:**
```bash
# Clone repo
git clone https://github.com/mifada2543/MEeL.git
cd MEeL

# Copy config
cp auth/settings.example.php auth/settings.php
cp auth/config.example.php auth/config.php

# Setup database (lihat installation.md)
```

2. **Aktifkan debug mode:**
```php
// Di awal file PHP yang sedang dikerjakan
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

3. **Nonaktifkan HDD check untuk development:**
```php
// modules/core/helpers.php - comment out baris berikut
// if (!is_dir(MEEL_HDD_BASE)) { ... }
```

4. **Path konfigurasi terpusat:**
   Semua path penyimpanan dikelola dari **satu tempat** (`auth/config.php`):
   ```php
   define('MEEL_HDD_BASE', '/media/username/MEeL/media');
   ```
   Tidak perlu lagi mencari-cari path di banyak file.

4. **Tools yang disarankan:**
- Editor: VS Code dengan PHP Intelephense
- Database: MySQL Workbench / phpMyAdmin
- API Testing: Postman / Insomnia
- Browser: Chrome DevTools untuk debugging HTMX

---

## Standar Koding

### PHP

#### 1. PSR-12 Basic Coding Style

```php
<?php
// Gunakan PHP tags dengan benar
declare(strict_types=1);

namespace MEeL\Modules;

class MediaLibrary
{
    private mysqli $conn;
    
    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }
    
    public function getVideos(int $limit = 15, int $offset = 0): mysqli_result
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM video ORDER BY upload_date DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        return $stmt->get_result();
    }
}
```

#### 2. Prepared Statements WAJIB

```php
// ✅ BENAR - Prepared Statement
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// ❌ SALAH - Jangan gunakan query() dengan concatenation
// $result = $conn->query("SELECT * FROM users WHERE username = '$username'");
```

#### 3. Parameter Binding Types

| Type | PHP Type | SQL Type |
|------|----------|----------|
| `i` | int | INTEGER |
| `d` | float | DOUBLE/FLOAT |
| `s` | string | VARCHAR/TEXT |
| `b` | blob | BLOB/BINARY |

#### 4. Error Handling

```php
try {
    $stmt = $conn->prepare("INSERT INTO video (...) VALUES (...)");
    if (!$stmt) {
        throw new Exception($conn->error);
    }
    $stmt->bind_param("sss", $title, $filename, $description);
    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }
} catch (Exception $e) {
    error_log("[MEeL] ERROR: " . $e->getMessage());
    return ['status' => 'error', 'msg' => $e->getMessage()];
}
```

#### 5. Class Naming Convention

```php
// Class: PascalCase
class MediaLibrary {}
class BookRepository {}
class DriveUserContext {}
class DriveViewRenderer {}

// Methods: camelCase
public function getVideos();
public function toggleLike();
public function processDownload();

// Properties: camelCase with $ prefix
private $user_id;
private $base_path;
private $conn;
```

#### 6. Constants

```php
// Class constants: UPPER_SNAKE_CASE
private const FFMPEG_THREADS = 8;
private const HLS_SEGMENT_DURATION = 10;
private const DOWNLOAD_TIMEOUT = 900;

// Global constants: MEEL_HDD_* untuk path terpusat (di auth/settings.php)
define('MEEL_HDD_BASE', '/media/username/MEeL/media');
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
```

#### 7. Type Hints

Properti class dan parameter constructor **wajib** memiliki type hints (PHP 7.4+):

```php
// ✅ BENAR - Type hints
private \mysqli $conn;
private int $user_id;
private string $username;

public function __construct(\mysqli $db_connection, int $session_user_id, string $session_username) { ... }

// ❌ SALAH - Tanpa type hint
// private $conn;
// public function __construct($db_connection, $session_user_id) { ... }
```

### JavaScript

#### 1. Event Handlers

```javascript
// ✅ BENAR - Named functions
function handleSearch(event) {
    const query = event.target.value;
    // ...logic
}

// ❌ SALAH - Inline anonymous functions in HTML
// onclick="doSomething()"
```

```javascript
// ✅ BENAR - Event listeners
document.getElementById('search-input').addEventListener('input', handleSearch);
```

#### 2. HTMX Integration

```javascript
// Monitor HTMX events
document.body.addEventListener('htmx:afterOnLoad', function(evt) {
    // Re-initialize Lucide icons after HTMX swap
    lucide.createIcons();
    
    // Re-attach event listeners
    setupMusicItemClicks();
});
```

#### 3. Variables & Functions

```javascript
// Variables: camelCase
let isMiniPlayerActive = false;
const miniPlayerIndex = document.getElementById('mini-player-index');

// Functions: camelCase
function updateIndexUI() {}
function toggleMiniLoopIndex() {}

// Global functions for HTML onclick: window scoped
window.miniPlayPauseIndex = function() {};
```

### CSS

Proyek menggunakan **TailwindCSS (self-hosted, purged)** untuk styling utama, dengan CSS kustom minimal untuk efek khusus.

```css
/* CSS kustom hanya untuk efek yang tidak bisa dicapai dengan Tailwind */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,...");
    pointer-events: none;
    z-index: 0;
}

/* Animasi kustom */
@keyframes meel-fade-in {
    from { opacity: 0; backdrop-filter: blur(0px); }
    to { opacity: 1; backdrop-filter: blur(8px); }
}
```

### Database

#### Naming Convention

```sql
-- Tables: lowercase, plural
CREATE TABLE video (...);
CREATE TABLE music (...);
CREATE TABLE playlists (...);
CREATE TABLE playlist_tracks (...);

-- Columns: snake_case
id, user_id, created_at, is_active, path_folder

-- Foreign keys: descriptive
CONSTRAINT fk_parent_comment FOREIGN KEY (parent_id) REFERENCES comments (id)
```

#### Migration Pattern

Karena MEeL belum menggunakan migration framework, ikuti pattern ini:

```sql
-- File: migrations/001_add_description_column.sql
ALTER TABLE video ADD COLUMN description text DEFAULT NULL;
ALTER TABLE music ADD COLUMN description text DEFAULT NULL;

-- Update di update.php
-- Tambahkan entry di tabel updates
```

---

## Struktur Database

### Entity Relationship Diagram

```
users ──1:N── video
users ──1:N── music
users ──1:N── books
users ──1:N── comments
users ──1:N── playlists
users ──1:N── interactions
users ──1:N── upload_queue
users ──1:N── drive_files

comments ──1:N── comments (parent_id, nested)

playlists ──1:N── playlist_tracks
music ──1:N── playlist_tracks
```

### Key Relationships

| Table | Foreign Key | References | Type |
|-------|-------------|-----------|------|
| `video` | `user_id` | `users.id` | CASCADE |
| `music` | `user_id` | `users.id` | CASCADE |
| `books` | `user_id` | `users.id` | SET NULL |
| `comments` | `user_id` | `users.id` | CASCADE |
| `comments` | `parent_id` | `comments.id` | CASCADE |
| `interactions` | `user_id` | `users.id` | NO ACTION |
| `playlists` | `user_id` | `users.id` | CASCADE |
| `playlist_tracks` | `playlist_id` | `playlists.id` | CASCADE |
| `playlist_tracks` | `music_id` | `music.id` | CASCADE |

---

## Coding Conventions

### Keamanan

1. **Selalu Prepared Statement** — Tidak ada SQL concat
2. **Selalu htmlspecialchars()** — Untuk output
3. **CSRF Token** — Setiap form POST wajib
4. **Role Check** — Sebelum aksi sensitif
5. **Input Validation** — Tipe, ukuran, ekstensi file
6. **Tanpa `@` (error suppression) pada operasi filesystem** — gunakan guard proaktif
   `is_file()`/`is_dir()`/`is_readable()`/`is_writable()`, cek nilai balik, dan pakai
   helper bersama (trait `FfmpegUtils`, `GarbageCollector::removeFile()`/`removeDirectory()`,
   `meel_write_cache_file()`). Lihat [Konvensi Keamanan Filesystem](modules.md#konvensi-keamanan-filesystem-tanpa-).

### File Structure per Modul

Setiap modul (video, music, books, drive) mengikuti pola:

```
[module]/
├── index.php          # Katalog / daftar
├── watch.php          # Player / detail
├── upload.php         # Form upload
├── search_[module].php  # Pencarian (HTMX)
├── load_more.php      # Pagination (HTMX)
└── [module]_item.php  # Komponen kartu
```

### HTMX Pattern

```php
<!-- Trigger -->
<input type="text" name="search"
    hx-get="search_video.php"
    hx-trigger="keyup[key=='Enter']"
    hx-target="#video-container"
    hx-indicator="#search-indicator">

<!-- Target -->
<div id="video-container">
    <!-- Results loaded here -->
</div>

<!-- Indicator -->
<div id="search-indicator" class="htmx-indicator">
    <div class="animate-spin">⏳</div>
</div>
```

### CSS File Organization

```css
/* assets/css/[module].css */

/* 1. CSS Variables */
:root {
    --bg-main: #0b0f1a;
    --accent: #3b82f6;
}

/* 2. Base styles */
body { ... }

/* 3. Component styles */
.glass { ... }

/* 4. Utility overrides */
@media (max-width: 768px) { ... }
```

---

## Testing

### Manual Testing Checklist

Setiap perubahan harus di-test:

**Frontend:**
- [ ] Halaman tidak error di browser console
- [ ] HTMX request/response bekerja
- [ ] Mobile responsive (min width 320px)
- [ ] Dark mode konsisten
- [ ] Semua tombol dan link berfungsi

**Backend:**
- [ ] Prepared statements tidak error
- [ ] CSRF validation berfungsi
- [ ] Role-based access berfungsi
- [ ] File upload validasi berfungsi
- [ ] Error handling menampilkan pesan yang sesuai

### Debug Tools

```php
// 1. PHP Error Log
error_log("[MEeL] Debug message: " . $variable);

// 2. AJAX Response Log (server-side)
error_log("LIKE.PHP - POST: " . json_encode($_POST));

// 3. Browser Console
console.log('HTMX response received');
console.error('Error:', error);

// 4. Query Logging
$stmt = $conn->prepare("SELECT ...");
// Pastikan prepared statement tidak error
if (!$stmt) error_log("SQL Error: " . $conn->error);
```

---

## MFA / TOTP Development

### TOTP Implementation (Time-based One-Time Password)

MEeL mengimplementasikan TOTP sesuai [RFC 6238](https://datatracker.ietf.org/doc/html/rfc6238):

| Parameter | Nilai |
|-----------|-------|
| Algoritma | HMAC-SHA1 |
| Digit | 6 digit |
| Time Step | 30 detik |
| Window | ±1 (90 detik toleransi) |
| Encoding | Base32 |

### Helper Functions (di `modules/core/helpers.php`)

```php
// ─── GENERATE SECRET ───────────────────────────────────────
function generate_mfa_secret(): string {
    $random = random_bytes(20);  // 160-bit
    // Base32 encode (A-Z, 2-7)
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bits = 0; $buffer = 0;
    foreach (str_split($random) as $byte) {
        $buffer = ($buffer << 8) | ord($byte);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $secret .= $base32[($buffer >> $bits) & 31];
        }
    }
    return $secret;
}

// ─── GENERATE TOTP ─────────────────────────────────────────
function generate_totp(string $secret): string {
    $decoded = base32_decode($secret);  // Base32 → raw bytes
    $counter = pack('N*', 0) . pack('N*', intdiv(time(), 30));
    $hash = hash_hmac('sha1', $counter, $decoded, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset+1]) & 0xff) << 16) |
        ((ord($hash[$offset+2]) & 0xff) << 8) |
        (ord($hash[$offset+3]) & 0xff)
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

// ─── VERIFY TOTP (dengan window ±1) ────────────────────────
function verify_totp(string $secret, string $code): bool {
    for ($i = -1; $i <= 1; $i++) {
        // Generate TOTP dengan offset waktu $i step
        $expected = generate_totp_at($secret, time() + ($i * 30));
        if (hash_equals($expected, $code)) return true;
    }
    return false;
}
```

### Backup Codes System

```php
// ─── GENERATE 8 BACKUP CODES ───────────────────────────────
function generate_backup_codes(): array {
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $code = bin2hex(random_bytes(4));  // 8 karakter hex
        $plain[] = $code;
        $hashed[] = hash('sha256', $code);
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}

// ─── VERIFY BACKUP CODE (single-use) ───────────────────────
function verify_backup_code(string $hashedJson, string $code): array {
    $codes = json_decode($hashedJson, true) ?? [];
    foreach ($codes as $i => $hash) {
        if (hash_equals($hash, hash('sha256', $code))) {
            array_splice($codes, $i, 1);  // Hapus yang sudah dipakai
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => $codes];
}
```

### Database Schema

3 kolom baru di tabel `users` (Migration v9):

```sql
ALTER TABLE users
    ADD COLUMN mfa_secret      VARCHAR(64)  DEFAULT NULL AFTER last_session_id,
    ADD COLUMN mfa_backup_codes TEXT        DEFAULT NULL AFTER mfa_secret,
    ADD COLUMN mfa_enabled     TINYINT(1)   DEFAULT 0     AFTER mfa_backup_codes;
```

### MFA Session Flow

```
1. Login password benar → Cek mfa_enabled == 1
2. Ya → Simpan $_SESSION['mfa_temp_uid'] = user_id
          Simpan $_SESSION['mfa_temp_username']
          Simpan $_SESSION['mfa_temp_role']
3. Redirect ke mfa_verify.php
4. User input kode 6-digit
5. Valid → Set $_SESSION['user_id'], 'username', 'role']
          Set $_SESSION['mfa_verified'] = true
          Hapus mfa_temp_* dari session
6. Invalid → Increment $_SESSION['mfa_fail_count']
             Jika >= 10 → $_SESSION['mfa_locked_until'] = time() + 300
```

### Rate Limiting

| Endpoint | Limit | Mekanisme |
|----------|:-----:|-----------|
| MFA Verify | 10 gagal → lock 5 menit | Session-based `mfa_fail_count` + `mfa_locked_until` |
| Backup Password | 5 gagal → lock 5 menit | Session-based `backup_pwd_attempts` + `backup_pwd_lock_until` |

### Security Considerations

1. **Secret TOTP** — Disimpan plaintext di DB (TOTP secret harus bisa dibaca)
2. **Backup Codes** — Disimpan sebagai SHA256 hash (one-way, tidak bisa dibaca balik)
3. **Session Temp** — `mfa_temp_uid` hanya ada di session, tidak di cookie
4. **Brute Force** — 10 percobaan MFA gagal → lock 5 menit
5. **QR Code** — 100% offline (library qrcode.min.js lokal, tidak ada data dikirim ke server eksternal)
6. **Admin Reset** — Admin tidak bisa reset MFA admin lain
7. **Activity Log** — Semua event MFA (setup, verify, gagal, reset) dicatat di `activity_log`

### Testing MFA Locally

1. **Aktifkan MFA:** Buka `profile/index.php` → klik toggle MFA → ikuti setup
2. **Dapatkan TOTP:** Buka `auth/mfa_setup.php`, scan QR dengan Google Authenticator
3. **Simulate TOTP:** Gunakan `generate_totp($secret)` via script test untuk verifikasi
4. **Test rate limit:** Input kode salah 10× → cek lockout
5. **Test backup code:** Coba salah satu backup code untuk login
6. **Test admin reset:** Login sebagai admin → `admin/mfa_reset.php` → reset user

---

## Pull Request Guide

### 📜 Lisensi & Kontribusi

Proyek ini dilisensikan di bawah **GNU General Public License v3.0 (GPLv3)**. Lihat file [`LICENSE`](../../LICENSE) untuk teks lengkap.

> **Dengan mengirimkan Pull Request, Anda menyetujui bahwa kontribusi Anda akan dilisensikan di bawah GPL v3** — lihat [Pasal 10](https://www.gnu.org/licenses/gpl-3.0.html#section10) (Automatic Licensing of Downstream Recipients).

#### Copyright Header pada File Baru

Setiap file sumber baru (PHP, JavaScript, CSS) **wajib** menyertakan header copyright berikut:

```php
/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
```

#### Atribusi & Modified Version

GPL v3 mewajibkan (Pasal 5a):
1. Setiap file yang dimodifikasi harus diberi **notice perubahan** yang jelas
2. File yang dimodifikasi harus tetap **mengacu pada lisensi GPL v3**
3. **Karya turunan** (derivative work) harus dirilis di bawah **lisensi yang sama**

---

### Checklist Kontribusi

- [ ] Gunakan **Prepared Statements** untuk semua query database
- [ ] Sanitasi input POST/GET
- [ ] CSRF token di setiap form POST baru
- [ ] Role check sebelum operasi sensitif
- [ ] Update `update.php` dengan changelog
- [ ] Test upload file besar di lokal
- [ ] Test di mode incognito (session test)
- [ ] Setiap file baru memiliki **copyright header GPL v3**
- [ ] Perubahan ditandai dengan **notice modifikasi** yang jelas

### Git Commit Convention

```
[type]: Deskripsi singkat (max 50 chars)

- Detail perubahan jika perlu
- Bisa multi-line
```

**Type:**
| Type | Penggunaan |
|------|------------|
| `feat` | Fitur baru |
| `fix` | Bug fix |
| `security` | Perbaikan keamanan |
| `perf` | Optimasi performa |
| `refactor` | Refactoring kode |
| `docs` | Dokumentasi |
| `style` | CSS/perbaikan UI |

**Contoh:**
```
feat: Add playlist queue next/prev navigation

- Implement auto-next on song end
- Add keyboard shortcuts for skip
- Fix mini player sync across pages
```

### Branch Strategy

```
main (stable)
  └── Experiment (development branch)
       ├── feature/[nama-fitur]
       └── fix/[nama-fix]
```

---

## Troubleshooting Development

### ❌ HTMX tidak bekerja

**Cek:**
1. File `assets/js/compatibilitas/htmx.min.js` ter-load (cek Network tab)
2. Element target (`hx-target`) ada di DOM
3. Tidak ada JavaScript error di console
4. Response dari server valid HTML

### ❌ "Headers already sent" error

**Penyebab:** Output sebelum `header()` atau `session_start()`.

**Solusi:**
```php
// Output buffering di awal
ob_start();

// Atau pindahkan session_start() ke paling atas
session_name('meel');
session_start();

// Redirect dengan JavaScript fallback
if (!headers_sent()) {
    header("Location: index.php");
} else {
    echo "<script>window.location.href='index.php';</script>";
}
```

### ❌ Session tidak tersimpan

**Cek:**
1. `session_name('meel')` dipanggil SEBELUM `session_start()`
2. `auth/config.php` di-include di setiap halaman
3. Tidak ada output sebelum `session_start()`
4. Folder session writable

### ❌ SweetAlert2 tidak muncul

**Cek:**
1. File `assets/js/compatibilitas/sweetalert2.all.min.js` ter-load
2. Fungsi `meelAlertRedirect()` didefinisikan di `assets/js/compatibilitas/script.min.js`
3. Tidak ada CSS conflict

---

## Resource untuk Developer

### File Penting untuk Dipahami

| File | Alasan |
|------|--------|
| `auth/config.php` | Entry point configurasi |
| `auth/auth.php` | Authentication middleware |
| `modules/core/helpers.php` | Fungsi utilitas global |
| `modules/core/Transcoder.php` | Engine utama (paling kompleks) |
| `modules/core/Uploader.php` | Proses upload file |
| `modules/core/System.php` | Queue & monitoring |
| `modules/core/RateLimiter.php` | API Rate Limiter |
| `modules/core/ProgressObserver.php` | Kontrak event progress (interface + adapter callable) — lihat `modules.md` |
| `modules/core/BrowserProgressObserver.php` | Presenter browser — memetakan event engine ke overlay/JS `meel*` |
| `modules/core/GarbageCollector.php` | Auto-cleanup |
| `modules/media/SearchEngine.php` | FULLTEXT Search engine |
| `modules/core/japanese.php` | Japanese text processing |
| `modules/core/bootstrap.php` | Bootstrap & environment |
| `modules/exceptions/*.php` | Exception classes |
| `modules/transcoder/FfmpegUtils.php` | FFmpeg utilities trait |
| `auth/mfa_setup.php` | MFA Setup (multi-step: secret → QR → verify → backup) |
| `auth/mfa_verify.php` | MFA TOTP verification page (rate limited) |
| `controllers/system/mfa.php` | MFA backend controller (generate/download backup codes) |
| `admin/mfa_reset.php` | Admin MFA reset panel |
| `partials/ui.php` | Overlay UI system (JS heavy) |
| `assets/js/shared/keyboard.js` | Guard shortcut keyboard bersama (meelKeyShortcutIgnored) — dipakai misc/mini-player video & music |
| `assets/js/shared/temp-index.js` | Loader bersama index.php ke #temp-index-content tanpa reload (meelLoadTempIndex) — dipakai mini-player video & music |
| `assets/js/shared/plyr-config.js` | Konfigurasi dasar Plyr bersama (MEEL_PLYR_COMMON: iconUrl, speed, keyboard, tooltips) — dipakai player video & music |
| `assets/js/shared/upload-progress.js` | Animasi progress-bar upload bersama (meelUploadProgress) — dipakai halaman upload music & video |
| `assets/js/shared/resume-modal.js` | Modal resume bersama (meelResumeModal) — dipakai player-events video & player-core music |
| `assets/js/shared/format-time.js` | Formatter waktu mm:ss bersama (formatTime) — dipindah dari music/shared/utils.js, dipakai mini-player music & resume-modal |
| `assets/js/shared/mini-player-popstate.js` | Handler popstate bersama untuk keluar dari mode mini-player (meelMiniPlayerPopstate) — dipakai mini-player watch video & music |
| `assets/js/video/watch/main.js` | Entry point folder watch/ — memuat sibling secara sinkron (document.write) |
| `assets/js/video/watch/state.js` | Video player state management |
| `assets/js/video/watch/player-init.js` | Plyr + HLS.js initialization |
| `assets/js/video/watch/player-events.js` | Event orchestration (auto-next, glow, resume) |
| `assets/js/video/watch/mini-player.js` | Mini-player floating mode |
| `assets/js/video/watch/recovery.js` | Player auto-recovery system |
| `assets/js/video/watch/gestures.js` | Mobile touch gestures |
| `assets/js/music/watch/main.js` | Entry point folder watch/ — memuat sibling secara sinkron (document.write) |
| `assets/js/music/watch/mini-player.js` | Mode mini-player music (Spotify-style) — dipisah dari player-core.js |
| `assets/js/music/watch/player-core.js` | Inti player music (visualizer, EQ, bitrate, logika resume-modal & sesi) |
| `assets/js/music/watch/state.js` | Music player state, preset equalizer & marker sesi resume (`window.__meelResumeSessionActive`) |

### Musik — Perilaku Resume Modal

Player musik menampilkan modal **"Lanjut Musik?"** ketika sebuah lagu punya
posisi putar tersimpan (`music_pos_<id>` di `localStorage`) dan user **tidak**
datang dari sesi mini-player yang aktif.

| Konteks | Perilaku |
|---------|----------|
| **Sesi mini-player** — user men-tap kartu / item playlist atau expand mini-player di `index.php`, dan masih mendengarkan | 🎧 **Auto-continue** — tanpa modal; semua lagu berikutnya di sesi itu langsung diputar otomatis |
| **Kunjungan dingin** — buka `watch.php` langsung, reload halaman, atau setelah pause/close eksplisit mini-player | ❓ **Modal muncul** — "Lanjut Musik?" menanyakan apakah lanjut dari posisi tersimpan |

**Mekanisme:**

- **Flag one-shot `skip_resume_once`** (`sessionStorage`) — dipasang sisi index
  saat tap kartu/playlist dan di `expandPlayerFromMiniPlayer()`. Dibaca dan
  dibuang di **setiap** pemanggilan `meelInitWatchPlayer()` (termasuk transisi
  gapless), jadi tidak pernah nyangkut di storage.
- **Marker sesi `window.__meelResumeSessionActive`** (in-memory, dideklarasikan
  di `assets/js/music/watch/state.js`) — diaktifkan saat flag one-shot
  dikonsumsi. Bertahan selama dokumen SPA, jadi **semua** perpindahan lagu
  berikutnya di watch (auto-next, ganti lagu) melewati modal.
- **Akhir sesi eksplisit** — `miniPlayPauseIndex()` (pause) dan
  `closeMiniPlayerIndex()` di `index.php` membersihkan flag one-shot dan marker
  sesi (`assets/js/music/shared/mini-player.js`). Setelah itu, membuka lagu
  dari link menampilkan modal lagi.
- **Kunjungan dingin** — full page load membuat dokumen baru di mana marker
  in-memory hilang, jadi modal bisa muncul (`skipOnce` di `player-core.js`
  mengecek `skipResumeModalOnce || window.__meelResumeSessionActive`).
- **Guard stuck-paused** — jika modal ditekan tapi lagu punya posisi tersimpan,
  `onFreshTrackReady()` auto putar dari awal, bukan membiarkan lagu diam.

> **Keputusan desain (2026-08):** sesi mendengarkan aktif auto-continue tanpa
> interupsi; hanya kunjungan dingin yang menanyakan resume.

### Proses yang Perlu Dipahami

1. **Upload Pipeline** — Uploader → FFmpeg → HDD → DB
2. **Download Pipeline** — URL → yt-dlp → FFmpeg → HDD → DB
3. **Auth Flow** — Login → Session → RBAC → Activity Log
4. **HTMX Flow** — Event → Request → Server → Response → DOM swap
5. **MFA Flow** — Login password valid → Cek mfa_enabled → Redirect mfa_verify.php → Verify TOTP → Set session penuh
6. **Sesi Music Player & Resume** — Tap kartu/playlist → mini-player (set `skip_resume_once`) → expand → watch (konsumsi flag, aktifkan marker sesi) → auto-continue; kunjungan dingin menampilkan resume-modal

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
