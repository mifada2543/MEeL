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
// modules/helpers.php - comment out baris berikut
// if (!is_dir($hdd_check_path)) { ... }
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

// Global constants: MEEL_HDD_* untuk path terpusat (di auth/config.php)
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
1. File `assets/js/htmx.js` ter-load (cek Network tab)
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
1. File `assets/js/sweetalert2.all.min.js` ter-load
2. Fungsi `meelAlertRedirect()` didefinisikan di `assets/js/script.js`
3. Tidak ada CSS conflict

---

## Resource untuk Developer

### File Penting untuk Dipahami

| File | Alasan |
|------|--------|
| `auth/config.php` | Entry point configurasi |
| `auth/auth.php` | Authentication middleware |
| `modules/helpers.php` | Fungsi utilitas global |
| `modules/Transcoder.php` | Engine utama (paling kompleks) |
| `modules/Uploader.php` | Proses upload file |
| `modules/System.php` | Queue & monitoring |
| `partials/ui.php` | Overlay UI system (JS heavy) |

### Proses yang Perlu Dipahami

1. **Upload Pipeline** — Uploader → FFmpeg → HDD → DB
2. **Download Pipeline** — URL → yt-dlp → FFmpeg → HDD → DB
3. **Auth Flow** — Login → Session → RBAC → Activity Log
4. **HTMX Flow** — Event → Request → Server → Response → DOM swap

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
