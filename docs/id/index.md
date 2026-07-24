# 📚 Dokumentasi MEeL-HUB

Selamat datang di dokumentasi resmi **MEeL** — Platform Media Hub Pribadi untuk streaming video, musik, buku digital, dan cloud storage.

---

## 📋 Peta Dokumentasi

| # | Dokumen | Deskripsi |
|---|---------|-----------|
| 1 | [🚀 Instalasi](installation.md) | Panduan instalasi lengkap dari awal hingga aplikasi berjalan |
| 2 | [⚙️ Konfigurasi](configuration.md) | Referensi semua file konfigurasi dan parameter |
| 3 | [🏗️ Modul & Arsitektur](modules.md) | Penjelasan mendalam setiap modul dan class |
| 4 | [🔌 API & Controller](api.md) | Dokumentasi semua endpoint AJAX/HTMX |
| 5 | [🔒 Keamanan](security.md) | Sistem keamanan, RBAC, CSRF, IP Banning |
| 6 | [🌍 Problem Solved](problem-solved.md) | Masalah dunia nyata yang melatarbelakangi MEeL |
| 7 | [🔧 Troubleshooting](troubleshooting.md) | Solusi untuk masalah umum |
| 8 | [👨‍💻 Panduan Development](development.md) | Standar koding, kontribusi, dan testing |
| 9 | [📥 Troubleshooting Advanced Upload](upload_issue.md) | Penanganan masalah yt-dlp & background queue |

---

## 📦 Daftar Modul Lengkap

| Modul | File | Deskripsi |
|-------|------|-----------|
| **Exception Classes** | `modules/exceptions/*.php` | 3 class exception spesifik: ProcessException, DownloadException, TranscodeException |
| **CommentRenderer** | `modules/CommentRenderer.php` | Render komentar dengan theme support (`video`/`music`) |
| **SearchEngine** | `modules/media/SearchEngine.php` | FULLTEXT search engine untuk video & music |
| **GarbageCollector** | `modules/GarbageCollector.php` | Auto-cleanup temporary files & guest accounts |
| **WatchController** | `controllers/api/WatchController.php` | Controller gabungan Video + Music watch pages |
| **UpdateManager** | `controllers/system/UpdateManager.php` | CRUD changelog entries (OOP) |
| **DriveService** | `drive/DriveService.php` | 3 class: DriveUserContext, DriveStorage, DriveViewRenderer |
| **Profile Manager** | `controllers/profile/fun-manage.php` | Delete media, pending deletions, cleanup |
| **Migration System** | `database/migrate.php` | Versioned database schema upgrades v1–v7 (idempotent) |
| **Autoloader** | `modules/autoload.php` | PSR-4-like autoloading via spl_autoload_register |
| **RateLimiter** | `modules/RateLimiter.php` | File-based API rate limiter (30 likes/min, 10 comments/min, dll.) |
| **Admin Activity Log** | `admin/activity_log.php` | Audit trail viewer dengan filter, pagination, cleanup |

---

## 📁 File Penting Baru

| File | Deskripsi |
|------|-----------|
| `database/schema.sql` | Skema database standalone — import langsung `mysql < database/schema.sql` |
| `auth/config.example.php` | Template konfigurasi (copy ke `config.php`) |

## 🔧 Perubahan Terbaru

- **Path terpusat:** Semua path penyimpanan media (Video, Music, Books, Drive) diatur dari `MEEL_HDD_BASE` di `auth/config.php` — cukup ubah 1 baris
- **Skema database standalone:** File `database/schema.sql` untuk import cepat
- **Type hints:** Properti class dan parameter constructor sekarang menggunakan type hints (`\mysqli`, `int`, `string`, dll.)
- **Activity Log Integration:** `log_activity()` function + integrasi di login, logout, upload, dan admin actions — audit trail penuh ke tabel `activity_log`
- **Admin Activity Log Viewer:** Halaman `admin/activity_log.php` untuk melihat, filter, dan cleanup trail audit
- **Database Alignment:** `schema.sql` dan `migrate.php` tersinkronisasi (v1–v7) — UNIQUE KEY username, FK constraints, FULLTEXT index
- **Modul Anime dihapus:** Modul placeholder "Coming Soon" yang sudah tidak relevan dihapus dari kodebase
- **API Rate Limiting:** File-based rate limiter (`modules/RateLimiter.php`) — proteksi endpoint like, comment, upload dari abuse dengan limits per user
- **Pagination Metadata:** `MediaLibrary` & `BookRepository` sekarang mengembalikan metadata pagination (`total_pages`, `from`, `to`) — UI menampilkan info halaman
- **Admin Dashboard Charts:** Chart.js 7-Day Activity Chart — views, uploads, active users dalam 7 hari terakhir

## 📖 Tentang Proyek

**MEeL** adalah platform media hub pribadi berbasis PHP & MySQL yang berjalan di atas Apache. Platform ini menggabungkan:

- **🎬 Video** — Streaming adaptif HLS dengan Plyr.js
- **🎵 Music** — Audio streaming dengan visualizer & mini player
- **📚 Books** — Pembaca manga/PDF digital
- **☁️ Cloud Drive** — Penyimpanan file pribadi dengan RBAC
- **🕹️ Arcade** — Mini-game (Dino Run, Chess)

### Tech Stack Utama

| Komponen | Teknologi |
|----------|-----------|
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
