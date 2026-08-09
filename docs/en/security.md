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
|------|-------|-----------|
| **Admin** | 100 | Full system control |
| **Member** | 50 | Media + Cloud Drive (20GB quota) |
| **User** | 30 | Media + comments (no Drive) |
| **Guest** | 0 | View-only, no interaction |

### Feature Gating per Role

| Feature | Admin | Member | User | Guest |
|---------|-------|--------|------|-------|
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
|------|-------|------------|
| `Secure` | auto (HTTPS) | Cookie never sent over plain HTTP — prevents sniffing |
| `HttpOnly` | `true` | XSS cannot steal the session cookie via JavaScript |
| `SameSite` | `Lax` | Cross-site POST requests don't carry the cookie (CSRF layer 1) |

Applied in both `auth/config.php` and `auth/auth_helpers.php` (`auth_boot_session()`).

### Session Hijacking Prevention

Every user has a `last_session_id` in the database. If the browser's session ID differs, the session is considered hijacked/kicked:

```php
if ($user_status['last_session_id'] !== $current_sid) {
    session_unset();
    session_destroy();
    header("Location: .../err/revoked.php");
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
// verify_csrf_token() — defined in modules/core/helpers.php
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

Admin state-changing actions (approve/reject/delete user, kick user, unban IP)
were migrated from GET links to **POST forms with CSRF token** — a GET link can
be triggered by a `<img>` tag (CSRF), a POST form cannot:

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

Checked on every page load. Non-admin users are redirected to `err/banned.php` with the ban reason.

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
|-------|------|------------------|
| Successful login | `login` | `auth/login.php` |
| Logout | `logout` | `auth/logout.php` |
| Video upload | `upload_video` | `video/upload.php` |
| Music upload | `upload_music` | `music/upload.php` |
| Book upload | `upload_book` | `books/upload.php` |
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
|---------|--------|
| 🔍 **Filter** | By action type (dropdown), search username/IP, date range (7–365 days) |
| 📄 **Pagination** | 50 entries per page with prev/next navigation |
| 📊 **Stats Cards** | 7-day activity count, unique users, total entries, page info |
| 🏷️ **Action Badges** | Color-coded: login/logout (blue), upload (green), ban (red), admin (purple) |
| 🗑️ **Manual Cleanup** | Delete old logs (>7, 14, 30, 90, 365 days) with SweetAlert2 confirmation + CSRF |

---

## API Rate Limiting

### Architecture

`modules/core/RateLimiter.php` provides **file-based rate limiting** that protects API endpoints from abuse:

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
|----------|:-----------:|:------:|--------------------|
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
  Redirect to mfa_verify.php     Redirect to index
```

### TOTP Implementation

MEeL uses **TOTP (Time-based One-Time Password)** with algorithm:
- **HMAC-SHA1** — standard TOTP
- **6 digits** — verification code
- **30 seconds** — time step
- **Window ±1** — 90 seconds tolerance

### Secret Generation

```php
// modules/core/helpers.php
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

- **8 backup codes** — each 8 alphanumeric characters
- **Stored as SHA256 hash** — one-way, cannot be reversed
- **Single-use** — hash removed from array after use

```php
function generate_backup_codes(): array {
    $codes = [];
    $hashes = [];
    for ($i = 0; $i < 8; $i++) {
        $plain = bin2hex(random_bytes(4)); // 8 hex characters
        $codes[] = $plain;
        $hashes[] = hash('sha256', $plain);
    }
    return ['plain' => $codes, 'hashed' => $hashes];
}

function verify_backup_code(string $hashedCodes, string $code): array {
    $codes = json_decode($hashedCodes, true) ?? [];
    foreach ($codes as $i => $hash) {
        if (hash_equals($hash, hash('sha256', $code))) {
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
- Green "Active" → link to `auth/mfa_setup.php` for management/disable
- Gray "Inactive" → link to `auth/mfa_setup.php` for setup
- If active, backup codes also displayed in profile

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

---

## Apache .htaccess Protection

### Protected Directories

- `auth/` — Database configuration & session
- `modules/` — Core business logic
- `partials/` — UI components (include-only)
- `tests/` — Test scripts (CLI only)
- `controllers/` — API endpoints
- `logs/` — System logs
- `books/upload/` — Book files
- `music/upload/` — Music files
- `video/upload/` — Video files

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
|-----------|---------|-----------------|
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

### Login Rate Limiting

```php
$max_login_attempts = 5;
$lockout_time = 300; // 5 minutes
```

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
