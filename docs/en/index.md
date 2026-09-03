# 📚 MEeL-HUB Documentation

Welcome to the official **MEeL** documentation — A Personal Media Hub Platform for video streaming, music, digital books, and cloud storage.

---

## 📋 Documentation Map

| # | Document | Description |
|---|---|---|
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
| 11 | [📱 PWA](pwa.md) | Progressive Web App: dynamic service worker, cache strategies, offline |

---

## 📦 Complete Module List

| Module | File | Description |
|---|---|---|
| **Exception Classes** | `modules/exceptions/*.php` | 3 specific exception classes: `ProcessException`, `DownloadException`, `TranscodeException` |
| **Japanese Processor** | `modules/core/japanese.php` | MeCab + transliterator for Japanese text → Romaji filenames |
| **Bootstrap** | `modules/core/bootstrap.php` | Environment detection (dev/prod), error reporting, timezone |
| **CommentRenderer** | `modules/core/CommentRenderer.php` | Comment rendering with theme support (`video`/`music`) |
| **SearchEngine** | `modules/media/SearchEngine.php` | FULLTEXT search engine for video, music & books — with query sanitizer (`sanitizeQuery()`), min query length 3, offset cache key |
| **GarbageCollector** | `modules/core/GarbageCollector.php` | Auto-cleanup of temporary files, guest accounts & expired rate limit cache |
| **RateLimiter** | `modules/auth/RateLimiter.php` | File-based API rate limiter (30 likes/min, 10 comments/min, etc.) |
| **SsrfGuard** | `modules/auth/SsrfGuard.php` | SSRF-safe URL validation for outbound requests (yt-dlp pipeline) |
| **Security Helpers** | `modules/auth/helpers/` | Centralized security infrastructure: `authz.php` (admin guards), `csrf.php`, `session.php` (secure cookie), `stream_auth.php`, `mfa.php`, `user.php` — loaded via `modules/auth/loader.php` |
| **ValidatingProxy** | `modules/auth/ValidatingProxy.php` + `validating_proxy_server.php` | SSRF-defense forward proxy: every hop (incl. redirects) validated by SsrfGuard — the CLI server is spawned as a subprocess |
| **WatchController** | `controllers/api/WatchController.php` | Combined Video + Music watch pages controller |
| **UpdateManager** | `controllers/system/UpdateManager.php` | CRUD changelog entries (OOP) |
| **DriveService** | `drive/DriveService.php` | 3 classes: DriveUserContext, DriveStorage, DriveViewRenderer |
| **Profile Manager** | `controllers/profile/fun-manage.php` | Delete media, pending deletions, cleanup |
| **Migration System** | `database/migrate.php` | Versioned database schema upgrades v1–v12 (idempotent) |
| **PWA Precache** | `modules/core/SwPrecache.php` | Dynamic service worker precache generator — reads `assets/css/*/manifest.php`, auto `SW_VERSION` from content hash |
| **PWA Generator** | `sw.js.php` | Service worker generated per request (served as `/sw.js` via `.htaccess` rewrite) |
| **Autoloader** | `modules/autoload.php` | PSR-4-like autoloading |
| **Activity Logger** | `modules/core/activity_logger.php` | IP detection, session kick, guest auto-registration |
| **MFA System** | `controllers/system/mfa.php` | MFA backend controller (TOTP verify, backup codes, email) |
| **MFA Setup** | `auth/mfa_setup.php` | MFA setup (generate secret, scan QR, verify TOTP, backup codes) |
| **MFA Verify** | `auth/mfa_verify.php` | TOTP verification page after login |
| **MFA Reset (Admin)** | `admin/mfa_reset.php` | Admin reset MFA for users who lost Authenticator access |
| **Chess Multiplayer** | `arcade/chess/` | Real-time LAN chess — create/join room, turn-based, legal move validation |
| **Rhythm Module (MEeL!Mania)** | `arcade/rhythm/` | 4-lane rhythm game inspired by osu!mania — beatmap editor, custom song uploads (`arcade_song`/`arcade_score` tables via `arcade/rhythm/migration.sql`) |
| **FfmpegUtils Trait** | `modules/transcoder/FfmpegUtils.php` | Shared trait: probeDuration(), generateSpriteAndVTT() |
| **PlaylistRepository** | `modules/media/PlaylistRepository.php` | Playlist queries & playlist slug routes |
| **MediaAdminRepository** | `modules/media/MediaAdminRepository.php` | Media metadata queries for the admin panel (edit video/music) |
| **ProfileRepository** | `modules/media/ProfileRepository.php` | Profile data queries (video/music counts) |
| **AdminActivityRepository** | `modules/media/AdminActivityRepository.php` | Activity-log queries & filters for the admin viewer |
| **Admin Activity Log** | `admin/activity_log.php` | Audit trail viewer with filter, pagination, cleanup |

