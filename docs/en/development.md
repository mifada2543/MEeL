# 👨‍💻 Development & Contribution Guide

Guide for developers who want to contribute or understand coding standards in MEeL-HUB.

---

## 📋 Table of Contents

- [Development Environment](#development-environment)
- [Coding Standards](#coding-standards)
- [Database Structure](#database-structure)
- [Coding Conventions](#coding-conventions)
- [Testing](#testing)
- [Pull Request Guide](#pull-request-guide)
- [Troubleshooting Development](#troubleshooting-development)

---

## Development Environment

### Setup

1. **Install dependencies:**
```bash
git clone https://github.com/mifada2543/MEeL.git
cd MEeL
cp auth/settings.example.php auth/settings.php
cp auth/config.example.php auth/config.php
```

2. **Enable debug mode:**
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

3. **Disable HDD check for development:**
```php
// modules/core/helpers.php - comment out:
// if (!is_dir(MEEL_HDD_BASE)) { ... }
```

4. **Recommended tools:**
- Editor: VS Code with PHP Intelephense
- Database: MySQL Workbench / phpMyAdmin
- API Testing: Postman / Insomnia
- Browser: Chrome DevTools for HTMX debugging

---

## Coding Standards

### PHP

#### 1. PSR-12 Basic Coding Style

```php
<?php
declare(strict_types=1);

namespace MEeL\Modules;

class MediaLibrary
{
    private mysqli $conn;
    
    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }
}
```

#### 2. Prepared Statements REQUIRED

```php
// ✅ CORRECT - Prepared Statement
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();

// ❌ WRONG - Don't use query() with concatenation
// $result = $conn->query("SELECT * FROM users WHERE username = '$username'");
```

#### 3. Class Naming Convention

```php
// Class: PascalCase
class MediaLibrary {}
class BookRepository {}

// Methods: camelCase
public function getVideos();
public function toggleLike();
```

#### 4. Type Hints

Properties and constructor parameters **must** have type hints (PHP 7.4+):

```php
// ✅ CORRECT
private \mysqli $conn;
private int $user_id;
private string $username;

public function __construct(\mysqli $db, int $user_id, string $username) { }
```

### JavaScript

```javascript
// ✅ CORRECT - Named functions
function handleSearch(event) {
    const query = event.target.value;
}

// Event listeners preferred over inline HTML
document.getElementById('search-input').addEventListener('input', handleSearch);

// HTMX event monitoring
document.body.addEventListener('htmx:afterOnLoad', function(evt) {
    lucide.createIcons();
});
```

### CSS

Project uses **TailwindCSS (self-hosted, purged)** for main styling with minimal custom CSS for special effects.

---

## Database Structure

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
|---|---|---|---|
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

### Security

1. **Always Prepared Statement** — No SQL concat
2. **Always htmlspecialchars()** — For output
3. **CSRF Token** — Every POST form required
4. **Role Check** — Before sensitive actions
5. **Input Validation** — Type, size, file extension
6. **No `@` suppression on filesystem ops** — use proactive `is_file()`/`is_dir()`/
   `is_readable()`/`is_writable()` guards, check return values, and reuse the shared
   helpers (`FfmpegUtils` trait, `GarbageCollector::removeFile()`/`removeDirectory()`,
   `meel_write_cache_file()`). See the [Filesystem Safety Convention](modules.md#filesystem-safety-convention-no--suppression).
7. **Centralized Session Boot** — Every entry point must call `meel_boot_session()`
   (from `modules/auth/helpers/session.php`) — never raw `session_name()` + `session_start()`.
   This function guarantees the session cookie uses `HttpOnly`/`SameSite=Lax`/`Secure`
   (auto-detect HTTPS) flags and the 12-hour timeout consistently.

### File Structure per Module

Each module (video, music, books, drive) follows this pattern. Pages are reached
via **clean URLs** (front controller `router.php` → `modules/core/Router.php`),
e.g. `video/beranda` → `video/index.php`, `music/watch?id=X` → `music/watch.php`:

```
[module]/
├── index.php          # Catalog / listing (URL: [module]/beranda)
├── watch.php          # Player / detail (URL: [module]/watch?id=X)
├── upload.php         # Upload form (URL: [module]/upload)
├── search_[module].php  # Search (HTMX) (URL: [module]/search)
├── load_more.php      # Pagination (HTMX) (URL: [module]/load-more)
└── [module]_item.php  # Card component
```

### HTMX Pattern

```php
<!-- Trigger -->
<input type="text" name="search"
    hx-get="video/search"
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

---

## Testing

### Manual Testing Checklist

**Frontend:**
- [ ] No errors in browser console
- [ ] HTMX request/response working
- [ ] Mobile responsive (min width 320px)
- [ ] Dark mode consistent
- [ ] All buttons and links functional

**Backend:**
- [ ] Prepared statements not erroring
- [ ] CSRF validation working
- [ ] Role-based access working
- [ ] File upload validation working
- [ ] Error handling showing appropriate messages

---

## MFA / TOTP Development

### TOTP Implementation (Time-based One-Time Password)

MEeL implements TOTP per [RFC 6238](https://datatracker.ietf.org/doc/html/rfc6238):

| Parameter | Value |
|---|---|
| Algorithm | HMAC-SHA1 |
| Digits | 6 digits |
| Time Step | 30 seconds |
| Window | ±1 (90 seconds tolerance) |
| Encoding | Base32 |

### Helper Functions (in `modules/core/helpers.php`)

```php
// ─── GENERATE SECRET ───────────────────────────────────────
function generate_mfa_secret(): string {
    $random = random_bytes(20);  // 160-bit
    // Base32 encode (A-Z, 2-7)
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bits = 0; $buffer = 0;
    foreach (str_split($random) as $byte) {
        $buffer = ($buffer << 8) | ord($byte);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $secret .= $base32[($buffer >> $bits) & 31];
        }
    }
    return $secret;
}

// ─── GENERATE TOTP ─────────────────────────────────────────
function generate_totp(string $secret): string {
    $decoded = base32_decode($secret);  // Base32 → raw bytes
    $counter = pack('N*', 0) . pack('N*', intdiv(time(), 30));
    $hash = hash_hmac('sha1', $counter, $decoded, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset+1]) & 0xff) << 16) |
        ((ord($hash[$offset+2]) & 0xff) << 8) |
        (ord($hash[$offset+3]) & 0xff)
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

// ─── VERIFY TOTP (with window ±1) ────────────────────────
function verify_totp(string $secret, string $code): bool {
    for ($i = -1; $i <= 1; $i++) {
        // Generate TOTP with time offset $i step
        $expected = generate_totp_at($secret, time() + ($i * 30));
        if (hash_equals($expected, $code)) return true;
    }
    return false;
}
```

### Backup Codes System

```php
// ─── GENERATE 8 BACKUP CODES ───────────────────────────────
function generate_backup_codes(): array {
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $code = bin2hex(random_bytes(4));  // 8 hex characters
        $plain[] = $code;
        $hashed[] = hash('sha256', $code);
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}

// ─── VERIFY BACKUP CODE (single-use) ───────────────────────
function verify_backup_code(string $hashedJson, string $code): array {
    $codes = json_decode($hashedJson, true) ?? [];
    foreach ($codes as $i => $hash) {
        if (hash_equals($hash, hash('sha256', $code))) {
            array_splice($codes, $i, 1);  // Remove used code
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => $codes];
}
```

### Database Schema

3 new columns in `users` table (Migration v9):

```sql
ALTER TABLE users
    ADD COLUMN mfa_secret      VARCHAR(64)  DEFAULT NULL AFTER last_session_id,
    ADD COLUMN mfa_backup_codes TEXT        DEFAULT NULL AFTER mfa_secret,
    ADD COLUMN mfa_enabled     TINYINT(1)   DEFAULT 0     AFTER mfa_backup_codes;
```

### MFA Session Flow

```
1. Login password correct → Check mfa_enabled == 1
2. Yes → Save $_SESSION['mfa_temp_uid'] = user_id
          Save $_SESSION['mfa_temp_username']
          Save $_SESSION['mfa_temp_role']
3. Redirect to auth/mfa-verify
4. User inputs 6-digit code
5. Valid → Set $_SESSION['user_id', 'username', 'role']
          Set $_SESSION['mfa_verified'] = true
          Remove mfa_temp_* from session
6. Invalid → Increment $_SESSION['mfa_fail_count']
             If >= 10 → $_SESSION['mfa_locked_until'] = time() + 300
```

### Rate Limiting

| Endpoint | Limit | Mechanism |
|---|:---:|---|
| MFA Verify | 10 failures → lock 5 minutes | Session-based `mfa_fail_count` + `mfa_locked_until` |
| Backup Password | 5 failures → lock 5 minutes | Session-based `backup_pwd_attempts` + `backup_pwd_lock_until` |

### Security Considerations

1. **TOTP Secret** — Stored plaintext in DB (TOTP secrets must be readable)
2. **Backup Codes** — Stored as SHA256 hash (one-way, cannot be reversed)
3. **Session Temp** — `mfa_temp_uid` only exists in session, not in cookies
4. **Brute Force** — 10 failed MFA attempts → lock 5 minutes
5. **QR Code** — 100% offline (local qrcode.min.js library, no data sent to external server)
6. **Admin Reset** — Admin cannot reset another admin's MFA
7. **Activity Log** — All MFA events (setup, verify, fail, reset) logged in `activity_log`

### Testing MFA Locally

1. **Enable MFA:** Go to `profile/index.php` → click MFA toggle → follow setup
2. **Get TOTP:** Open `auth/mfa_setup.php`, scan QR with Google Authenticator
3. **Simulate TOTP:** Use `generate_totp($secret)` via test script for verification
4. **Test rate limit:** Enter wrong code 10× → check lockout
5. **Test backup code:** Try one of the backup codes to login
6. **Test admin reset:** Login as admin → `admin/mfa_reset.php` → reset user

---

## Pull Request Guide

### 📜 License & Contribution

This project is licensed under **GNU General Public License v3.0 (GPLv3)**. See the [`LICENSE`](../../LICENSE) file for full text.

> **By submitting a Pull Request, you agree that your contributions will be licensed under GPL v3.**

#### Copyright Header on New Files

```php
/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
```

### Contribution Checklist

- [ ] Use **Prepared Statements** for all database queries
- [ ] Sanitize POST/GET input
- [ ] CSRF token on every new POST form
- [ ] Role check before sensitive operations
- [ ] Update `update.php` with changelog
- [ ] Every new file has **GPL v3 copyright header**
- [ ] Changes clearly marked with **modification notice**

### Git Commit Convention

```
[type]: Short description (max 50 chars)

- Detailed changes if needed
- Multi-line allowed
```

**Types:**
| Type | Usage |
|---|---|
| `feat` | New feature |
| `fix` | Bug fix |
| `security` | Security fix |
| `perf` | Performance optimization |
| `refactor` | Code refactoring |
| `docs` | Documentation |
| `style` | CSS/UI fix |

### Branch Strategy

```
main (stable)
  └── Experiment (development branch)
       ├── feature/[feature-name]
       └── fix/[fix-name]
```

---

## Resource for Developers

### Key Files to Understand

| File | Reason |
|---|---|
| `auth/config.php` | Configuration entry point |
| `auth/auth.php` | Authentication middleware |
| `modules/core/helpers.php` | Global utility functions |
| `modules/core/Transcoder.php` | Main engine (most complex) |
| `modules/core/Uploader.php` | File upload process |
| `modules/core/System.php` | Queue & monitoring |
| `modules/auth/RateLimiter.php` | API Rate Limiter |
| `modules/core/ProgressObserver.php` | Progress event contract (interface + callable adapter) — see `modules.md` |
| `modules/core/BrowserProgressObserver.php` | Browser presenter — maps engine events to the overlay/`meel*` JS |
| `modules/core/GarbageCollector.php` | Auto-cleanup |
| `modules/media/SearchEngine.php` | FULLTEXT Search engine |
| `modules/core/japanese.php` | Japanese text processing |
| `modules/core/bootstrap.php` | Bootstrap & environment |
| `modules/exceptions/*.php` | Exception classes |
| `modules/transcoder/FfmpegUtils.php` | FFmpeg utilities trait |
| `auth/mfa_setup.php` | MFA Setup (multi-step: secret → QR → verify → backup) |
| `auth/mfa_verify.php` | MFA TOTP verification page (rate limited) |
| `controllers/system/mfa.php` | MFA backend controller (generate/download backup codes) |
| `admin/mfa_reset.php` | Admin MFA reset panel |
| `partials/ui.php` | Overlay UI system (JS heavy) |
| `assets/js/shared/keyboard.js` | Shared keyboard shortcut guard (meelKeyShortcutIgnored) — used by video & music misc/mini-player |
| `assets/js/shared/temp-index.js` | Shared loader of index.php into #temp-index-content without reload (meelLoadTempIndex) — used by video & music mini-player |
| `assets/js/shared/plyr-config.js` | Shared Plyr base config (MEEL_PLYR_COMMON: iconUrl, speed, keyboard, tooltips) — used by video & music players |
| `assets/js/shared/upload-progress.js` | Shared upload progress-bar animation (meelUploadProgress) — used by music & video upload pages |
| `assets/js/shared/resume-modal.js` | Shared resume modal (meelResumeModal) — used by video player-events & music player-core |
| `assets/js/shared/format-time.js` | Shared mm:ss time formatter (formatTime) — moved from music/shared/utils.js, used by music mini-players & resume-modal |
| `assets/js/shared/mini-player-popstate.js` | Shared popstate handler to exit mini-player mode (meelMiniPlayerPopstate) — used by video & music watch mini-players |
| `assets/js/video/watch/main.js` | Entry point folder watch/ — loads siblings synchronously (document.write) |
| `assets/js/video/watch/state.js` | Video player state management |
| `assets/js/video/watch/player-init.js` | Plyr + HLS.js initialization |
| `assets/js/video/watch/player-events.js` | Event orchestration (auto-next, glow, resume) |
| `assets/js/video/watch/mini-player.js` | Mini-player floating mode |
| `assets/js/video/watch/recovery.js` | Player auto-recovery system |
| `assets/js/video/watch/gestures.js` | Mobile touch gestures |
| `assets/js/music/watch/main.js` | Entry point folder watch/ — loads siblings synchronously (document.write) |
| `assets/js/music/watch/mini-player.js` | Music mini-player mode (Spotify-style) — separated from player-core.js |
| `assets/js/music/watch/player-core.js` | Music player core (visualizer, EQ, bitrate, resume-modal & session logic) |
| `assets/js/music/watch/state.js` | Music player state, equalizer presets & resume-session marker (`window.__meelResumeSessionActive`) |

### Music Player — Resume Modal Behavior

The music player shows the **"Lanjut Musik?"** resume modal when a song has a
saved playback position (`music_pos_<id>` in `localStorage`) and the user did
**not** arrive from an active mini-player session.

| Context | Behavior |
|---|---|
| **Mini-player session** — user tapped a card / playlist item or expanded the mini-player on `index.php`, and is still listening | 🎧 **Auto-continue** — no modal; every following song in the session plays automatically |
| **Cold visit** — direct `watch.php` open, page reload, or after an explicit pause/close of the mini-player | ❓ **Modal shown** — "Lanjut Musik?" asks whether to resume from the saved position |

**Mechanisms:**

- **One-shot flag `skip_resume_once`** (`sessionStorage`) — set by the index
  side on card/playlist tap and in `expandPlayerFromMiniPlayer()`. It is read
  and removed at **every** `meelInitWatchPlayer()` call (including gapless
  transitions), so it never leaks or sticks in storage.
- **Session marker `window.__meelResumeSessionActive`** (in-memory, declared in
  `assets/js/music/watch/state.js`) — activated when the one-shot flag is
  consumed. It lives for the whole SPA document, so **all** subsequent
  in-watch track changes (auto-next, song switch) skip the modal.
- **Explicit end of session** — `miniPlayPauseIndex()` (pause) and
  `closeMiniPlayerIndex()` on `index.php` clear both the one-shot flag and the
  session marker (`assets/js/music/shared/mini-player.js`). After that, opening
  a song from a link shows the modal again.
- **Cold visits** — a full page load creates a new document where the in-memory
  marker is gone, so the modal can appear (`skipOnce` in `player-core.js`
  checks `skipResumeModalOnce || window.__meelResumeSessionActive`).
- **Stuck-paused guard** — if the modal is suppressed but the song has a saved
  position, `onFreshTrackReady()` auto-plays from the beginning instead of
  leaving the song silent.

> **Design decision (2026-08):** active listening sessions auto-continue
> without interruption; only cold visits ask to resume.

### Key Processes

1. **Upload Pipeline** — Uploader → FFmpeg → HDD → DB
2. **Download Pipeline** — URL → yt-dlp → FFmpeg → HDD → DB
3. **Auth Flow** — Login → Session → RBAC → Activity Log
4. **HTMX Flow** — Event → Request → Server → Response → DOM swap
5. **MFA Flow** — Login password valid → Check mfa_enabled → Redirect mfa_verify.php → Verify TOTP → Set full session
6. **Music Player Session & Resume** — Card/playlist tap → mini-player (sets `skip_resume_once`) → expand → watch (consumes flag, activates session marker) → auto-continue; cold visits show the resume modal

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
