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
| 10 | [🧪 Testing Guide](test.md) | PHPUnit, Functional, Security test — complete guide |

---

## 📦 Complete Module List

| Module | File | Description |
|-------|------|-----------|
| **Exception Classes** | `modules/exceptions/*.php` | 3 specific exception classes: `ProcessException`, `DownloadException`, `TranscodeException` |
| **Japanese Processor** | `modules/core/japanese.php` | MeCab + transliterator for Japanese text → Romaji filenames |
| **Bootstrap** | `modules/core/bootstrap.php` | Environment detection (dev/prod), error reporting, timezone |
| **CommentRenderer** | `modules/core/CommentRenderer.php` | Comment rendering with theme support (`video`/`music`) |
| **SearchEngine** | `modules/media/SearchEngine.php` | FULLTEXT search engine for video & music |
| **GarbageCollector** | `modules/core/GarbageCollector.php` | Auto-cleanup of temporary files, guest accounts & expired rate limit cache |
| **RateLimiter** | `modules/core/RateLimiter.php` | File-based API rate limiter (30 likes/min, 10 comments/min, etc.) |
| **WatchController** | `controllers/api/WatchController.php` | Combined Video + Music watch pages controller |
| **UpdateManager** | `controllers/system/UpdateManager.php` | CRUD changelog entries (OOP) |
| **DriveService** | `drive/DriveService.php` | 3 classes: DriveUserContext, DriveStorage, DriveViewRenderer |
| **Profile Manager** | `controllers/profile/fun-manage.php` | Delete media, pending deletions, cleanup |
| **Migration System** | `database/migrate.php` | Versioned database schema upgrades v1–v8 (idempotent) |
| **Autoloader** | `modules/autoload.php` | PSR-4-like autoloading |
| **Activity Logger** | `modules/core/activity_logger.php` | IP detection, session kick, guest auto-registration |
| **MFA System** | `controllers/system/mfa.php` | MFA backend controller (TOTP verify, backup codes, email) |
| **MFA Setup** | `auth/mfa_setup.php` | MFA setup (generate secret, scan QR, verify TOTP, backup codes) |
| **MFA Verify** | `auth/mfa_verify.php` | TOTP verification page after login |
| **MFA Reset (Admin)** | `admin/mfa_reset.php` | Admin reset MFA for users who lost Authenticator access |
| **Chess Multiplayer** | `arcade/chess/` | Real-time LAN chess — create/join room, turn-based, legal move validation |
| **FfmpegUtils Trait** | `modules/transcoder/FfmpegUtils.php` | Shared trait: probeDuration(), generateSpriteAndVTT() |
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
- **Database Alignment:** `schema.sql` and `migrate.php` are synchronized (v1–v8) — FULLTEXT, FK, UNIQUE KEY, activity_log, schema sync
- **Anime Module Removed:** The "Coming Soon" placeholder module has been removed from the codebase
- **API Rate Limiting:** File-based rate limiter (`modules/core/RateLimiter.php`) — protects like, comment, upload endpoints from abuse with per-user limits with role-based adjustment (admin=unlimited, member=2x)
- **Pagination Metadata:** `MediaLibrary` & `BookRepository` now return pagination metadata (`total_pages`, `from`, `to`) — UI displays page info
- **Admin Dashboard Charts:** Chart.js 7-Day Activity Chart — views, uploads, active users in the last 7 days
- **Player Enhancement:** Auto-next overlay with dark backdrop + hide Plyr replay button + mutual exclusion Auto-Next ↔ Loop
- **MFA Support:** Multi-Factor Authentication (TOTP) — setup, verify, backup codes, admin reset, brute-force protection (10 attempts → 5 min lockout)
- **UX Improvement:** Click vinyl disc → toggle mini-player; Hover overlay only on music thumbnail area; Skip resume modal when navigating from index mini-player
- **Cache Busting:** Music watch.php JS scripts now use `filemtime()` — no more hard-refresh needed
- **Arcade Chess:** Real-time LAN multiplayer chess — create/join room, turn-based, legal move validation

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
