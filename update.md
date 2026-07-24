# 📋 Changelog & Review — Perbaikan Audit Repository MEeL-HUB

> **Ringkasan semua perubahan yang dilakukan berdasarkan hasil audit menyeluruh**
> Tanggal: 24 Juli 2026

---

## 📊 Status Akhir

| Metrik | Hasil |
|--------|-------|
| **Total item audit** | 20 ✅ selesai dari 20 |
| **Security Test** | 100/100 (A) — 0 fail, 0 warn |
| **Functional Test** | 98/100 (A) |
| **PHP Syntax** | 97 file valid |

---

## 🔴 PRIORITAS KRITIS (4/4)

### 1.1 `display_errors` Terpusat

**Masalah:** 17 file menjalankan `ini_set('display_errors', 1)` meskipun komentar mengklaim sebaliknya — membocorkan path server, query SQL, dan stack trace.

**Perbaikan:**
- File baru: `modules/core/bootstrap.php` — environment detection + error reporting terpusat
- `MEEL_ENV` auto-detect (`'production'` / `'development'` / `'maintenance'`)
- Production: `display_errors=0`, Development: `display_errors=1`
- Hapus `ini_set('display_errors', 1)` dari 17 file entry-point

### 1.2 Content-Security-Policy

**Masalah:** `script-src 'self' 'unsafe-inline' 'unsafe-eval'` meniadakan manfaat CSP terhadap XSS.

**Perbaikan:**
- Hapus `'unsafe-eval'` dari CSP di `auth/config.php` dan `auth/config.example.php`
- Sisakan `'unsafe-inline'` untuk kompatibilitas inline script yang belum dipindah ke file eksternal

### 1.3 Query Non-Parameterized di Profile

**Masalah:** `$conn->query("SELECT COUNT(*) ... user_id = '$profile_id'")` — interpolasi langsung.

**Perbaikan:**
- `profile/index.php` — ganti ke prepared statement `bind_param("i", $profile_id)`

### 1.4 Open Redirect via HTTP_REFERER

**Masalah:** Validasi referer hanya membandingkan dengan `$_SERVER['HTTP_HOST']` yang bisa dipalsukan.

**Perbaikan:**
- Tambah konstanta `MEEL_HOST` di config
- Gunakan whitelist hostname tetap (`MEEL_HOST`, `localhost`, `127.0.0.1`)

---

## 🟠 PRIORITAS TINGGI (6/6)

### 2.1 Duplikasi Query Role Admin

**Masalah:** 6 file melakukan query `SELECT role FROM users WHERE id = ?` terpisah tiap request.

**Perbaikan:**
- Helper `get_user_role($conn, $user_id)` dengan 3-level cache:
  1. Static cache (per-request)
  2. Session cache (`$_SESSION['role']`)
  3. Query DB (fallback)
- Helper `invalidate_user_role_cache()` — panggil saat role berubah
- Update 4 admin files: `edit-video.php`, `edit-music.php`, `cookies.php`, `activity_log.php`

### 2.2 Guard Admin `index.php`

**Masalah:** `admin/index.php` tidak memverifikasi role admin secara mandiri — hanya bergantung pada side-effect dari include.

**Perbaikan:**
- Tambah guard eksplisit di baris paling atas `admin/index.php` (sebelum include apa pun)

### 2.3 Rate Limiter Race Condition

**Masalah:** Storage file tunggal tanpa penguncian (`flock`) pada read-modify-write.

**Perbaikan:**
- Diverifikasi sudah menggunakan `flock(LOCK_EX)` — **tidak ada perubahan**
- Dokumentasi: RateLimiter sudah aman untuk concurrent request

### 2.4 `extract()` Tanpa Proteksi

**Masalah:** `extract($ctrl->getViewData())` bisa overwrite variabel scope (`$conn`, `$user_id`).

**Perbaikan:**
- Tambah parameter `EXTR_SKIP` di `music/watch.php` dan `video/watch.php`

### 2.5 CSRF Konsolidasi

**Masalah:** Dua fungsi CSRF paralel (`verify_csrf()` vs `verify_csrf_token()`) meningkatkan risiko salah pakai.

**Perbaikan:**
- `verify_csrf()` di `config.php` dan `config.example.php` sekarang delegasi ke `verify_csrf_token()`
- `verify_csrf_token()` di `helpers.php` menggunakan `hash_equals()` — cegah timing attack
- Dukungan GET/POST token opsional

### 2.6 Binary Path Constants

**Masalah:** Path binary (`ffmpeg`, `ffprobe`, `node`, `yt-dlp`) hardcoded di berbagai file — risiko binary-hijacking.

**Perbaikan:**
- Konstanta baru di `auth/config.php` & `config.example.php`:
  - `MEEL_FFMPEG_PATH`
  - `MEEL_FFPROBE_PATH`
  - `MEEL_NODE_PATH`
  - `MEEL_YTDLP_PATH`
- `resolve_binary()` di `helpers.php` — cek konstanta dulu sebelum fallback auto-discovery
- Update 6 call sites:
  - `modules/core/Uploader.php` — via `resolveBinary()`
  - `modules/core/Transcoder.php` — via `resolveBinary()`
  - `controllers/api/auto_metadata.php` — via `resolve_binary()`
  - `admin/edit-video.php` — via `resolve_binary()`
  - `admin/edit-music.php` — via `resolve_binary()`
  - `modules/media/MediaLibrary.php` (BookUploader) — via `MEEL_FFMPEG_PATH` + fallback

