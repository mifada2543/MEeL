# 📚 MEeL-HUB Documentation

Welcome to the official **MEeL** documentation — A Personal Media Hub Platform for video streaming, music, digital books, and cloud storage.

---

## 📋 Documentation Map

| # | Document | Description |
|---|---------|-----------|
| 1 | [🚀 Installation](installation.md) | Complete installation guide from scratch |
| 2 | [⚙️ Configuration](configuration.md) | Reference for all config files and parameters |
| 3 | [🏗️ Modules & Architecture](modules.md) | Deep dive into every module and class |
| 4 | [🔌 API & Controller](api.md) | Documentation for all AJAX/HTMX endpoints |
| 5 | [🔒 Security](security.md) | Security system, RBAC, CSRF, IP Banning, Rate Limiting |
| 6 | [🌍 Problem Solved](problem-solved.md) | Real-world problems that inspired MEeL |
| 7 | [🔧 Troubleshooting](troubleshooting.md) | Solutions for common issues |
| 8 | [👨‍💻 Development Guide](development.md) | Coding standards, contributions, and testing |
| 9 | [📥 Advanced Upload Issues](upload_issue.md) | Handling yt-dlp & background queue problems |

---

## 📦 Complete Module List

| Module | File | Description |
|-------|------|-----------|
| **Exception Classes** | `modules/exceptions/*.php` | 3 specific exception classes: ProcessException, DownloadException, TranscodeException |
| **CommentRenderer** | `modules/CommentRenderer.php` | Comment rendering with theme support (`video`/`music`) |
| **SearchEngine** | `modules/media/SearchEngine.php` | FULLTEXT search engine for video & music |
| **GarbageCollector** | `modules/GarbageCollector.php` | Auto-cleanup of temporary files, guest accounts & expired rate limit cache |
| **WatchController** | `controllers/api/WatchController.php` | Combined Video + Music watch pages controller |
| **UpdateManager** | `controllers/system/UpdateManager.php` | CRUD changelog entries (OOP) |
| **DriveService** | `drive/DriveService.php` | 3 classes: DriveUserContext, DriveStorage, DriveViewRenderer |
| **Profile Manager** | `controllers/profile/fun-manage.php` | Delete media, pending deletions, cleanup |
| **Migration System** | `database/migrate.php` | Versioned database schema upgrades v1–v7 (idempotent) |
| **Autoloader** | `modules/autoload.php` | PSR-4-like autoloading via spl_autoload_register |
| **RateLimiter** | `modules/RateLimiter.php` | File-based API rate limiter (30 likes/min, 10 comments/min, etc.) |
| **Admin Activity Log** | `admin/activity_log.php` | Audit trail viewer with filter, pagination, cleanup |

---

## 📁 Important New Files

| File | Description |
|------|-----------|
| `database/schema.sql` | Standalone database schema — import directly via `mysql < database/schema.sql` |
| `auth/config.example.php` | Config template (copy to `config.php`) |

## 🔧 Recent Changes

- **Centralized paths:** All media storage paths (Video, Music, Books, Drive) managed from `MEEL_HDD_BASE` in `auth/config.php` — change just 1 line
- **Standalone database schema:** `database/schema.sql` for quick import
- **Type hints:** Class properties and constructor parameters now use type hints (`\mysqli`, `int`, `string`, etc.)
- **Activity Log Integration:** `log_activity()` function integrated at login, logout, upload, and admin actions — full audit trail to `activity_log` table
- **Admin Activity Log Viewer:** `admin/activity_log.php` page for viewing, filtering, and cleaning audit trails
- **Database Alignment:** `schema.sql` and `migrate.php` are synchronized (v1–v7) — UNIQUE KEY username, FK constraints, FULLTEXT index
- **Anime Module Removed:** The "Coming Soon" placeholder module has been removed from the codebase
- **API Rate Limiting:** File-based rate limiter (`modules/RateLimiter.php`) — protects like, comment, upload endpoints from abuse with per-user limits
- **Pagination Metadata:** `MediaLibrary` & `BookRepository` now return pagination metadata (`total_pages`, `from`, `to`) — UI displays page info
- **Admin Dashboard Charts:** Chart.js 7-Day Activity Chart — views, uploads, active users in the last 7 days

## 📖 About the Project

**MEeL** is a personal media hub platform built with PHP & MySQL running on Apache. It combines:

- **🎬 Video** — Adaptive HLS streaming with Plyr.js
- **🎵 Music** — Audio streaming with visualizer & mini player
- **📚 Books** — Manga/PDF digital reader
- **☁️ Cloud Drive** — Personal file storage with RBAC
- **🕹️ Arcade** — Mini-games (Dino Run, Snake, Chess)

### Core Tech Stack

| Component | Technology |
|----------|-----------|
| Backend | PHP 8.0+, MySQL/MariaDB |
| Frontend | TailwindCSS, HTMX, Vanilla JS |
| Media Player | Plyr.js, HLS.js |
| Transcoding | FFmpeg 6.0+, FFprobe |
| Downloader | yt-dlp |
| Server | Apache 2.4+ (mod_rewrite) |

---

## 🔗 Important Links

- [README.md](../../README.md) — Project overview
- [LICENSE](../../LICENSE) — Project license
- [GitHub Repository](https://github.com/mifada2543/MEeL) — Source repository
- [Bug Report](../../.github/ISSUE_TEMPLATE/bug_report.md) — Bug report template

---

## 👨‍💻 Contact

- **Email:** mifada2543@gmail.com
- **GitHub:** [github.com/mifada2543](https://github.com/mifada2543)

---

<div align="center">
  <sub>MEeL © 2026 — Mifada | Documentation v2.0</sub>
</div>
