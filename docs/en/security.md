# 🔒 MEeL Security System

Documentation about authentication, authorization, and protection systems in MEeL-HUB.

---

## 📋 Table of Contents

- [Security Architecture](#security-architecture)
- [Role-Based Access Control (RBAC)](#role-based-access-control-rbac)
- [Session Management](#session-management)
- [CSRF Protection](#csrf-protection)
- [IP Banning & Firewall](#ip-banning--firewall)
- [Activity Logging](#activity-logging)
- [API Rate Limiting](#api-rate-limiting)
- [Multi-Factor Authentication (MFA)](#multi-factor-authentication-mfa)
- [File Upload Security](#file-upload-security)
- [Apache .htaccess Protection](#apache-htaccess-protection)
- [SSRF Protection (Advanced Upload)](#ssrf-protection-advanced-upload)
- [Private Drive Protection](#private-drive-protection)
- [Exception Handling](#exception-handling)
- [Disk Space Validation](#disk-space-validation)
- [Input Validation](#input-validation)

---

## Security Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Browser Request                      │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              1. Apache .htaccess Layer                  │
│  • Block direct access to sensitive directories         │
│  • mod_rewrite rules                                    │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              2. Session Authentication (auth.php)       │
│  • Check user_id in session                             │
│  • Validate last_session_id (anti-hijack)               │
│  • Session timeout (12 hours)                           │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              3. IP Ban Check (activity_logger.php)      │
│  • Check IP against banned list                         │
│  • Block all non-admin users if IP is banned            │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              4. Role-Based Access (RBAC)                │
│  • Admin / Member / User / Guest                        │
│  • Feature gating per page                              │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              5. CSRF Protection                         │
│  • Token validation on all POST requests                │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              6. Multi-Factor Authentication (MFA)       │
│  • TOTP (Time-based One-Time Password) via Authenticator │
│  • Backup codes for recovery                            │
│  • Brute-force protection (10 attempts → 5 min lock)    │
└──────────────────────┬──────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────┐
│              7. Prepared Statements                     │
│  • All database queries use mysqli prepared statements  │
│  • No raw SQL concatenation with user input             │
└─────────────────────────────────────────────────────────┘
```

---

## Role-Based Access Control (RBAC)

### Role Definitions

| Role | Level | Access Rights |
|---|---|---|
| **Admin** | 100 | Full system control |
| **Member** | 50 | Media + Cloud Drive (20GB quota) |
| **User** | 30 | Media + comments (no Drive) |
| **Guest** | 0 | View-only, no interaction |

### Feature Gating per Role

| Feature | Admin | Member | User | Guest |
|---|---|---|---|---|
| Watch Video | ✅ | ✅ | ✅ | ✅ |
| Listen Music | ✅ | ✅ | ✅ | ✅ |
| Like/Dislike | ✅ | ✅ | ✅ | ❌ |
| Comments | ✅ | ✅ | ✅ | ❌ |
| Upload Video | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Upload Music | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Books | ✅ | ✅ | ✅ | ❌ |
| Cloud Drive | ✅ (unlimited) | ✅ (20GB) | ❌ | ❌ |
| Advanced Upload | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Transcoder | ✅ | ✅ | ✅ | ❌ |
| Admin Panel | ✅ | ❌ | ❌ | ❌ |
| Chess Multiplayer | ✅ | ✅ | ✅ | ❌ |

---

## Session Management

### Session Configuration

```php
// modules/auth/helpers/session.php — meel_boot_session()
// (auth/config.php & auth/auth_helpers.php delegate to this function)
$timeout = 43200;              // 12 hours
ini_set('session.gc_maxlifetime', $timeout);

// Hardened cookie flags (auto-detect HTTPS / X-Forwarded-Proto)
$secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

session_set_cookie_params([
    'lifetime' => $timeout,
    'path'     => '/',
    'secure'   => $secure_cookie,  // HTTPS only
    'httponly' => true,            // not readable by JavaScript
    'samesite' => 'Lax',           // CSRF mitigation
]);
session_name('meel');
session_start();
```

| Flag | Value | Protection |
|---|---|---|
| `Secure` | auto (HTTPS) | Cookie never sent over plain HTTP — prevents sniffing |
| `HttpOnly` | `true` | XSS cannot steal the session cookie via JavaScript |
| `SameSite` | `Lax` | Cross-site POST requests don't carry the cookie (CSRF layer 1) |

The configuration above is now **centralized** in `modules/auth/helpers/session.php` as the
`meel_boot_session()` function — the single source of truth for session bootstrapping. Every
entry point (index, video, music, auth, controllers/api, err, admin) calls it instead of the
scattered manual `session_name('meel'); session_start();` pattern, so the session cookie is
guaranteed to always use the hardened flags. The function is **idempotent** (no-op if the
session is already active); `auth/config.php` and `auth_boot_session()` in
`auth/auth_helpers.php` now delegate to it:

```php
// Usage in a new entry point
require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();
```

### Session Hijacking Prevention

Every user has a `last_session_id` in the database. If the browser's session ID differs, the session is considered hijacked/kicked:

```php
if ($user_status['last_session_id'] !== $current_sid) {
    session_unset();
    session_destroy();
    header("Location: .../err/?code=revoked");
    exit();
}
```

### Admin Kick Feature

Admins can forcefully kick users from the admin panel:
```php
$stmt_kick = $conn->prepare("UPDATE users SET 
    last_session_id = 'KICKED', 
    last_page = 'KICKED BY ADMIN', 
    last_activity = DATE_SUB(NOW(), INTERVAL 10 MINUTE) 
    WHERE username = ?");
```

---

## CSRF Protection

### Token Generation

```php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

### Token Validation

```php
// verify_csrf_token() — defined in modules/auth/helpers/csrf.php
// Uses hash_equals() for timing-attack safety
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
```

### HTMX Integration

```php
$token = $_SESSION['csrf_token'];
echo "<input type='hidden' name='csrf_token' value='$token'>";
```

### Admin Actions — POST Forms (not GET links)

Admin state-changing actions (approve/reject/delete user, kick user, unban IP, force-stop queue)
use **POST forms with CSRF token** — a GET link can be triggered by a `<img>` tag (CSRF),
a POST form cannot:

```html
<form method="POST" class="inline" onsubmit="return meelConfirmForm(event, {...})">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="approve_id" value="<?= (int)$u['id'] ?>">
    <button type="submit">APPROVE</button>
</form>
```

Handlers in `controllers/admin/admin_actions.php` now read `$_POST` and the
chess admin `catur.php?auto_cleanup=1` endpoint requires a `csrf_token` too
(`window.MEEL_ADMIN_CSRF` bridge).

> ⚠️ **Form action must NOT use `action="."`** — when the page URL is `/admin/beranda`,
> `action="."` resolves to `/admin/`, which gets 301-redirected to `/admin/beranda` by
> the `.htaccess` canonical rule. The 301 converts POST to GET, losing all form data.
> Use no `action` attribute (defaults to current page URL) or `action=""`.

### Chess Multiplayer — Login + CSRF Guards

All `arcade/chess/controller/*.php` endpoints require:
- **Login** — JSON `401` + `login_required: true` (client `arcade/chess/assets/js/api.js` redirects to login).
- **CSRF** — every state-changing POST carries `csrf_token` (JSON body for `save_move`, `FormData` for `create_room`/`join_room`).
- The token is **never stored** in `moves.move_data` (not exposed to opponents).

---

## IP Banning & Firewall

### IP Detection (Anti-Proxy)

```php
function get_real_ip() {
    // Cloudflare
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        return $_SERVER["HTTP_CF_CONNECTING_IP"];
    }
    // X-Forwarded-For
    if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        return trim(explode(',', $_SERVER["HTTP_X_FORWARDED_FOR"])[0]);
    }
    // Fallback
    return $_SERVER["REMOTE_ADDR"];
}
```

### Trusted Proxy Gate (`MEEL_TRUST_PROXY_HEADERS`)

Header proxy **hanya** boleh dipercaya jika request lewat proxy/CDN yang Anda
kendalikan. Konfigurasi di `auth/settings.php`:

```php
define('MEEL_TRUST_PROXY_HEADERS', false); // default aman: pakai REMOTE_ADDR saja
```

> Jika diset `true` padahal server diakses langsung, attacker bisa memalsukan
> `X-Forwarded-For` untuk mem-bypass IP-ban atau membanjiri activity log.

### Ban Check (Real-time)

Checked on every page load. Non-admin users are redirected to `err/?code=banned` with the ban reason.

---

## Activity Logging

### Logger Function

```php
function log_activity(
    mysqli $conn, 
    int $user_id, 
    string $action, 
    string $media_type = '', 
    ?int $media_id = null
): void;
```

### Integrated Events

| Event | Action | Location |
|---|---|---|
| Successful login | `login` | `auth/login` |
| Logout | `logout` | `auth/logout` |
| Video upload | `upload_video` | `video/upload` |
| Music upload | `upload_music` | `music/upload` |
| Book upload | `upload_book` | `books/upload` |
| URL download | `upload_url` | `upload_advanced.php` |
| IP Ban | `ban_ip` | `controllers/admin/admin_actions.php` |
| IP Unban | `unban_ip` | `controllers/admin/admin_actions.php` |
| Approve user | `approve_user` | `controllers/admin/admin_actions.php` |
| Reject user | `reject_user` | `controllers/admin/admin_actions.php` |
| Delete user | `delete_user` | `controllers/admin/admin_actions.php` |
| Kick user | `kick_user` | `controllers/admin/admin_actions.php` |

### Admin Activity Log Viewer

Page `admin/activity_log.php` provides a dedicated audit trail viewer:

| Feature | Detail |
|---|---|
| 🔍 **Filter** | By action type (dropdown), search username/IP, date range (7–365 days) |
| 📄 **Pagination** | 50 entries per page with prev/next navigation |
| 📊 **Stats Cards** | 7-day activity count, unique users, total entries, page info |
| 🏷️ **Action Badges** | Color-coded: login/logout (blue), upload (green), ban (red), admin (purple) |
| 🗑️ **Manual Cleanup** | Delete old logs (>7, 14, 30, 90, 365 days) with SweetAlert2 confirmation + CSRF |

---

## API Rate Limiting

### Architecture

`modules/auth/RateLimiter.php` provides **file-based rate limiting** that protects API endpoints from abuse:

```
Request → RateLimiter::check(key, endpoint)
  ↓
Read cache file at temp/ratelimit/{md5_hash}.cache
  ↓
flock(LOCK_EX) → Increment counter → ftruncate + fwrite
  ↓
Counter > max? → Yes → HTTP 429 Too Many Requests
  ↓ No
Allow request
```

### Endpoint Limits

| Endpoint | Max Requests | Window | Response on Limit |
|---|:---:|:---:|---|
| **Like/Dislike** | 30 | 1 minute | HTTP 429 + HTMX HTML snippet (yellow badge "Wait Xs" + disabled buttons) |
| **Comment** | 10 | 1 minute | Redirect with flash error message |
| **Upload** (video/music/books) | 3 | 1 hour | — |
| **Transcode** | 5 | 1 hour | — |
| **API Generic** | 60 | 1 minute | — |

### Cleanup

Expired files (>1 hour) are automatically cleaned by `GarbageCollector::run()` called on every request.

---

## Multi-Factor Authentication (MFA)

### MFA Architecture

```
Login Flow:
  POST login → password correct
    ↓
  Check users.mfa_enabled == 1?
    ↓ Yes                         ↓ No
  Save mfa_temp_uid to session    Set session directly
    ↓                              ↓
  Redirect to auth/mfa-verify     Redirect to index
```

### TOTP Implementation

MEeL uses **TOTP (Time-based One-Time Password)** with algorithm:
- **HMAC-SHA1** — standard TOTP
- **6 digits** — verification code
- **30 seconds** — time step
- **Window ±1** — 90 seconds tolerance

### Secret Generation

```php
// modules/auth/helpers/mfa.php
function generate_mfa_secret(): string {
    $random = random_bytes(20);  // 160-bit random
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    foreach (str_split($random) as $byte) {
        $secret .= $base32[ord($byte) & 31];
        // XOR carry for 5-bit encoding
    }
    return $secret;
}
```

Secret stored in `mfa_secret` column (VARCHAR(64)) in `users` table. TOTP secrets must be readable for verification. If database leaks, attacker still needs access to Google Authenticator or backup codes.

### Backup Codes

- **8 backup codes** — each 6 numeric digits
- **Stored as `password_hash()` (bcrypt) hashes** — one-way, cannot be reversed
- **Single-use** — hash removed from array after use

```php
function generate_backup_codes(): array {
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT); // 6 digits
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT); // bcrypt
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}

function verify_backup_code(string $hashedCodes, string $code): array {
    $codes = json_decode($hashedCodes, true) ?? [];
    foreach ($codes as $i => $hash) {
        if (password_verify($code, $hash)) {
            array_splice($codes, $i, 1); // Remove used code
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => $codes];
}
```

### Brute-Force Protection

```php
// Max 10 failed MFA attempts, lock 5 minutes
$max_mfa_attempts = 10;
$mfa_lockout_time = 300; // 5 minutes

if (isset($_SESSION['mfa_locked_until'])) {
    if (time() >= $_SESSION['mfa_locked_until']) {
        unset($_SESSION['mfa_locked_until'], $_SESSION['mfa_fail_count']);
    } else {
        $mfa_locked = true;
        $mfa_remaining = $_SESSION['mfa_locked_until'] - time();
    }
}
```

### Admin Reset MFA

Admins can reset a user's MFA from `admin/mfa_reset.php`:
- **Cannot reset another admin** — only the admin themselves can disable their own MFA
- **Action logged** — `log_activity($conn, $admin_id, 'reset_mfa', 'user', $target_id)`
- **User needs to re-setup** — MFA reset to default (disabled)

### MFA in Profile

Page `profile/index.php` displays MFA status with visual toggle switch:
- Green "Active" → link to `auth/mfa-setup` for management/disable
- Gray "Inactive" → link to `auth/mfa-setup` for setup
- If active, backup codes also displayed in profile

### Guest Profile Access

Guests (unauthenticated users) can view any user's profile page without login. The profile page uses `meel_boot_session()` (not raw `session_start()`) to ensure the session cookie name matches the rest of the application (`meel`).

- **Guest viewing own profile:** Synthetic profile with `id=0`, `role='guest'`, bio="Akun Guest" — theme toggle available
- **Guest viewing other users:** Real profile from DB — bio, stats, badge shown correctly; no Edit/Manage/MFA buttons; no theme toggle
- **Logged-in user viewing own profile:** Full access — Edit Profile, Kelola Konten, MFA, Backup Codes, Theme Toggle

Session initialization uses `meel_boot_session()` to set `session_name('meel')` and hardened cookie flags. Using raw `session_start()` would look for a `PHPSESSID` cookie instead of `meel`, causing the session to appear empty.

---

## File Upload Security

### Extension Validation

```php
// Video
$allowed_ext = ['mp4', 'webm', 'mkv'];
if (!in_array($ext, $allowed_ext, true) || 
    preg_match('/\.(php|phtml|sh)/i', $files['video']['name'])) {
    return ['status' => 'error', 'msg' => "Security Error / Format rejected!"];
}
```

### File Size Limits

```php
$max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
```

### Magic Bytes Validation (Drive)

```php
$header = fread($handle, 16);
if ($detectedType === 'video') { /* WebM/MKV: \x1A\x45\xDF\xA3 */ }
if ($detectedType === 'audio') { /* MP3: 0xFFFB, FLAC: 0x664C6143 */ }
```

### Centralized Magic Bytes Validation (`meel_magic_extension_ok`)

The single source of truth for server-side file-signature validation — every
upload path (Uploader video/music, thumbnails, BookUploader, DriveStorage) uses
it, replacing duplicated inline checks:

```php
// modules/core/helpers/upload.php
meel_magic_extension_ok(string $path, string $ext, string $mediaKind): string
// mediaKind: 'audio' | 'video' | 'image' | 'pdf' | 'archive'
// returns '' when valid, an error message otherwise
```

Recognized types: Ogg/Opus, FLAC, WAV/RIFF, MP3 (ID3/frame sync), MP4/M4A
(ftyp), Matroska/WebM, JPEG/PNG/WebP/GIF, PDF, and ZIP archives. Validation
never relies on `$_FILES['type']` or the extension alone.

### Atomic Filename Allocation (race-condition free)

Physical filenames are no longer allocated with a check-then-move pattern
(`while (file_exists(...))`). Every media filename is **atomically** reserved
via `meel_reserve_unique_filename()` (placeholder `fopen(..., 'x')` / O_EXCL):

```php
// modules/core/helpers/upload.php — used by Uploader, EncodeService,
// admin editors, and the arcade rhythm module
meel_reserve_unique_filename(string $dir, string $clean_name, string $ext): ?string
```

Two concurrent requests can never receive the same name; callers overwrite the
placeholder with `move_uploaded_file()` / ffmpeg `-y`. Video work-folder names
are allocated through a similar helper (`meel_allocate_unique_dir()`).

### ZIP/CBZ Archive Protection (`ArchiveGuard`)

Manga/book extraction **never** calls `ZipArchive::extractTo()` directly into
the final folder. `modules/media/ArchiveGuard.php` runs a validate → staging →
move pipeline:

```
open archive
  → inspect every entry (traversal, null byte, count, size, ratio,
    depth, symlink, extension)
  → extract one-by-one into a server-generated staging dir (random name)
  → validate the result
  → move into the final folder (rename; existing files are NOT overwritten;
    symlinks are never moved)
```

Limits are configurable via constants (defaults):

| Constant | Default | Meaning |
|---|---|---|
| `MAX_ARCHIVE_ENTRIES` | 5000 | Maximum number of entries |
| `MAX_ARCHIVE_UNCOMPRESSED_BYTES` | 2 GiB | Total expanded size |
| `MAX_ARCHIVE_ENTRY_BYTES` | 200 MiB | Single file size |
| `MAX_ARCHIVE_COMPRESSION_RATIO` | 300 | Per-entry compression ratio (zip bomb) |
| `MAX_ARCHIVE_PATH_DEPTH` | 16 | Maximum directory depth |

Rejected: absolute paths, `../`, `..\`, null bytes, symlinks, excessive depth,
non-image entries (only `jpg/jpeg/png/webp/gif/bmp/avif` for CBZ/manga), and
entry count/size/ratio over the limits. Rejections are written to the error
log without storing archive contents.

### Quota & Resource Limits

- **Disk pre-flight** — `require_disk_space()` rejects an upload before any byte
  is written (music 500MB, video 1GB + `/dev/shm` 512MB).
- **Concurrent uploads** — max 3 simultaneous uploads (`flock`-protected counter
  file + 5-minute TTL + shutdown auto-decrement).
- **Rate limit** — uploads are hourly-limited per role (admin unlimited).
- **Archives** — the entry/size/ratio limits above (zip-bomb protection).
- **Transcode** — audio timeout 600s (`TRANSCODE_AUDIO_TIMEOUT`), HLS remux 120s,
  `stream_set_timeout(30)`, and output duration validated ≥ 50% of source.

---

## Apache .htaccess Protection

### Protected Directories

- `auth/` — Database configuration & session
- `modules/` — Core business logic
- `partials/` — UI components (include-only)
- `tests/` — Test scripts (CLI only)
- `controllers/` — API endpoints
- `logs/` — System logs
- `books/upload/` — Book files (served via `books/file.php` — **login required**, see note below)
- `music/upload/` — Music files
- `video/upload/` — Video files
- `data_drive/private_admins/` — Private Drive files (denied for direct HTTP access, see [Private Drive Protection](#private-drive-protection))

> **Media uploads are not served directly by the web server.** Since the
> portability refactor, `books/upload/`, `music/upload/`, and `video/upload/`
> are real tracked directories (placeholder `.gitkeep` + hardened `.htaccess`)
> whose contents are served through PHP endpoints mapped by an internal rewrite
> in the root `.htaccess`: `video/upload/...` → `video/stream.php`,
> `music/upload/...` → `music/file.php`, `books/upload/...` → `books/file.php`
> (see [Installation §5a](installation.md#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)).
> These endpoints enforce path-traversal protection, an extension whitelist,
> and HTTP Range support.
>
> **`books/file.php` requires login** (`auth/auth.php`), consistent with the rest
> of the Books module — `index.php`, `read.php`, `read_pdf.php`, and
> `controllers/api/pdf.php` are all login-gated. Without this, manga images and
> PDFs could be downloaded directly without authentication (the raw paths were
> reachable with `HTTP 200` before the fix). Music audio stays behind
> `music/stream.php` (session marker + strict referer gate); `music/file.php`
> and `video/stream.php` intentionally have **no** login gate because the
> Music/Video index & watch pages are public by design (thumbnails must render
> for anonymous visitors).

---

## SSRF Protection (Advanced Upload)

The Advanced Upload feature (`upload_advanced.php`) lets authenticated users download media from external URLs via yt-dlp. Because yt-dlp runs **on the server**, an attacker-supplied URL that resolves to an internal address could be abused as a Server-Side Request Forgery (SSRF) vector — probing loopback services, cloud metadata endpoints (`169.254.169.254`), or other machines on the local network.

### Architecture

`modules/auth/SsrfGuard.php` is the **single source of truth** for every outbound request made on behalf of a user:

```
User URL → SsrfGuard::validate()
    ↓
Protocol allowlist (http/https only)
    ↓
Hostname denylist (defense-in-depth: localhost, .local, .internal, …)
    ↓
DNS resolution → EVERY A/AAAA record checked against explicit
private/reserved IPv4 & IPv6 ranges (one bad record = reject)
    ↓
Transcoder::processDownload() pins plain-HTTP connections to the
validated public IP + forces the original Host header
```

### Validation Rules

| Rule | Detail |
|---|---|
| **Protocol** | Only `http` and `https`. Scheme-less, protocol-relative (`//host/…`), and any other scheme are rejected |
| **Credentials** | `user:pass@` embedded in the URL is rejected (commonly used to obscure the real destination host) |
| **Hostname denylist** | `localhost`, `*.local`, `*.internal`, `*.lan`, `*.test`, `*.onion`, … (defense-in-depth only) |
| **DNS** | Every returned A/AAAA record is validated — a single non-public record rejects the URL (blocks mixed-answer DNS tricks) |
| **IP literals** | IPv4 & IPv6 literals are validated directly with no DNS round-trip |
| **Fail closed** | Unresolvable hosts, IDN/punycode hosts, and unparsable IPs are rejected |
| **Length limit** | URLs longer than 2048 characters are rejected |

### Blocked IP Ranges

**IPv4:** `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10` (CGNAT), `127.0.0.0/8`, `169.254.0.0/16` (link-local), `172.16.0.0/12`, `192.0.0.0/24`, `192.0.2.0/24` (TEST-NET-1), `192.168.0.0/16`, `198.18.0.0/15`, `198.51.100.0/24` (TEST-NET-2), `203.0.113.0/24` (TEST-NET-3), multicast/reserved `224.0.0.0/4`.

**IPv6:** `::/128`, `::1/128`, `::ffff:0:0/96` (IPv4-mapped — re-checked with the IPv4 logic), `64:ff9b::/96` (NAT64), `100::/64` (discard-only), `2001:db8::/32`, `2001:10::/28` (ORCHID), `2002::/16` (6to4), `3fff::/20`, `fc00::/7` (unique-local), `fe80::/10` (link-local), `fec0::/10` (site-local), `ff00::/8` (multicast).

### DNS Rebinding & HTTP Pinning

A naive "validate once, then request" flow is vulnerable to **DNS rebinding**: the hostname resolves to a public IP during validation, then to a private IP when the real request is made. To close this window, `SsrfGuard::pinHttpUrl()` rewrites validated `http://` URLs so the connection goes directly to the **validated public IP**, while yt-dlp receives `--add-header Host: <original-hostname>`:

```php
[$dl_url, $dl_extra] = $ssrf->pinHttpUrl($url);
// $dl_url   = http://<public-ip>[:port]/path  (no second, attacker-influenced DNS lookup)
// $dl_extra = --add-header 'Host: example.com'
```

`https://` URLs are returned untouched — TLS SNI and certificate validation require the hostname.

### Validating Forward Proxy (per-redirect enforcement)

A single pre-flight validation cannot protect against **open redirects**: yt-dlp follows redirects itself and re-resolves each target without passing it back through `SsrfGuard`. To close this gap, the download pipeline routes **all** yt-dlp traffic (metadata + download + every redirect hop) through a validating forward proxy:

```
URL → SsrfGuard::validate() + pinHttpUrl()   (pre-flight, fast fail)
   ↓
yt-dlp --proxy http://127.0.0.1:<ephemeral>  (spawned per Transcoder)
   ↓
Validating forward proxy (validating_proxy_server.php)
   • HTTP  absolute-URI → validate host → re-write to origin-form → forward
   • HTTPS CONNECT host:port → validate host → 200 tunnel → byte relay
   • Rejected target (private/reserved IP, blocked hostname, unresolvable
     host, malformed request) → 502, never forwarded
   ↓
Stream relayed back to yt-dlp
```

Key properties:

| Property | Detail |
|---|---|
| **Every hop validated** | A redirect to `127.0.0.1`, `10.x`, cloud metadata, etc. becomes a new CONNECT/absolute-URI request to the proxy, which applies `SsrfGuard::resolvePublicAddresses()` to the **destination of that hop** and refuses it |
| **Loopback-only** | The proxy binds to `127.0.0.1` on an ephemeral port — no external party can use it as an open proxy |
| **DNS-rebinding safe per hop** | Each hop is resolved once and the connection goes to the validated public IP (no separate, later lookup) |
| **Host header preserved** | The client's `Host:` header is kept for the upstream request, so virtual-host routing and TLS SNI are unaffected |
| **Fail closed** | If the proxy cannot start, the download is **refused** — there is no unprotected fallback |
| **Lifecycle** | Spawned lazily on first download via `ValidatingProxy` (proc_open of the CLI script), terminated by the `Transcoder` destructor — no orphan processes |

The `https://` limitation is therefore resolved: even though the original HTTPS URL cannot be pinned (TLS SNI), every redirect destination is re-validated by the proxy before a connection is opened.

### Defense in Depth

`Transcoder::fetchMetadata()` also calls `SsrfGuard::validate()` independently and forces the proxy flag, so even a future caller that skips `processDownload()` cannot pass an unvalidated URL — or an unprotected hop — to yt-dlp.

### Integration Points

| File | Role |
|---|---|
| `modules/auth/SsrfGuard.php` | Central validation: protocol allowlist, explicit IPv4/IPv6 range checks, DNS all-record validation, HTTP pinning |
| `modules/auth/ValidatingProxy.php` | Spawns/terminates the proxy process, exposes `--proxy` URL (loopback-only) |
| `modules/auth/validating_proxy_server.php` | CLI forward proxy: SsrfGuard on every hop (HTTP absolute-URI + CONNECT tunnel) |
| `modules/core/Transcoder.php` (facade → `modules/transcoder/DownloadService.php`) | `processDownload()` / `fetchMetadata()` call the guard and route yt-dlp through `--proxy`; fail closed if the proxy cannot start |
| `modules/autoload.php` | Autoloads the `SsrfGuard` class |

### `temp_file` & `post_encode` Security

`temp_file` never accepts a filesystem path from the client. The music-download
flow:

```
yt-dlp download → staging on the RAM disk (`/dev/shm/meel/`) under an opaque
  server-generated token name (uniqid)
  → token + metadata stored in the session (`meel_pending_music`, 1-hour expiry)
  → the browser POSTs only the token to `controllers/api/post_encode.php`
```

`post_encode.php`:
- **POST method** + CSRF verification (state-changing endpoint)
- Token validation: regex `[A-Za-z0-9._-]+`, rejects `..`, absolute paths, and null bytes
- `pathinfo()` + session match → **ownership** (user A cannot use user B's
  token) + existence check + 1-hour expiry
- The encode input is locked to the server temp dir (`getShmTempPath()`)

Transcode downloads (`download_transcode.php`) use
`Transcoder::ownsTranscodeFile()` — session-bound ownership — plus a filename
allowlist regex, so another user's transcode files cannot be guessed/downloaded.

---

## Private Drive Protection

MEeL's Cloud Drive stores private user files under
`<MEEL_HDD_DRIVE>/private_admins/<username>/...` when `MEEL_HDD_DRIVE` is set in
`auth/settings.php`, or the repo-tracked fallback folder
`data_drive/private_admins/<username>/...` when it is not (see
[Installation §5a](installation.md#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)).
Whenever the private subtree is reachable from the web server (the fallback
folder lives in the document root; HDD deployments may expose it via a
deploy-time symlink), the web server could serve files directly — bypassing
application-level authorization. Two layered controls close this gap.

### Layer 1 — Web Server Deny (tracked in repo)

`data_drive/.htaccess` is committed to the repository, so the deny rule applies to **every deployment** — whether `private_admins/` is the tracked fallback folder or a deploy-time symlink to external storage:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^private_admins/ - [F,L]
</IfModule>
```

Any direct request to `data_drive/private_admins/...` returns **HTTP 403**, regardless of file type (mp3/mp4/jpg/png/pdf/zip — everything) or HTTP method. `Options -Indexes` alone is NOT sufficient — it only hides directory listings, it does not block direct access to a file whose exact name is known.

### Layer 2 — Hard Deny at the Storage Root

`data_drive/private_admins/.htaccess` (tracked in the repo fallback folder; recreated at deploy time on the external storage target):

```apache
Options -Indexes

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
```

### Authorized Access Paths

Private files are only served through authenticated endpoints:

| Endpoint | Purpose | Checks |
|---|---|---|
| `drive/stream.php` | Inline preview / streaming (video, audio, images, PDF) | session + CSRF + ownership |
| `drive/download.php` | Download of private files | session + CSRF + ownership |

Both endpoints resolve files through `DriveStorage::getFileForDownload()`, which:

1. **Normalizes** scope (`public`/`private`) and type (`video`/`audio`/`dokumen`)
2. Applies `basename()` — strips any path-traversal component
3. Calls `verifyPrivateFileAccess()` — compares `realpath()` of the file against `realpath()` of the requester's own private root, so the file must be inside the requester's own directory (symlink escapes and `..` traversal fail)

`stream.php` also supports HTTP `Range` requests so seeking in video/audio keeps working.

### Preview URLs

`DriveStorage::listFilesByType()` never emits direct web paths for private files. Instead it builds `stream?file=…&type=…&scope=private&csrf_token=…` URLs (clean route — `stream.php?file=…` is still accepted via 301), so the browser never touches the raw storage path (which would also leak filesystem layout).

### Race-Condition Hardening (Drive Uploads)

Two TOCTOU races were closed in `DriveStorage::upload()`:

- **Quota** — the "check usage → check quota → write file" sequence runs inside a per-user `flock()` (lock file `temp/drive_quota_<md5(username)>.lock`) with usage computed fresh (bypassing the 5-minute cache), so concurrent uploads cannot collectively exceed the member quota. If the lock cannot be created, the quota check still runs non-atomically — it is never skipped.
- **Filename collisions** — `fopen($path, 'x')` (O_CREAT|O_EXCL) atomically claims a unique filename before `move_uploaded_file()`, so two simultaneous uploads with the same name cannot both win (closes the `file_exists()` → move TOCTOU).

### Regression Tests

| Test file | Coverage |
|---|---|
| `tests/unit/SsrfGuardTest.php` | Protocol allowlist, private/public IP ranges (v4 & v6), DNS resolution incl. mixed records, hostname denylist, HTTP pinning |
| `tests/unit/ValidatingProxyTest.php` | Real-proxy probes: CONNECT/GET to private targets refused, public targets tunneled, loopback-only bind, process lifecycle |
| `tests/unit/DriveSecurityTest.php` | Cross-user access, path traversal, symlink escape, realpath boundary, quota enforcement, atomic filename reservation |
| `tests/security_test.php` / `tests/functional_test.php` | Static wiring checks: guard is called, proxy flag is wired, `.htaccess` deny rules exist, stream endpoint is used for private previews |

**Run everything with one command:** `scripts/verify_security.sh` runs the
three security suites (PHPUnit security subset, `security_test.php`,
`functional_test.php`) plus a live Private Drive 403 probe and exits with a
CI-friendly code (see [test.md](test.md)).

---

## Exception Handling

### Custom Exception Classes

Since PHP 8+, MEeL uses 3 custom exceptions for specific error handling:

```php
// ProcessException — External process failure (FFmpeg, yt-dlp)
// Use for: I/O errors, exec() failures, environment issues
catch (RuntimeException $e) { /* disk space */ }
catch (ProcessException $e) { /* ffmpeg failed */ }
catch (TranscodeException $e) { /* HLS failed */ }
catch (DownloadException $e) { /* yt-dlp failed */ }
```

| Exception | Extends | Used For |
|---|---|---|
| `ProcessException` | `\RuntimeException` | External process failure: FFmpeg, yt-dlp, exec() non-zero |
| `DownloadException` | `\RuntimeException` | URL download failure: metadata parsing, connection |
| `TranscodeException` | `\RuntimeException` | Transcoding failure: HLS segments, codec, output missing |

### Best Practice

```php
try {
    $meta = $this->fetchMetadata($url);
} catch (ProcessException $e) {
    // Queue release + specific error
    $this->releaseQueue($queue_id, 'failed');
    throw $e;
}
```

---

## Disk Space Validation

Before download/transcoding operations, disk space validation:

```php
function check_disk_space(int $required_bytes, string $path): array {
    // Check free space, return ["ok" => bool, "free" => bytes, "required" => bytes]
}

function require_disk_space(int $required_bytes, string $path, string $label): void {
    // Throw RuntimeException if disk space insufficient
}
```

---

## Input Validation

### SQL Injection Prevention

All database queries use **Prepared Statements**:

```php
// ✅ SAFE
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $user_input);
$stmt->execute();

// ❌ UNSAFE - NOT USED
// $result = $conn->query("SELECT * FROM users WHERE username = '$user_input'");
```

### XSS Prevention

Output is always escaped with `htmlspecialchars()`:

```php
echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
```

**Stored XSS (hardening):** the database stores raw (canonical) data; escaping
happens **at output time**, per context:

- Plain-text HTML → `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
- Attributes/URLs → attribute-context escaping + `urlencode`/allowlist
- JSON/JS → `json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`

The audit covers every rendered user-controlled value: profile bios, media
title/description, comments, filenames, playlists, search results, and data
shown in the admin panel. Regression tests (`tests/security_test.php`) verify
payloads such as `<script>alert(1)</script>` and
`<img src=x onerror=alert(1)>` are never executed as HTML.

### Login Rate Limiting

```php
$max_login_attempts = 5;
$lockout_time = 300; // 5 minutes
```

### Theme System Security

Theme preference is stored securely:

1. **Client-side:** `localStorage` — no sensitive data, only string 'light' or 'dark'
2. **Server-side:** `users.custom_theme` — varchar(50), only accepts 'light' or 'dark'
3. **API validation:** `in_array($theme, ['light', 'dark'], true)` — rejects invalid values
4. **CSRF protection:** POST requires valid CSRF token
5. **No XSS risk:** Theme value is not escaped to HTML, only used for `data-theme` attribute

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
