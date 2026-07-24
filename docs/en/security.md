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
- [File Upload Security](#file-upload-security)
- [Apache .htaccess Protection](#apache-htaccess-protection)
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
│              6. Prepared Statements                     │
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

---

## Session Management

### Session Configuration

```php
$timeout = 43200;              // 12 hours
ini_set('session.gc_maxlifetime', $timeout);
session_set_cookie_params($timeout, "/");
session_name('meel');
session_start();
```

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
function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || 
            !isset($_SESSION['csrf_token']) || 
            $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            return false;
        }
    }
    return true;
}
```

### HTMX Integration

```php
$token = $_SESSION['csrf_token'];
echo "<input type='hidden' name='csrf_token' value='$token'>";
```

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

`modules/RateLimiter.php` provides **file-based rate limiting** that protects API endpoints from abuse:

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
