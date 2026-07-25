# MEeL-HUB — Media Hub Platform

<div align="center">
  <img src="assets/MEeL.png" alt="MEeL Logo" width="500"/>
</div>

**Platform media cloud terpadu untuk streaming video, musik, membaca buku digital, dan penyimpanan file pribadi.**

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.2%2B-003545?style=flat-square&logo=mariadb&logoColor=white)](https://mariadb.org/)
[![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-Self--hosted-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![FFmpeg](https://img.shields.io/badge/FFmpeg-6.0%2B-007808?style=flat-square&logo=ffmpeg&logoColor=white)](https://ffmpeg.org/)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)](LICENSE)
[![Maintenance](https://img.shields.io/badge/Maintained-Yes-22c55e?style=flat-square)](https://github.com/mifada2543/MEeL)
[![GitHub Stars](https://img.shields.io/github/stars/mifada2543/MEeL?style=social)](https://github.com/mifada2543/MEeL)
---

## 📖 Ikhtisar

**MEeL** adalah platform media hub pribadi berbasis PHP & MySQL yang berjalan di atas Apache (XAMPP/LAMPP). Platform ini menggabungkan modul **Video**, **Music**, **Books**, dan **Cloud Drive** ke dalam antarmuka web gelap bertema monospace yang modern. Sistem ini dilengkapi dengan:

- **Streaming HLS** (HTTP Live Streaming) adaptif
- **Transcoding otomatis** menggunakan FFmpeg
- **Integrasi yt-dlp** untuk download via URL
- **Manajemen file** berbasis peran (RBAC)
- **Mini-game arcade** interaktif (Dino Run, Snake, Chess)
- **Sistem keamanan** berlapis (CSRF, IP Banning, Session Management, Rate Limiting)
- **Audit Trail** aktivitas pengguna dengan admin viewer
- **Dashboard admin** dengan grafik aktivitas 7 hari

---

## ✨ Fitur Utama

### 🎬 Video (Streaming HLS)

| Fitur | Detail |
|-------|--------|
| **Streaming Adaptif** | HLS (`.m3u8` playlist + segment `.ts`) dengan fallback MP4 otomatis |
| **Player Kustom** | Berbasis Plyr.js dengan quality selector, subtitle, PiP, keyboard shortcuts |
| **Gesture Sentuh** | Double-tap kiri (rewind 5s), kanan (forward 5s), tengah (play/pause) |
| **Transisi Mulus** | Video berikutnya dimuat SPA-like tanpa reload, pertahankan fullscreen |
| **Resume Otomatis** | Posisi terakhir disimpan via `localStorage` |
| **Preview Thumbnail** | VTT sprite thumbnail pada seekbar |

### 🎵 Music (Audio Platform)

| Fitur | Detail |
|-------|--------|
| **Visualizer** | WebAudio API spectrum analyzer |
| **Mini Player** | Spotify-style persistent mini player |
| **Streaming** | MP3, FLAC, OGG/Opus, M4A |
| **Playlist** | Buat & kelola playlist kustom |
| **Smart Queue** | Antrean lagu dinamis dengan next/prev |

### 📚 Books (Digital Library)

- Pembaca buku digital (Manga/PDF) terintegrasi di browser
- Upload dengan generate thumbnail otomatis
- Manajemen metadata buku (judul, author, kategori)
- Support ZIP/CBZ untuk manga, PDF untuk e-book

### ☁️ Cloud Drive (Personal Cloud Storage)

| Fitur | Detail |
|-------|--------|
| **Dua Scope** | Public (semua member) & Private (per-member) |
| **Kuota Terbatas** | 20GB per member, unlimited untuk admin |
| **Filter Tipe** | Video, Audio, Dokumen (auto-detect) |
| **Preview In-Browser** | Video, audio, dan gambar bisa dipratinjau |
| **Validasi Magic Bytes** | Cegah file palsu dengan signature detection |

### 🕹️ Arcade (Mini Games)

- **Dino Run** — endless runner ala Chrome Dino dengan karakter Miku & Teto
- **Chess** — permainan catur klasik dengan mode multiplayer online (LAN)
- **Snake** — permainan Snake klasik yang nostalgia

### 🔧 Fungsionalitas Umum

| Fitur | Detail |
|-------|--------|
| **Dashboard Hub** | Statistik kapasitas disk & ringkasan media |
| **Transcoder** | Ekstrak audio dari video (MP3/OGG/M4A) |
| **Download URL** | yt-dlp + FFmpeg untuk download dari YouTube dll |
| **Komentar** | Nested comments pada video & musik |
| **Like/Dislike** | Interaksi sosial pada konten media |
| **Profil User** | Avatar, bio, statistik upload |
| **Mode Sehat 20-20-20** | Notifikasi istirahat mata tiap 20 menit |
| **Autoloader PSR-4** | Auto-loading class core (`MediaLibrary`, `Uploader`, dll.) tanpa require manual |
| **Migration System v1–v7** | Database schema versioning + auto-upgrade (FULLTEXT, FK, activity_log, UNIQUE KEY) |
| **Base URL Portability** | `base_url()` + `MEEL_BASE_URL` constant — path konsisten di semua subdirektori |
| **FULLTEXT Search** | Search video/music 10-100× lebih cepat via `MATCH AGAINST` (MySQL 5.7+) |
| **Admin Panel** | Dashboard monitoring, manajemen user, queue control, activity log viewer |
| **Role Helper** | `get_user_role()` — query role ter-cache, menghilangkan duplikasi di upload files |
| **Redirect Guard** | Validasi URL redirect cegah open redirect |
| **Activity Log Integration** | Audit trail login, logout, upload, admin actions — tabel `activity_log` |
| **Admin Activity Log Viewer** | Halaman `admin/activity_log.php` — filter, pagination, cleanup log |
| **API Rate Limiting** | Proteksi endpoint dari abuse (like: 30/menit, comment: 10/menit) |
| **Pagination Metadata** | UI menampilkan info halaman (`total_pages`, `from`, `to`) |
| **Admin Dashboard Charts** | Chart.js 7-Day Activity Chart — views, uploads, active users |

---

## 📸 Screenshots

### 🎬 Video Library
![Video Library](assets/img/video0.webp)

### 🎵 Music Discovery
![Music Discovery](assets/img/music0.webp)

> Sisanya menyusul

---

## 🛠️ Tech Stack

| Layer | Teknologi | Keterangan |
|-------|-----------|------------|
| **Backend** | PHP 8.0+ | Core logic & API endpoints |
| **Database** | MySQL 5.7+ / MariaDB 10.2+ | Relational storage & metadata |
| **Web Server** | Apache 2.4+ | `mod_rewrite` engine |
| **Styling** | TailwindCSS (Self-hosted, Purged) + Vanilla CSS | Dark-mode monospace theme |
| **Interaktivitas** | HTMX + Vanilla JavaScript | AJAX SPA-like tanpa reload |
| **Media Player** | Plyr.js + HLS.js | HLS video & audio playback |
| **Icons** | Lucide Icons | SVG icon library |
| **Transcoding** | FFmpeg 6.0+ & FFprobe | HLS segmentasi, kompresi, thumbnail |
| **Downloader** | yt-dlp (optional) | Download media dari URL eksternal |
| **Transliterasi** | PHP `intl` (Transliterator) | Pembersihan nama file (Romaji) |
| **Autoloader** | Manual PSR-4-like (`modules/autoload.php`) | Auto-loading 10+ class core |
| **Migration** | PHP-based (`database/migrate.php`) | Schema versioning v1–v7 (FULLTEXT, FK, activity_log) |
| **Rate Limiting** | `modules/RateLimiter.php` | File-based rate limiter (flock safety) |

---

## 📁 Struktur Proyek

```
MEeL/
├── admin/                 # Panel Admin (role admin only)
│   ├── index.php          # Dashboard with Chart.js activity chart
│   ├── activity_log.php   # Audit trail viewer
│   ├── edit-video.php     # Edit video metadata
│   └── edit-music.php     # Edit music metadata
├── arcade/                # Mini Games (Dino Run, Snake, Chess)
├── assets/                # Aset statis (CSS, JS, font, gambar)
├── auth/                  # Autentikasi & manajemen sesi
│   ├── config.php         # Konfigurasi database + path terpusat (MEEL_HDD_*)
│   └── config.example.php # Template konfigurasi
├── books/                 # Modul E-Book / Komik
├── controllers/           # API Actions & Event Handler (AJAX/HTMX)
│   ├── api/               # WatchController, like, comment, transcode
│   ├── admin/             # admin_actions, admin_data
│   └── profile/           # profile_edit, fun-manage
├── database/              # Skema database
│   ├── schema.sql         # File schema standalone (16 tabel)
│   └── migrate.php        # 🔄 Migration system v1–v7 (FULLTEXT, FK, activity_log)
├── data_drive/            # Cloud Drive storage runtime
├── docs/                  # Dokumentasi proyek
├── drive/                 # Modul Cloud Drive
│   ├── templates/         # Template rendering (file_grid.php)
│   └── DriveService.php   # OOP: DriveUserContext, DriveStorage, DriveViewRenderer
├── err/                   # Halaman error (denied, maintenance, banned, revoked)
├── modules/               # Core logic & business layer (OOP)
│   ├── autoload.php       # 🔄 Autoloader PSR-4-like (semua class core auto-load)
│   ├── core/              # Semua file core dipindah ke sini
│   │   ├── helpers.php    # Fungsi bantuan: base_url(), resolve_binary(), time_ago(), dll
│   │   ├── System.php     # Queue management & monitoring
│   │   ├── Transcoder.php # FFmpeg HLS & yt-dlp download engine
│   │   ├── Uploader.php   # Upload file & validasi
│   │   ├── GarbageCollector.php # Auto-cleanup temp files + rate limit cache
│   │   ├── RateLimiter.php # ⚡ File-based API rate limiter
│   │   ├── CommentRenderer.php # Render komentar nested
│   │   ├── activity_logger.php # Logging aktivitas & IP ban check
│   │   ├── japanese.php   # Analisis teks Jepang (MeCab/Romaji)
│   │   └── bootstrap.php  # Bootstrap error handling terpusat
│   ├── media/             # Media library classes
│   │   ├── MediaLibrary.php   # Query database, search, pagination metadata
│   │   ├── MediaViewer.php    # View tracking, komentar, rekomendasi
│   │   ├── MediaInteraction.php # Like/dislike
│   │   └── SearchEngine.php   # Mesin pencari FULLTEXT
│   ├── transcoder/        # Utilitas FFmpeg
│   │   └── FfmpegUtils.php    # FFmpeg trait – probe, sprite, VTT
│   └── exceptions/        # Custom exception classes
│       ├── TranscodeException.php
│       ├── ProcessException.php
│       └── DownloadException.php
├── music/                 # Modul pemutar musik
├── partials/              # Reusable UI components (navbar, footer, head, nav)
├── profile/               # Modul profil user
├── temp/                  # Runtime staging transcoding + rate limit cache
├── video/                 # Modul pemutar video
├── .htaccess              # Apache rewrite rules
├── index.php              # Homepage Hub / portal modul
├── introduction.php       # Panduan interaktif walkthrough
├── transcode.php          # Entry point transcoding video→audio
├── update.php             # Changelog & update log
└── upload_advanced.php    # Advanced upload via URL (yt-dlp)
```

> 📖 **Dokumentasi lengkap** tersedia dalam dua bahasa: [🇮🇩 Indonesia](docs/id/index.md) · [🇬🇧 English](docs/en/index.md)

---

## 📋 Persyaratan Sistem

### Minimum Requirements

| Komponen | Versi | Keterangan |
|----------|-------|------------|
| **PHP** | 8.0+ | Versi 8.0+ sangat disarankan |
| **MySQL** | 5.7+ / MariaDB 10.2+ | Skema mendukung encoding `utf8mb4` |
| **Apache** | 2.4+ | Wajib `mod_rewrite` aktif |
| **FFmpeg** | 6.0+ | Untuk HLS segmentasi & kompresi |
| **yt-dlp** | Versi terbaru | Untuk download media via URL |
| **RAM** | 2 GB+ | 4 GB+ direkomendasikan untuk transcoding |
| **Storage** | 10 GB+ | Tergantung ukuran media |

### PHP Extensions Wajib

```ini
extension=mysqli
extension=pdo_mysql
extension=gd
extension=fileinfo
extension=json
extension=mbstring
extension=intl      # Wajib untuk transliterasi karakter Jepang→Romaji
extension=zip       # Untuk ekstraksi file manga (ZIP/CBZ)
```

---

## 🚀 Instalasi Cepat

### 1. Kloning Repositori

```bash
cd /opt/lampp/htdocs
git clone https://github.com/mifada2543/MEeL.git MEeL
```

### 2. Setup Database

```bash
# Buat database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS MEeL DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# Import skema
mysql -u root -p MEeL < database/schema.sql
```

### 3. Konfigurasi Aplikasi

```bash
cd /opt/lampp/htdocs/MEeL/auth
cp config.example.php config.php
```

Edit `auth/config.php` dan isi kredensial database Anda.

### 4. Setup Direktori Runtime

```bash
cd /opt/lampp/htdocs/MEeL
mkdir -p data_drive/public data_drive/private_admins temp profile/upload \
         music/upload/file music/upload/thumbnail \
         books/upload/manga books/upload/pdf books/upload/thumbnail
sudo chown -R www-data:www-data data_drive temp profile/upload music/upload books/upload
sudo chmod -R 775 data_drive temp profile/upload music/upload books/upload
```

### 5. Aktifkan mod_rewrite Apache

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 6. Jalankan Migration Database

```bash
/opt/lampp/bin/php database/migrate.php
```

> ⚠️ **Default Login:** Username: `Admin` | Password: `Admin#123`

> 📖 **Instalasi detail** → [docs/id/installation.md](docs/id/installation.md) | [English](docs/en/installation.md)

---

## ⚙️ Konfigurasi

### File Konfigurasi Utama

| File | Keperluan |
|------|-----------|
| `auth/config.php` | Database, session, CSRF, **path terpusat (`MEEL_HDD_*`)** |
| `auth/config.example.php` | Template konfigurasi (copy ke config.php) |
| `database/schema.sql` | Skema database standalone |
| `modules/Transcoder.php` | FFmpeg, yt-dlp, CPU threads |
| `modules/Uploader.php` | Upload file, FFmpeg |
| `modules/helpers.php` | HDD check path (dari `MEEL_HDD_BASE`) |
| `modules/System.php` | Queue & rate limit config |
| `modules/RateLimiter.php` | **Baru!** API rate limiter — per-endpoint limits |

### Konfigurasi Path Terpusat

```php
// auth/config.php — ★ Cukup ubah 1 baris ini
define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');

// Semua modul otomatis mengikuti:
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');
```

### Base URL Portability

```php
// auth/config.php — Auto-detected dari __DIR__, bisa dioverride
define('MEEL_BASE_URL', '/MEeL'); // Contoh jika di subdirektori

// Di view/pages:
// Otomatis konsisten, tidak peduli dari mana file di-include
$url = base_url('/assets/css/style.css'); // → /MEeL/assets/css/style.css
```

### Migration System

```bash
# Upgrade database ke versi terbaru (v1–v7)
/opt/lampp/bin/php database/migrate.php
```

**Riwayat Migration:**
| Versi | Perubahan |
|-------|-----------|
| **v1** | FULLTEXT index untuk search video, music, books |
| **v2** | Performance index (upload_date) untuk sorting |
| **v3** | Sinkronisasi struktural |
| **v4** | Foreign key constraints (upload_queue, drive_files → users) |
| **v5** | title VARCHAR → TEXT |
| **v6** | activity_log table untuk audit trail |
| **v7** | UNIQUE INDEX on users.username |

Migration bersifat **idempotent** — aman dijalankan berulang kali.

> 📖 **Konfigurasi lengkap** → [docs/id/configuration.md](docs/id/configuration.md) | [English](docs/en/configuration.md)

---

## 👥 Role-Based Access Control

| Role | Hak Akses |
|------|-----------|
| **Admin** | Kontrol penuh: semua modul, admin panel, upload advanced, transcode, manajemen user, IP banning, activity log viewer |
| **Member** | Semua media, komentar, like/dislike, books, Cloud Drive pribadi (quota 20GB) |
| **User** | Semua media, komentar, like/dislike, books (tanpa Cloud Drive) |
| **Guest** | Terbatas: hanya nonton/dengar tanpa interaksi |

---

## 📚 Dokumentasi Lengkap

Dokumentasi proyek tersedia dalam dua bahasa:

**🇮🇩 Bahasa Indonesia:** [`docs/id/`](docs/id/index.md)
**🇬🇧 English:** [`docs/en/`](docs/en/index.md)

| Dokumen | 🇮🇩 ID | 🇬🇧 EN |
|---------|:-----:|:-----:|
| 📖 Index Dokumentasi | [🇮🇩](docs/id/index.md) | [🇬🇧](docs/en/index.md) |
| 🚀 Instalasi | [🇮🇩](docs/id/installation.md) | [🇬🇧](docs/en/installation.md) |
| ⚙️ Konfigurasi | [🇮🇩](docs/id/configuration.md) | [🇬🇧](docs/en/configuration.md) |
| 🏗️ Modul & Arsitektur | [🇮🇩](docs/id/modules.md) | [🇬🇧](docs/en/modules.md) |
| 🔌 API & Controller | [🇮🇩](docs/id/api.md) | [🇬🇧](docs/en/api.md) |
| 🔒 Keamanan | [🇮🇩](docs/id/security.md) | [🇬🇧](docs/en/security.md) |
| 🌍 Problem Solved | [🇮🇩](docs/id/problem-solved.md) | [🇬🇧](docs/en/problem-solved.md) |
| 🔧 Troubleshooting | [🇮🇩](docs/id/troubleshooting.md) | [🇬🇧](docs/en/troubleshooting.md) |
| 👨‍💻 Development | [🇮🇩](docs/id/development.md) | [🇬🇧](docs/en/development.md) |
| 📥 Advanced Upload | [🇮🇩](docs/id/upload_issue.md) | [🇬🇧](docs/en/upload_issue.md) |
| 📋 Analisis Proyek | [🇮🇩](docs/id/deskripsi.md) | [🇬🇧](docs/en/analysis.md) |

---

## 📄 Lisensi

Proyek ini dilisensikan di bawah **GNU General Public License v3.0 (GPLv3)**.

```
✅ Anda bebas untuk:
   • Menggunakan, menyalin, dan mendistribusikan perangkat lunak ini
   • Memodifikasi dan membuat karya turunan
   • Menggunakannya untuk keperluan komersial
   • Menjalankan untuk keperluan pribadi, pendidikan, atau publik

⚠️ Kewajiban (Copyleft):
   • Anda harus menyertakan lisensi GPLv3 yang sama pada distribusi ulang
   • Anda harus menyertakan kode sumber jika Anda mendistribusikan secara publik
   • Anda harus mencantumkan perubahan yang dibuat
   • Lisensi ini bersifat "viral" — karya turunan harus tetap GPLv3
```

> © 2026 Mifada. Beberapa hak dilindungi. Lihat [LICENSE](LICENSE) untuk detail.

---

## Q&A

### Q: Kenapa belum ada versi docker?

> A: Karena proyek ini masih dalam tahap **pengembangan** dan **debugging**, jadi docker masih kurang relevan untuk proyek ini.

### Q: Kenapa absolut path?

> A: Lebih mudah dalam mengkonfigurasi jika anda menggunakan media eksternal seperti HDD (mengurangi memori system penuh).

### Q: Ukuran MEeL?

> A: 77MB untuk source codenya, 1-2GB untuk env (ffmpeg, yt-dlp, apache, MariaDB, php, dsb).

### Q: System Requirement?

> A: CPU 2 Core 2GHz cukup, GPU optional karena seluruh process bergantung pada CPU (anda dapat konfigurasi ulang dibagian codec jika ingin menggunakan accelerate GPU untuk transcoding), RAM 2GB cukup namun saran 4GB untuk membantu transcoding, ROM disesuaikan saja, OS ubuntu server, intinya linux dan asal ada env nya itu bisa pakai MEeL.

---

### ⚠️ Pernyataan Penting / Disclaimer

> [!IMPORTANT]
> **Catatan Hukum**: Pembuat (Mifada) tidak bertanggung jawab dan tidak terlibat atas segala jenis berkas media yang diunggah, disimpan, atau disebarluaskan oleh pihak ketiga yang menggunakan atau memodifikasi kode MEeL-HUB ini. Seluruh risiko penggunaan dan kepatuhan hak cipta kembali ke tanggung jawab masing-masing pengguna.

> 🌐 **Domain Status:**
> * **EN:** The public demo domain may occasionally be unavailable because it runs directly on the developer's local device.
> * **ID:** Domain demo publik terkadang tidak berfungsi karena sistem berjalan langsung di perangkat lokal milik developer.

**Kontak:** `mifada2543@gmail.com` · [github.com/mifada2543](https://github.com/mifada2543)

---

<div align="center">
  <strong>MEeL</strong> © 2026 — Mifada<br>
  <sub>Dibuat dengan ❤️ untuk streaming media pribadi</sub>
</div>
