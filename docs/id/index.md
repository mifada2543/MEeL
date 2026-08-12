# 📚 Dokumentasi MEeL-HUB

Selamat datang di dokumentasi resmi **MEeL** — Platform Media Hub Pribadi untuk streaming video, musik, buku digital, dan cloud storage.

---

## 📋 Peta Dokumentasi

| # | Dokumen | Deskripsi |
|---|---|---|
| 1 | [🚀 Instalasi](installation.md) | Panduan instalasi lengkap dari awal hingga aplikasi berjalan |
| 2 | [⚙️ Konfigurasi](configuration.md) | Referensi semua file konfigurasi dan parameter |
| 3 | [🏗️ Modul & Arsitektur](modules.md) | Penjelasan mendalam setiap modul dan class |
| 4 | [🔌 API & Controller](api.md) | Dokumentasi semua endpoint AJAX/HTMX |
| 5 | [🔒 Keamanan](security.md) | Sistem keamanan, RBAC, CSRF, IP Banning |
| 6 | [🌍 Problem Solved](problem-solved.md) | Masalah dunia nyata yang melatarbelakangi MEeL |
| 7 | [🔧 Troubleshooting](troubleshooting.md) | Solusi untuk masalah umum |
| 8 | [👨‍💻 Panduan Development](development.md) | Standar koding, kontribusi, dan testing |
| 9 | [📥 Troubleshooting Advanced Upload](upload_issue.md) | Penanganan masalah yt-dlp & background queue |
| 10 | [🧪 Testing Guide](test.md) | PHPUnit, Functional, Security test — panduan lengkap |
| 11 | [📱 PWA](pwa.md) | Progressive Web App: service worker dinamis, strategi cache, offline |

---

## 📦 Daftar Modul Lengkap

| Modul | File | Deskripsi |
|---|---|---|
| **Exception Classes** | `modules/exceptions/*.php` | 3 class exception spesifik: `ProcessException`, `DownloadException`, `TranscodeException` |
| **Japanese Processor** | `modules/core/japanese.php` | MeCab + transliterator untuk teks Jepang → Romaji filenames |
| **Bootstrap** | `modules/core/bootstrap.php` | Environment detection (dev/prod), error reporting, timezone |
| **CommentRenderer** | `modules/core/CommentRenderer.php` | Render komentar dengan theme support (`video`/`music`) |
| **SearchEngine** | `modules/media/SearchEngine.php` | FULLTEXT search engine untuk video, music & books — dengan sanitizer query (`sanitizeQuery()`), min query 3 karakter, cache key offset |
| **GarbageCollector** | `modules/core/GarbageCollector.php` | Auto-cleanup temporary files & guest accounts |
| **RateLimiter** | `modules/core/RateLimiter.php` | File-based API rate limiter (30 likes/min, 10 comments/min, dll.) |
| **WatchController** | `controllers/api/WatchController.php` | Controller gabungan Video + Music watch pages |
| **UpdateManager** | `controllers/system/UpdateManager.php` | CRUD changelog entries (OOP) |
| **DriveService** | `drive/DriveService.php` | 3 class: DriveUserContext, DriveStorage, DriveViewRenderer |
| **Profile Manager** | `controllers/profile/fun-manage.php` | Delete media, pending deletions, cleanup |
| **Migration System** | `database/migrate.php` | Versioned database schema upgrades v1–v11 (idempotent) |
| **PWA Precache** | `modules/core/SwPrecache.php` | Generator precache service worker dinamis — membaca `assets/css/*/manifest.php`, `SW_VERSION` otomatis dari hash konten |
| **PWA Generator** | `sw.js.php` | Service worker dibangkitkan per request (disajikan sebagai `/sw.js` via rewrite `.htaccess`) |
| **Autoloader** | `modules/autoload.php` | PSR-4-like autoloading |
| **Activity Logger** | `modules/core/activity_logger.php` | IP detection, session kick, guest auto-registration |
| **MFA System** | `controllers/system/mfa.php` | MFA backend controller (TOTP verify, backup codes, email) |
| **MFA Setup** | `auth/mfa_setup.php` | Setup MFA (generate secret, verify TOTP, backup codes) |
| **MFA Verify** | `auth/mfa_verify.php` | Halaman verifikasi TOTP setelah login |
| **MFA Reset (Admin)** | `admin/mfa_reset.php` | Admin reset MFA user jika kehilangan akses Authenticator |
| **Chess Multiplayer** | `arcade/chess/` | Catur real-time via LAN — buat/gabung ruang, giliran, legal move validation |
| **FfmpegUtils Trait** | `modules/transcoder/FfmpegUtils.php` | Shared trait: probeDuration(), generateSpriteAndVTT() |
| **Admin Activity Log** | `admin/activity_log.php` | Audit trail viewer dengan filter, pagination, cleanup |

