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

**MEeL** is a personal media hub platform built with PHP & MySQL running on Apache (XAMPP/LAMPP). It combines **Video**, **Music**, **Books**, and **Cloud Drive** modules into a modern dark-themed monospace web interface.

Features include:
- **HLS Adaptive Streaming** (HTTP Live Streaming)
- **Automatic Transcoding** using FFmpeg
- **yt-dlp Integration** for URL downloads
- **Role-Based Access Control** (RBAC)
- **Interactive Arcade** mini-games (Dino Run, Snake, Chess)
- **Layered Security** (CSRF, IP Banning, Session Management, Rate Limiting)
- **Audit Trail** with admin activity log viewer
- **Admin Dashboard** with 7-day activity charts

---

## ✨ Key Features

### 🎬 Video (HLS Streaming)
- Adaptive HLS streaming (.m3u8 + .ts) with MP4 fallback
- Plyr.js-based player with quality selector, subtitles, PiP
- Touch gestures: double-tap left (rewind 5s), right (forward 5s)
- Auto-resume via localStorage
- VTT sprite thumbnail preview on seekbar

### 🎵 Music (Audio Platform)
- WebAudio API spectrum analyzer visualizer
- Spotify-style persistent mini player
- Supports MP3, FLAC, OGG/Opus, M4A
- Custom playlist creation & management
- Dynamic smart queue with next/prev navigation

### 📚 Books (Digital Library)
- In-browser manga/PDF reader
- Auto thumbnail generation on upload
- ZIP/CBZ support for manga, PDF for e-books

### ☁️ Cloud Drive (Personal Storage)
- Public (all members) & Private (per-member) scopes
- 20GB quota per member, unlimited for admin
- Auto-type detection (Video, Audio, Documents)
- In-browser preview for video, audio, and images
- Magic byte validation — prevents fake files

### 🕹️ Arcade (Mini Games)
- **Dino Run** — endless runner with Miku & Teto characters
- **Chess** — classic chess with online multiplayer (LAN)
- **Snake** — nostalgic classic Snake game

### 🔧 General Functionality
- **Admin Dashboard** — disk stats, media summary, 7-day activity chart
- **Transcoder** — extract audio from video (MP3/OGG/M4A)
- **URL Downloader** — yt-dlp + FFmpeg
- **Nested Comments** on video & music
- **Like/Dislike** with rate limiting
- **User Profiles** — avatar, bio, upload stats
- **PSR-4 Autoloader** — auto-loads core classes
- **Database Migration v1–v7** — FULLTEXT, FK, activity_log, UNIQUE KEY
- **FULLTEXT Search** — 10-100× faster via MATCH AGAINST
- **Activity Log Integration** — audit trail for login, logout, upload, admin actions
- **API Rate Limiting** — 30 likes/min, 10 comments/min
- **Pagination Metadata** — total_pages, from, to

---

## 🛠️ Tech Stack

| Layer | Technology | Notes |
|-------|-----------|------------|
| **Backend** | PHP 8.0+ | Core logic & API endpoints |
| **Database** | MySQL 5.7+ / MariaDB 10.2+ | Relational storage |
| **Web Server** | Apache 2.4+ | `mod_rewrite` engine |
| **Styling** | TailwindCSS (Self-hosted, Purged) | Dark monospace theme |
| **Interactivity** | HTMX + Vanilla JavaScript | AJAX without page reload |
| **Media Player** | Plyr.js + HLS.js | HLS video & audio |
| **Icons** | Lucide Icons | SVG icon library |
| **Transcoding** | FFmpeg 6.0+ & FFprobe | HLS, compression, thumbnails |
| **Downloader** | yt-dlp (optional) | External URL downloads |
| **Rate Limiting** | `modules/RateLimiter.php` | File-based, flock safety |

---

## 📋 System Requirements