---

## 🧭 Routing System (Front Controller)

Since the routing migration, all public URLs use **clean URLs** (no `.php` extension).
Every request goes through the `router.php` front controller, which resolves the path
back to the real handler file via the route table in `modules/core/Router.php`.

**How it works:**

```
Request: /MEeL/music/beranda?format=ogg
  → .htaccess: every non-file/non-dir request is rewritten to router.php (except media upload/)
  → modules/core/Router.php: routeFor('music/beranda') → handler 'music/index.php'
  → require music/index.php with the original query string
```

**`.htaccess` rules (root):**
- All clean URLs (e.g. `video/watch`, `music/beranda`, `admin/analys`) → `router.php`
- Legacy `.php` files (e.g. `video/watch.php`, `music/index.php`, `upload_advanced.php`,
  `admin/cookies.php`) → **301** to the clean URL (query preserved, old bookmarks still work)
- Trailing-slash variants (e.g. `/video/upload/`) → **301** to the non-slash form
- `DirectorySlash Off` in media directories (`video/upload`, `music/upload`, `books/upload`)
  so `/video/upload` is served by the router (not a mod_dir redirect)

**`beranda` scheme:** each module has a `beranda` home page
(`video/beranda`, `music/beranda`, `books/beranda`, `drive/beranda`, `admin/beranda`,
`arcade/beranda`). Legacy `index.php` pages remain as aliases.

**Main route table (`modules/core/Router.php`):**

| URL | Handler (file) |
|---|---|
| `/` | `index.php` (hub) |
| `/introduction`, `/update`, `/upload`, `/transcode` | `introduction.php`, `update.php`, `upload_advanced.php`, `transcode.php` |
| `/err`, `/err/offline` | `err/index.php` (dynamic `?code=`), `err/offline.php` (PWA) |
| `/video/beranda`, `/video/watch`, `/video/search`, `/video/load-more`, `/video/upload`, `/video/stream` | `video/*.php` |
| `/music/beranda`, `/music/watch`, `/music/search`, `/music/load-more`, `/music/upload`, `/music/playlist`, `/music/playlist-action`, `/music/stream`, `/music/file` | `music/*.php` |
| `/music/<playlist-name>` | `music/view_playlist.php` (playlist slug route — see below) |
| `/books/beranda`, `/books/read`, `/books/read-pdf`, `/books/search`, `/books/upload`, `/books/file` | `books/*.php` |
| `/drive/beranda`, `/drive/upload`, `/drive/delete`, `/drive/download`, `/drive/stream` | `drive/*.php` |
| `/profile` (`?u=`), `/profile/edit`, `/profile/manage`, `/profile/manage-action` | `profile/index.php`, `controllers/profile/*.php` |
| `/admin/beranda`, `/admin/edit-video`, `/admin/edit-music`, `/admin/analys`, `/admin/activity-log`, `/admin/catur`, `/admin/mfa-reset`, `/admin/actions`, `/admin/data` | `admin/*.php`, `controllers/admin/*.php` |
| `/auth/login`, `/auth/register`, `/auth/logout`, `/auth/mfa-setup`, `/auth/mfa-verify` | `auth/*.php` |
| `/arcade/beranda`, `/arcade/chess`, `/arcade/rhythm`, `/arcade/rhythm/game`, `/arcade/rhythm/editor`, `/arcade/rhythm/manage`, `/arcade/rhythm/edit` | `arcade/*.php` |
| `/arcade/rhythm/api/songs`, `/arcade/rhythm/api/beatmap`, `/arcade/rhythm/api/upload`, `/arcade/rhythm/api/delete` | `arcade/rhythm/api/*.php` (MEeL!Mania) |
| `/api/like`, `/api/comment`, `/api/delete-comment`, `/api/auto-metadata`, `/api/pdf`, `/api/download-transcode`, `/api/post-encode`, `/api/theme`, `/api/ajax-refresh`, `/api/server-stats`, `/api/server-stats-sse` | `controllers/api/*.php` |
| `/system/mfa` | `controllers/system/mfa.php` |