---

## 📁 File Penting Baru

| File | Deskripsi |
|---|---|
| `database/schema.sql` | Skema database standalone — import langsung `mysql < database/schema.sql` |
| `auth/config.example.php` | Template entry point (copy ke `config.php`) |
| `auth/settings.example.php` | Template data konfigurasi (copy ke `settings.php`) |
| `sw.js.php` | Generator service worker dinamis (disajikan sebagai `/sw.js`) |
| `modules/core/SwPrecache.php` | Generator daftar precache + versi otomatis untuk SW |
| `assets/MEeL-{180,192,512}.png` | Ikon PWA asli (180/192/512 px) |

## 🔧 Perubahan Terbaru

- **Path terpusat:** Semua path penyimpanan media (Video, Music, Books, Drive) diatur dari `MEEL_HDD_BASE` di `auth/settings.php` — cukup ubah 1 baris
- **Skema database standalone:** File `database/schema.sql` untuk import cepat
- **Type hints:** Properti class dan parameter constructor sekarang menggunakan type hints (`\mysqli`, `int`, `string`, dll.)
- **Activity Log Integration:** `log_activity()` function + integrasi di login, logout, upload, dan admin actions — audit trail penuh ke tabel `activity_log`
- **Admin Activity Log Viewer:** Halaman `admin/activity_log.php` untuk melihat, filter, dan cleanup trail audit
- **Database Alignment:** `schema.sql` dan `migrate.php` tersinkronisasi (v1–v11) — FULLTEXT, FK, UNIQUE KEY, activity_log, MFA, composite index comments, unique key interactions
- **Migrasi v10:** Index komposit `(video_id, created_at)` & `(music_id, created_at)` pada tabel `comments`
- **Migrasi v11:** Unique key `interactions` dipecah menjadi `(user_id, video_id)` & `(user_id, music_id)` — NULL di unique key gabungan tidak mencegah duplikat
- **Modul Anime dihapus:** Modul placeholder "Coming Soon" yang sudah tidak relevan dihapus dari kodebase
- **API Rate Limiting:** File-based rate limiter (`modules/core/RateLimiter.php`) — proteksi endpoint like, comment, upload dari abuse dengan per-user limits dan role-based adjustment (admin=unlimited, member=2x)
- **Pagination Metadata:** `MediaLibrary` & `BookRepository` sekarang mengembalikan metadata pagination (`total_pages`, `from`, `to`) — UI menampilkan info halaman
- **Admin Dashboard Charts:** Chart.js 7-Day Activity Chart — views, uploads, active users dalam 7 hari terakhir
- **Player Enhancement:** Auto-next overlay dengan backdrop gelap + sembunyikan replay button Plyr + mutual exclusion Auto-Next ↔ Loop
- **MFA Support:** Multi-Factor Authentication (TOTP) — setup, verify, backup codes, admin reset, brute-force protection (10 attempts → 5 menit lock)
- **UX Improvement:** Klik vinyl disc → toggle mini-player; Hover overlay hanya di area thumbnail musik; Skip resume modal saat navigasi dari index mini-player
- **Cache Busting:** Script JS music watch.php pakai `filemtime()` — tidak perlu hard-refresh
- **Arcade Chess:** Multiplayer catur real-time via LAN — buat/gabung ruang, giliran bergantian, validasi legal move
- **Chess Color Picker:** Di mode multiplayer, papan disembunyikan di balik overlay pilihan warna (Putih = buat room & tunggu, Hitam = join pakai kode) — papan terkunci sampai game dimulai
- **Chess Auth & CSRF:** Controller multiplayer kini wajib login (JSON 401) dan token CSRF di semua panggilan yang mengubah state; endpoint admin `auto_cleanup` diverifikasi dengan CSRF
- **PWA Optimization:** Service worker dinamis (`sw.js.php` + `SwPrecache`) — daftar precache otomatis dari `manifest.php`, `SW_VERSION` otomatis, ikon asli 192/512/maskable, meta iOS standalone, auto-reload saat update SW
- **Search Improvements:** Sanitizer query (`sanitizeQuery()`), `MIN_SEARCH_QUERY = 3`, pagination search musik, search buku server-side (`BookRepository::searchBooks()`), cache key menyertakan offset, `try/catch` di sekitar query FULLTEXT
- **Auth Hardening:** Cookie session kini `Secure` (auto-detect HTTPS) + `HttpOnly` + `SameSite=Lax`; `MEEL_TRUST_PROXY_HEADERS` (default `false`) untuk mencegah IP spoofing via header proxy; charset koneksi DB dipaksa `utf8mb4`
- **Admin CSRF:** Aksi approve/reject/delete/kick/unban dipindah dari link GET ke form POST dengan token CSRF
- **Session Bootstrap Terpusat:** File baru `modules/core/helpers/session.php` berisi `meel_boot_session()` — semua entry point (index, video, music, auth, controllers/api, err, admin) kini memanggil satu fungsi ini menggantikan pola lama `session_name('meel'); session_start();` yang tersebar. Cookie sesi dijamin selalu `HttpOnly` + `SameSite=Lax` + `Secure` (auto-detect HTTPS), timeout 12 jam, dan idempotent (no-op jika session sudah aktif)

