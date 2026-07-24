# MEeL-HUB — Media Hub Platform

<div align="center">
  <img src="assets/MEeL.png" alt="MEeL Logo" width="500"/>
</div>

**An integrated media cloud platform for video streaming, music, digital books, and personal file storage.**

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.2%2B-003545?style=flat-square&logo=mariadb&logoColor=white)](https://mariadb.org/)
[![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-Self--hosted-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![FFmpeg](https://img.shields.io/badge/FFmpeg-6.0%2B-007808?style=flat-square&logo=ffmpeg&logoColor=white)](https://ffmpeg.org/)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)](LICENSE)
[![Maintenance](https://img.shields.io/badge/Maintained-Yes-22c55e?style=flat-square)](https://github.com/mifada2543/MEeL)
[![GitHub Stars](https://img.shields.io/github/stars/mifada2543/MEeL?style=social)](https://github.com/mifada2543/MEeL)
---

## 📖 Overview

**MEeL** is a personal media hub platform built with PHP & MySQL running on Apache (XAMPP/LAMPP). The platform combines **Video**, **Music**, **Books**, and **Cloud Drive** modules into a modern dark-themed monospace web interface. Key features include:

- **HLS Adaptive Streaming** (HTTP Live Streaming)
- **Automatic Transcoding** using FFmpeg
- **yt-dlp Integration** for external URL downloads
- **Role-Based Access Control** (RBAC)
- **Interactive Arcade** mini-games (Dino Run, Snake, Chess)
- **Layered Security** (CSRF, IP Banning, Session Management, Rate Limiting)
- **Audit Trail** with admin activity log viewer
- **Admin Dashboard** with 7-day activity charts

---

## ✨ Key Features

### 🎬 Video (HLS Streaming)

| Feature | Detail |
|---------|--------|
| **Adaptive Streaming** | HLS (`.m3u8` playlist + `.ts` segments) with automatic MP4 fallback |
| **Custom Player** | Based on Plyr.js with quality selector, subtitles, PiP, keyboard shortcuts |
| **Touch Gestures** | Double-tap left (rewind 5s), right (forward 5s), center (play/pause) |
| **Smooth Transitions** | Next video loads SPA-like without page reload, preserves fullscreen |
| **Auto Resume** | Last position saved via `localStorage` |
| **Thumbnail Preview** | VTT sprite thumbnail on seekbar |

### 🎵 Music (Audio Platform)

| Feature | Detail |
|---------|--------|
| **Visualizer** | WebAudio API spectrum analyzer |
| **Mini Player** | Spotify-style persistent mini player |
| **Streaming** | MP3, FLAC, OGG/Opus, M4A |
| **Playlist** | Create & manage custom playlists |
| **Smart Queue** | Dynamic song queue with next/prev |

### 📚 Books (Digital Library)

- In-browser digital book reader (Manga/PDF)
- Auto thumbnail generation on upload
- Book metadata management (title, author, category)
- ZIP/CBZ support for manga, PDF for e-books

### ☁️ Cloud Drive (Personal Cloud Storage)

| Feature | Detail |
|---------|--------|
| **Two Scopes** | Public (all members) & Private (per-member) |
| **Quota Limit** | 20GB per member, unlimited for admin |
| **Type Filter** | Video, Audio, Documents (auto-detect) |
| **In-Browser Preview** | Video, audio, and images can be previewed |
| **Magic Byte Validation** | Prevents fake files with signature detection |

### 🕹️ Arcade (Mini Games)

- **Dino Run** — endless runner inspired by Chrome Dino with Miku & Teto characters
- **Chess** — classic chess with online multiplayer mode (LAN)
- **Snake** — nostalgic classic Snake game

### 🔧 General Functionality

| Feature | Detail |
|---------|--------|
| **Hub Dashboard** | Disk capacity stats & media summary |
| **Transcoder** | Extract audio from video (MP3/OGG/M4A) |
| **URL Downloader** | yt-dlp + FFmpeg for downloads from YouTube and others |
| **Comments** | Nested comments on video & music |
| **Like/Dislike** | Social interaction on media content |
| **User Profiles** | Avatar, bio, upload statistics |
| **20-20-20 Eye Care** | Eye rest notifications every 20 minutes |
| **PSR-4 Autoloader** | Auto-loading core classes (`MediaLibrary`, `Uploader`, etc.) without manual require |
| **Migration System v1–v7** | Database schema versioning + auto-upgrade (FULLTEXT, FK, activity_log, UNIQUE KEY) |
| **Base URL Portability** | `base_url()` + `MEEL_BASE_URL` constant — consistent paths across all subdirectories |
| **FULLTEXT Search** | Search video/music 10-100× faster via `MATCH AGAINST` (MySQL 5.7+) |
| **Admin Panel** | Dashboard monitoring, user management, queue control, activity log viewer |
| **Role Helper** | `get_user_role()` — cached role query, eliminating duplication in upload files |
| **Redirect Guard** | URL redirect validation to prevent open redirect |
| **Activity Log Integration** | Audit trail for login, logout, upload, admin actions — `activity_log` table |
| **Admin Activity Log Viewer** | `admin/activity_log.php` page — filter, pagination, log cleanup |
| **API Rate Limiting** | Endpoint protection from abuse (likes: 30/min, comments: 10/min) |
| **Pagination Metadata** | UI displays page info (`total_pages`, `from`, `to`) |
| **Admin Dashboard Charts** | Chart.js 7-Day Activity Chart — views, uploads, active users |

---

## 📸 Screenshots

### 🎬 Video Library
![Video Library](assets/img/video0.webp)

### 🎵 Music Discovery
![Music Discovery](assets/img/music0.webp)

> More coming soon

---

## 🛠️ Tech Stack

| Layer | Technology | Notes |
|-------|-----------|-------|
| **Backend** | PHP 8.0+ | Core logic & API endpoints |
| **Database** | MySQL 5.7+ / MariaDB 10.2+ | Relational storage & metadata |
| **Web Server** | Apache 2.4+ | `mod_rewrite` engine |
| **Styling** | TailwindCSS (Self-hosted, Purged) + Vanilla CSS | Dark-mode monospace theme |
| **Interactivity** | HTMX + Vanilla JavaScript | AJAX SPA-like without page reload |
| **Media Player** | Plyr.js + HLS.js | HLS video & audio playback |
| **Icons** | Lucide Icons | SVG icon library |
| **Transcoding** | FFmpeg 6.0+ & FFprobe | HLS segmentation, compression, thumbnails |
| **Downloader** | yt-dlp (optional) | External media URL downloads |
| **Transliteration** | PHP `intl` (Transliterator) | File name sanitization (Romaji) |
| **Autoloader** | Manual PSR-4-like (`modules/autoload.php`) | Auto-loads 10+ core classes |
| **Migration** | PHP-based (`database/migrate.php`) | Schema versioning v1–v7 (FULLTEXT, FK, activity_log) |
| **Rate Limiting** | `modules/core/RateLimiter.php` | File-based rate limiter (flock safety) |

---

## 📁 Project Structure

```
MEeL/
├── admin/                 # Admin Panel (admin role only)
│   ├── index.php          # Dashboard with Chart.js activity chart
│   ├── activity_log.php   # Audit trail viewer
│   ├── edit-video.php     # Edit video metadata
│   └── edit-music.php     # Edit music metadata
├── arcade/                # Mini Games (Dino Run, Snake, Chess)
├── assets/                # Static assets (CSS, JS, fonts, images)
├── auth/                  # Authentication & session management
│   ├── config.php         # Centralized database config + paths (MEEL_HDD_*)
│   └── config.example.php # Configuration template
├── books/                 # E-Book / Comic module
├── controllers/           # API Actions & Event Handlers (AJAX/HTMX)
│   ├── api/               # WatchController, like, comment, transcode
│   ├── admin/             # admin_actions, admin_data
│   └── profile/           # profile_edit, fun-manage
├── database/              # Database schema
│   ├── schema.sql         # Standalone schema file (16 tables)
│   └── migrate.php        # 🔄 Migration system v1–v7 (FULLTEXT, FK, activity_log)
├── data_drive/            # Cloud Drive runtime storage
├── docs/                  # Project documentation
├── drive/                 # Cloud Drive module
│   ├── templates/         # Template rendering (file_grid.php)
│   └── DriveService.php   # OOP: DriveUserContext, DriveStorage, DriveViewRenderer
├── err/                   # Error pages (denied, maintenance, banned, revoked)
├── modules/               # Core logic & business layer (OOP)
│   ├── core/              # All core modules (moved from modules/ root)
│   │   ├── helpers.php    # Helper functions: base_url(), resolve_binary(), time_ago(), etc.
│   │   ├── System.php     # Queue management & monitoring
│   │   ├── Transcoder.php # FFmpeg HLS & yt-dlp download engine
│   │   ├── Uploader.php   # File upload & validation
│   │   ├── GarbageCollector.php # Auto-cleanup temp files + rate limit cache
│   │   ├── RateLimiter.php # ⚡ File-based API rate limiter
│   │   ├── CommentRenderer.php # Nested comment rendering
│   │   ├── activity_logger.php # Activity logging & IP ban check
│   │   ├── japanese.php   # Japanese text analysis (MeCab/Romaji)
│   │   └── bootstrap.php  # Centralized error handling bootstrap
│   ├── autoload.php       # 🔄 PSR-4-like autoloader (all core classes auto-load)
│   ├── media/             # Media library classes
│   │   ├── MediaLibrary.php   # Database queries, search, pagination metadata
│   │   ├── MediaViewer.php    # View tracking, comments, recommendations
│   │   ├── MediaInteraction.php # Like/dislike
│   │   └── SearchEngine.php   # FULLTEXT search engine
│   ├── transcoder/        # FFmpeg utilities
│   │   └── FfmpegUtils.php    # FFmpeg trait – probe, sprite, VTT
│   └── exceptions/        # Custom exception classes
│       ├── TranscodeException.php
│       ├── ProcessException.php
│       └── DownloadException.php
├── music/                 # Music player module
├── partials/              # Reusable UI components (navbar, footer, head, nav)
├── profile/               # User profile module
├── temp/                  # Runtime staging transcoding + rate limit cache
├── video/                 # Video player module
├── .htaccess              # Apache rewrite rules
├── index.php              # Homepage Hub / module portal
├── introduction.php       # Interactive walkthrough guide
├── transcode.php          # Video→audio transcoding entry point
├── update.php             # Changelog & update log
└── upload_advanced.php    # Advanced upload via URL (yt-dlp)
```

> 📖 **Full documentation** available in two languages: [🇮🇩 Indonesia](docs/id/index.md) · [🇬🇧 English](docs/en/index.md)

---

## 📋 System Requirements

### Minimum Requirements

| Component | Version | Notes |
|-----------|---------|-------|
| **PHP** | 8.0+ | PHP 8.0+ strongly recommended |
| **MySQL** | 5.7+ / MariaDB 10.2+ | Schema supports `utf8mb4` encoding |
| **Apache** | 2.4+ | Requires `mod_rewrite` enabled |
| **FFmpeg** | 6.0+ | For HLS segmentation & compression |
| **yt-dlp** | Latest | For URL media downloads |
| **RAM** | 2 GB+ | 4 GB+ recommended for transcoding |
| **Storage** | 10 GB+ | Depends on media size |

### Required PHP Extensions

```ini
extension=mysqli
extension=pdo_mysql
extension=gd
extension=fileinfo
extension=json
extension=mbstring
extension=intl      # Required for Japanese→Romaji transliteration
extension=zip       # For manga file extraction (ZIP/CBZ)
```

---

## 🚀 Quick Install

### 1. Clone Repository

```bash
cd /opt/lampp/htdocs
git clone https://github.com/mifada2543/MEeL.git MEeL
```

### 2. Setup Database

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS MEeL DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# Import schema
mysql -u root -p MEeL < database/schema.sql
```

### 3. Configure Application

```bash
cd /opt/lampp/htdocs/MEeL/auth
cp config.example.php config.php
```

Edit `auth/config.php` and fill in your database credentials.

### 4. Setup Runtime Directories

```bash
cd /opt/lampp/htdocs/MEeL
mkdir -p data_drive/public data_drive/private_admins temp profile/upload \
         music/upload/file music/upload/thumbnail \
         books/upload/manga books/upload/pdf books/upload/thumbnail
sudo chown -R www-data:www-data data_drive temp profile/upload music/upload books/upload
sudo chmod -R 775 data_drive temp profile/upload music/upload books/upload
```

### 5. Enable Apache mod_rewrite

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### 6. Run Database Migration

```bash
/opt/lampp/bin/php database/migrate.php
```

> ⚠️ **Default Login:** Username: `Admin` | Password: `Admin#123`

> 📖 **Full installation guide** → [docs/en/installation.md](docs/en/installation.md)

---

## ⚙️ Configuration

### Main Configuration Files

| File | Purpose |
|------|---------|
| `auth/config.php` | Database, session, CSRF, **centralized paths (`MEEL_HDD_*`)** |
| `auth/config.example.php` | Configuration template (copy to config.php) |
| `database/schema.sql` | Standalone database schema |
| `modules/core/Transcoder.php` | FFmpeg, yt-dlp, CPU threads |
| `modules/core/Uploader.php` | File upload, FFmpeg |
| `modules/core/helpers.php` | HDD check paths (from `MEEL_HDD_BASE`) |
| `modules/core/System.php` | Queue & rate limit config |
| `modules/core/RateLimiter.php` | API rate limiter — per-endpoint limits |

### Centralized Path Configuration

```php
// auth/config.php — ★ Only change this 1 line
define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');

// All modules automatically follow:
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');
```

### Base URL Portability

```php
// auth/config.php — Auto-detected from __DIR__, can be overridden
define('MEEL_BASE_URL', '/MEeL'); // Example if in subdirectory

// In views/pages:
// Automatically consistent, regardless of where the file is included from
$url = base_url('/assets/css/style.css'); // → /MEeL/assets/css/style.css
```

### Binary Path Configuration

```php
// auth/config.php — Set absolute paths to prevent binary-hijacking
define('MEEL_FFMPEG_PATH', '/usr/bin/ffmpeg');
define('MEEL_FFPROBE_PATH', '/usr/bin/ffprobe');
define('MEEL_NODE_PATH', '/usr/bin/node');
define('MEEL_YTDLP_PATH', '/usr/local/bin/yt-dlp');
```

### Migration System

```bash
# Upgrade database to latest version (v1–v7)
/opt/lampp/bin/php database/migrate.php
```

**Migration History:**
| Version | Changes |
|---------|---------|
| **v1** | FULLTEXT index for search on video, music, books |
| **v2** | Performance index (upload_date) for sorting |
| **v3** | Structural synchronization |
| **v4** | Foreign key constraints (upload_queue, drive_files → users) |
| **v5** | title VARCHAR → TEXT |
| **v6** | activity_log table for audit trail |
| **v7** | UNIQUE INDEX on users.username |

Migrations are **idempotent** — safe to run repeatedly.

> 📖 **Full configuration guide** → [docs/en/configuration.md](docs/en/configuration.md)

---

## 👥 Role-Based Access Control

| Role | Access Rights |
|------|---------------|
| **Admin** | Full control: all modules, admin panel, advanced upload, transcode, user management, IP banning, activity log viewer |
| **Member** | All media, comments, like/dislike, books, personal Cloud Drive (20GB quota) |
| **User** | All media, comments, like/dislike, books (no Cloud Drive) |
| **Guest** | Limited: only watch/listen without interaction |

---

## 📚 Documentation

Documentation is available in two languages:

**🇮🇩 Indonesian:** [`docs/id/`](docs/id/index.md)
**🇬🇧 English:** [`docs/en/`](docs/en/index.md)

| Document | 🇮🇩 ID | 🇬🇧 EN |
|----------|:-----:|:-----:|
| 📖 Documentation Index | [🇮🇩](docs/id/index.md) | [🇬🇧](docs/en/index.md) |
| 🚀 Installation | [🇮🇩](docs/id/installation.md) | [🇬🇧](docs/en/installation.md) |
| ⚙️ Configuration | [🇮🇩](docs/id/configuration.md) | [🇬🇧](docs/en/configuration.md) |
| 🏗️ Modules & Architecture | [🇮🇩](docs/id/modules.md) | [🇬🇧](docs/en/modules.md) |
| 🔌 API & Controller | [🇮🇩](docs/id/api.md) | [🇬🇧](docs/en/api.md) |
| 🔒 Security | [🇮🇩](docs/id/security.md) | [🇬🇧](docs/en/security.md) |
| 🌍 Problem Solved | [🇮🇩](docs/id/problem-solved.md) | [🇬🇧](docs/en/problem-solved.md) |
| 🔧 Troubleshooting | [🇮🇩](docs/id/troubleshooting.md) | [🇬🇧](docs/en/troubleshooting.md) |
| 👨‍💻 Development | [🇮🇩](docs/id/development.md) | [🇬🇧](docs/en/development.md) |
| 📥 Advanced Upload | [🇮🇩](docs/id/upload_issue.md) | [🇬🇧](docs/en/upload_issue.md) |
| 📋 Project Analysis | [🇮🇩](docs/id/deskripsi.md) | [🇬🇧](docs/en/analysis.md) |

---

## 📄 License

This project is licensed under the **GNU General Public License v3.0 (GPLv3)**.

```
✅ You are free to:
   • Use, copy, and distribute this software
   • Modify and create derivative works
   • Use it for commercial purposes
   • Run it for personal, educational, or public use

⚠️ Obligations (Copyleft):
   • You must include the same GPLv3 license on redistribution
   • You must include source code if you distribute publicly
   • You must document changes made
   • This license is "viral" — derivative works must remain GPLv3
```

> © 2026 Mifada. Some rights reserved. See [LICENSE](LICENSE) for details.

---

## Q&A

### Q: Why no Docker version yet?

> A: The project is still in **development** and **debugging** phase, so Docker is not yet relevant.

### Q: Why absolute paths?

> A: Easier configuration when using external media like HDD (reduces system memory usage).

### Q: MEeL size?

> A: ~77MB for source code, 1-2GB for environment (FFmpeg, yt-dlp, Apache, MariaDB, PHP, etc.).

### Q: System requirements?

> A: 2-core 2GHz CPU is sufficient, GPU is optional (all processing is CPU-based; you can reconfigure codec settings for GPU acceleration if desired). 2GB RAM is sufficient, but 4GB is recommended for transcoding. Storage depends on your needs. Works on Linux servers (Ubuntu recommended).

---

### ⚠️ Disclaimer

> [!IMPORTANT]
> **Legal Notice**: The creator (Mifada) is not responsible for and not involved in any media files uploaded, stored, or distributed by third parties using or modifying the MEeL-HUB code. All usage risks and copyright compliance are the responsibility of each user.

> 🌐 **Domain Status:**
> * The public demo domain may occasionally be unavailable because it runs directly on the developer's local device.

**Contact:** `mifada2543@gmail.com` · [github.com/mifada2543](https://github.com/mifada2543)

*Last synced with README.md: July 24, 2026*

---

<div align="center">
  <strong>MEeL</strong> © 2026 — Mifada<br>
  <sub>Made with ❤️ for personal media streaming</sub>
</div>