> **Playlist slug route:** playlists have name-based URLs — `/music/<playlist-name>`
> (e.g. `/music/leo-need`). The slug is derived from the playlist name (`playlistSlug()`),
> guaranteed unique per user (`getUserPlaylistRoutes()`); non-ASCII names fall back to
> `playlist`, and names colliding with module routes or other slugs get a `-<id>` suffix.
> Exact routes (`beranda`, `watch`, etc.) always win; anything else is treated as a
> playlist slug (`resolvePlaylistSlug()`). The legacy URL `music/playlist?id=X` still works.

---

## 📁 Important New Files

| File | Description |
|---|---|
| `database/schema.sql` | Standalone database schema — import directly via `mysql < database/schema.sql` |
| `auth/config.example.php` | Entry point template (copy to `config.php`) |
| `auth/settings.example.php` | Config data template (copy to `settings.php`) |
| `sw.js.php` | Dynamic service worker generator (served as `/sw.js`) |
| `modules/core/SwPrecache.php` | Precache list + auto version generator for the SW |
| `assets/MEeL-{180,192,512}.png` | Real PWA icons (180/192/512 px) |

## 🔧 Recent Changes

- **Centralized paths:** All media storage paths (Video, Music, Books, Drive) managed from `MEEL_HDD_BASE` in `auth/settings.php` — change just 1 line
- **Standalone database schema:** `database/schema.sql` for quick import
- **Type hints:** Class properties and constructor parameters now use type hints (`\mysqli`, `int`, `string`, etc.)
- **Activity Log Integration:** `log_activity()` function integrated at login, logout, upload, and admin actions — full audit trail to `activity_log` table
- **Admin Activity Log Viewer:** `admin/activity_log.php` page for viewing, filtering, and cleaning audit trails
- **Database Alignment:** `schema.sql` and `migrate.php` are synchronized (v1–v12) — FULLTEXT, FK, UNIQUE KEY, activity_log, MFA, comments composite indexes, interactions unique keys, chess room identity
- **Migration v10:** Composite index `(video_id, created_at)` & `(music_id, created_at)` on `comments`
- **Migration v11:** `interactions` unique keys split into `(user_id, video_id)` & `(user_id, music_id)` — NULL in a combined unique key did not prevent duplicates
- **Migration v12:** Bind user identity to chess rooms (`white_user_id`, `black_user_id`) — prevents illegal access via `room_code`
- **Anime Module Removed:** The "Coming Soon" placeholder module has been removed from the codebase
- **API Rate Limiting:** File-based rate limiter (`modules/auth/RateLimiter.php`) — protects like, comment, upload endpoints from abuse with per-user limits with role-based adjustment (admin=unlimited, member=2x)
- **Security Module (`modules/auth/`):** Security helpers & classes consolidated into one directory for easy auditing — `helpers/` (authz, csrf, session, stream_auth, mfa, user) + `RateLimiter.php` + `SsrfGuard.php`, loaded via `modules/auth/loader.php` (the legacy `modules/core/helpers.php` shim still works)
- **Pagination Metadata:** `MediaLibrary` & `BookRepository` now return pagination metadata (`total_pages`, `from`, `to`) — UI displays page info
- **Admin Dashboard Charts:** Chart.js 7-Day Activity Chart — views, uploads, active users in the last 7 days
- **Player Enhancement:** Auto-next overlay with dark backdrop + hide Plyr replay button + mutual exclusion Auto-Next ↔ Loop
- **MFA Support:** Multi-Factor Authentication (TOTP) — setup, verify, backup codes, admin reset, brute-force protection (10 attempts → 5 min lockout)
- **UX Improvement:** Click vinyl disc → toggle mini-player; Hover overlay only on music thumbnail area; Skip resume modal when navigating from index mini-player
- **Cache Busting:** Music watch.php JS scripts now use `filemtime()` — no more hard-refresh needed
- **Arcade Chess:** Real-time LAN multiplayer chess — create/join room, turn-based, legal move validation
- **Chess Color Picker:** In multiplayer mode the board is hidden behind a color picker overlay (White = create room & wait, Black = join with code) — board locked until the game starts
- **Chess Auth & CSRF:** Multiplayer controllers now require login (JSON 401) and CSRF token on all state-changing calls; admin `auto_cleanup` endpoint verified with CSRF
- **Arcade Expansion (9 games):** besides Dino Run, Chess & Snake — now **2048**, **Tetris**, **Breakout**, **Simon Says**, **Ludo**, and **MEeL!Mania** (4-lane rhythm game inspired by osu!mania with a beatmap editor and custom song uploads MP3/OGG/FLAC/WAV ≤ 5 min; the `arcade_song`/`arcade_score` tables come from `arcade/rhythm/migration.sql` — separate from the main v1–v12 migrations)
- **PWA Optimization:** Dynamic service worker (`sw.js.php` + `SwPrecache`) — precache list auto-generated from `manifest.php`, auto `SW_VERSION`, real 192/512/maskable icons, iOS standalone meta, auto-reload on SW update
- **Search Improvements:** Query sanitizer (`sanitizeQuery()`), `MIN_SEARCH_QUERY = 3`, music search pagination, server-side books search (`BookRepository::searchBooks()`), cache key includes offset, `try/catch` around FULLTEXT queries
- **Auth Hardening:** Session cookies now `Secure` (auto-detect HTTPS) + `HttpOnly` + `SameSite=Lax`; `MEEL_TRUST_PROXY_HEADERS` (default `false`) to prevent IP spoofing via proxy headers; DB connection charset forced to `utf8mb4`
- **Admin CSRF:** Approve/reject/delete/kick/unban actions moved from GET links to POST forms with CSRF token
- **Centralized Session Bootstrap:** New file `modules/auth/helpers/session.php` with `meel_boot_session()` — every entry point (index, video, music, auth, controllers/api, err, admin) now calls this single function instead of scattered manual `session_name('meel'); session_start();`. The session cookie is guaranteed `HttpOnly` + `SameSite=Lax` + `Secure` (auto-detect HTTPS), 12-hour timeout, and idempotent (no-op if the session is already active)

## 📖 About the Project

**MEeL** is a personal media hub platform built with PHP & MySQL running on Apache. It combines:

- **🎬 Video** — Adaptive HLS streaming with Plyr.js
- **🎵 Music** — Audio streaming with visualizer & mini player
- **📚 Books** — Manga/PDF digital reader
- **☁️ Cloud Drive** — Personal file storage with RBAC
- **🕹️ Arcade** — 9 mini-games (Miku & Teto Run, Chess, Snake, 2048, Tetris, Breakout, Simon Says, Ludo, MEeL!Mania)

### Core Tech Stack

| Component | Technology |
|---|---|
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