| Component | Version | Notes |
|----------|-------|------------|
| **PHP** | 8.0+ | 8.1+ recommended |
| **MySQL** | 5.7+ / MariaDB 10.2+ | utf8mb4 encoding |
| **Apache** | 2.4+ | Requires `mod_rewrite` |
| **FFmpeg** | 6.0+ | HLS & transcoding |
| **yt-dlp** | Latest | URL downloads |
| **RAM** | 2 GB (4 GB+ for transcoding) | |
| **Storage** | 10 GB+ | Depends on media |

---

## 🚀 Quick Install

```bash
# 1. Clone
cd /opt/lampp/htdocs
git clone https://github.com/mifada2543/MEeL.git MEeL

# 2. Database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS MEeL CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u root -p MEeL < database/schema.sql

# 3. Configure
cd MEeL/auth
cp config.example.php config.php
# Edit config.php with your database credentials

# 4. Runtime directories
mkdir -p data_drive/public data_drive/private_admins temp profile/upload
sudo chown -R www-data:www-data data_drive temp profile/upload
sudo chmod -R 775 data_drive temp profile/upload

# 5. Run migrations
/opt/lampp/bin/php database/migrate.php
```

> ⚠️ **Default Login:** Username: `Admin` | Password: `Admin#123`

> 📖 **Full installation guide** → [docs/en/installation.md](docs/en/installation.md)

---

## 👥 Role-Based Access Control

| Role | Access Rights |
|------|-----------|
| **Admin** | Full control: all modules, admin panel, user management, IP banning, activity log viewer |
| **Member** | All media, comments, like/dislike, books, Cloud Drive (20GB quota) |
| **User** | All media, comments, like/dislike, books (no Cloud Drive) |
| **Guest** | Limited: watch/listen only, no interaction |

---

## 📚 Documentation

Documentation is available in two languages:

**🇮🇩 Indonesian:** [docs/id/index.md](docs/id/index.md)
**🇬🇧 English:** [docs/en/index.md](docs/en/index.md)

| Document | Indonesian | English |
|---------|-----------|---------|
| Documentation Index | [🇮🇩](docs/id/index.md) | [🇬🇧](docs/en/index.md) |
| Installation | [🇮🇩](docs/id/installation.md) | [🇬🇧](docs/en/installation.md) |
| Configuration | [🇮🇩](docs/id/configuration.md) | [🇬🇧](docs/en/configuration.md) |
| Modules & Architecture | [🇮🇩](docs/id/modules.md) | [🇬🇧](docs/en/modules.md) |
| API & Controller | [🇮🇩](docs/id/api.md) | [🇬🇧](docs/en/api.md) |
| Security | [🇮🇩](docs/id/security.md) | [🇬🇧](docs/en/security.md) |
| Development Guide | [🇮🇩](docs/id/development.md) | [🇬🇧](docs/en/development.md) |
| Troubleshooting | [🇮🇩](docs/id/troubleshooting.md) | [🇬🇧](docs/en/troubleshooting.md) |
| Problem Solved | [🇮🇩](docs/id/problem-solved.md) | [🇬🇧](docs/en/problem-solved.md) |
| Advanced Upload | [🇮🇩](docs/id/upload_issue.md) | [🇬🇧](docs/en/upload_issue.md) |
| Project Analysis | [🇮🇩](docs/id/deskripsi.md) | [🇬🇧](docs/en/analysis.md) |

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

> A: 2-core 2GHz CPU is sufficient, GPU is optional (all processing is CPU-based, you can configure codec for GPU acceleration), 2GB RAM is enough but 4GB recommended for transcoding.

---

### ⚠️ Disclaimer

> [!IMPORTANT]
> **Legal Notice**: The creator (Mifada) is not responsible for and not involved in any media files uploaded, stored, or distributed by third parties using or modifying the MEeL-HUB code. All usage risks and copyright compliance are the responsibility of each user.

> 🌐 **Domain Status:**
> * The public demo domain may occasionally be unavailable because it runs directly on the developer's local device.

**Contact:** `mifada2543@gmail.com` · [github.com/mifada2543](https://github.com/mifada2543)

---

<div align="center">
  <strong>MEeL</strong> © 2026 — Mifada<br>
  <sub>Made with ❤️ for personal media streaming</sub>
</div>