## 📖 Tentang Proyek

**MEeL** adalah platform media hub pribadi berbasis PHP & MySQL yang berjalan di atas Apache. Platform ini menggabungkan:

- **🎬 Video** — Streaming adaptif HLS dengan Plyr.js
- **🎵 Music** — Audio streaming dengan visualizer & mini player
- **📚 Books** — Pembaca manga/PDF digital
- **☁️ Cloud Drive** — Penyimpanan file pribadi dengan RBAC
- **🕹️ Arcade** — Mini-game (Dino Run, Snake, Chess)

### Tech Stack Utama

| Komponen | Teknologi |
|---|---|
| Backend | PHP 8.0+, MySQL/MariaDB |
| Frontend | TailwindCSS, HTMX, Vanilla JS |
| Media Player | Plyr.js, HLS.js |
| Transcoding | FFmpeg 6.0+, FFprobe |
| Downloader | yt-dlp |
| Server | Apache 2.4+ (mod_rewrite) |

---

## 🔗 Tautan Penting

- [README.md](../../README.md) — Ikhtisar proyek
- [LICENSE](../../LICENSE) — Lisensi proyek
- [GitHub Repository](https://github.com/mifada2543/MEeL) — Repo sumber
- [Bug Report](../../.github/ISSUE_TEMPLATE/bug_report.md) — Template laporan bug

---

## 👨‍💻 Kontak

- **Email:** mifada2543@gmail.com
- **GitHub:** [github.com/mifada2543](https://github.com/mifada2543)

---

<div align="center">
  <sub>MEeL © 2026 — Mifada | Dokumentasi v2.0</sub>
</div>