---

## 🟡 PRIORITAS SEDANG (5/5)

### 3.1 Chess Room Code `md5(time())`

**Masalah:** Kode room chess cuma dari `md5(time())` — bisa ditebak/ditabrakan.

**Perbaikan:**
- Ganti ke `strtoupper(substr(bin2hex(random_bytes(4)), 0, 6))`

### 3.2 N+1 Query Audit

**Masalah:** Potensi N+1 jika partial (`music_item.php`, `video_card.php`) melakukan query per-item.

**Perbaikan:**
- Verifikasi: `music_item.php` dan `video_card.php` adalah **template-only** — tidak ada query DB
- **Tidak ada perubahan**

### 3.3 Module Restructuring

**Masalah:** Struktur `modules/` campuran — file flat + sub-folder.

**Perbaikan:**
- Pindahkan 10 file dari `modules/` ke `modules/core/`:
  `System.php`, `Uploader.php`, `Transcoder.php`, `helpers.php`, `activity_logger.php`,
  `GarbageCollector.php`, `RateLimiter.php`, `japanese.php`, `CommentRenderer.php`, `bootstrap.php`
- Fix `__DIR__` path di 5 file (log, temp, thumb, cache path)
- Update `modules/autoload.php`
- Update ~70 `require`/`include` references di seluruh project
- Hapus file asli di `modules/` setelah diverifikasi

### 3.4 .htaccess Audit

**Masalah:** 15+ folder `.htaccess` — risiko folder baru tidak terproteksi.

**Perbaikan:**
- 4 .htaccess baru untuk direktori kritis: `modules/core/`, `modules/exceptions/`, `docs/partials/`, `drive/templates/`
- 3 .htaccess baru untuk upload directory: `books/upload/`, `music/upload/`, `video/upload/` — `php_flag engine off` + ForceType
- Fix regex di `modules/transcoder/.htaccess` (escaped dots)
- **Total: 35 file .htaccess**
- Update security test TEST 7 — scan 23 folder sensitif

### 3.5 Docs Bilingual Sync

**Masalah:** `docs/en/analysis.md` tidak punya padanan di `docs/id/`.

**Perbaikan:**
- Buat `docs/id/analysis.md` — analisis proyek dalam Bahasa Indonesia

---

## 🟢 PRIORITAS RENDAH (5/5)

### 4.1 Vendor Versions

**Masalah:** Tidak ada catatan versi library vendor JS/CSS — sulit lacak CVE.

**Perbaikan:**
- Buat `assets/js/VENDOR_VERSIONS.md` — 8 library tercatat dengan versi + sumber unduhan

### 4.2 `dirSize()` Helper Cache

**Masalah:** Duplikasi `shell_exec("du -sb ...")` di `helpers.php` dan `System.php` — bisa timeout untuk folder besar.

**Perbaikan:**
- Helper `dir_size($path, $cache_ttl = 300)` dengan file-based cache
- Cache TTL default 5 menit — cegah `du -sb` di setiap page-load admin dashboard

### 4.3 README Sync

**Masalah:** `README.md` (Indonesia) vs `README-en.md` (Inggris) tidak sinkron.

**Perbaikan:**
- `README-en.md` di-sinkronisasi penuh — tambah:
  - Screenshots, Project Structure tree, Configuration section
  - PHP Extensions, expanded Tech Stack
  - Last synced timestamp
- `README.md` — update project structure ke `modules/core/`

### 4.4 CI Workflow

**Masalah:** Tidak ada CI otomatis — regresi tidak terdeteksi.

**Perbaikan:**
- Buat `.github/workflows/ci.yml` — GitHub Actions:
  - `php -l` semua file `.php`
  - `tests/functional_test.php`
  - `tests/security_test.php`
  - Trigger: push ke main, pull request

### 4.5 `logs/.gitkeep`

**Masalah:** Folder `logs/` tidak ter-track di git karena kosong.

**Perbaikan:**
- Buat `logs/.gitkeep` (konsisten dengan pola `temp/`)

---

## 🔧 Fix Tambahan

### Test Suite Improvements

**Masalah:** False positive di `tests/security_test.php` — comment/docblock containing `shell_exec` counted as real code.

**Perbaikan:**
- Fungsi `stripPhpComments()` di `tests/helpers.php` — hapus `//`, `/* */`, `#` comments
- `countInFile()` sekarang strip comments sebelum pattern matching
- Tighten `#` regex: `'/(?:^|\s)#.*$/m'` — hindari match di dalam string

**Masalah:** 2 warnings sisa — ALTER TABLE AUTO_INCREMENT + bootstrap display_errors.

**Perbaikan:**
- `admin/activity_log.php`, `admin_actions.php`, `migrate.php`, `GarbageCollector.php` — inline `(int)` cast di query string
- Security test — exclude `modules/core/bootstrap.php` dari display_errors check (env-aware)

### Final Test Scores

| Test | Skor Awal | Skor Akhir |
|------|:---------:|:----------:|
| **Security Test** | 95/100 (A) | **100/100 (A)** |
| **Functional Test** | 97/100 (A) | **98/100 (A)** |
| **All PHP Syntax** | ✅ | **✅ 97 file valid** |

---

> *Last updated: 24 Juli 2026*
> *Audit & perbaikan oleh Agent AI — Freebuff*
