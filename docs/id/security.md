# 🔒 Sistem Keamanan MEeL

Dokumentasi tentang sistem keamanan, autentikasi, otorisasi, dan proteksi yang ada di MEeL-HUB.

---

## 📋 Daftar Isi

- [Arsitektur Keamanan](#arsitektur-keamanan)
- [Role-Based Access Control (RBAC)](#role-based-access-control-rbac)
- [Session Management](#session-management)
- [CSRF Protection](#csrf-protection)
- [IP Banning & Firewall](#ip-banning--firewall)
- [Activity Logging](#activity-logging)
- [File Upload Security](#file-upload-security)
- [Apache .htaccess Protection](#apache-htaccess-protection)
- [Input Validation](#input-validation)

---

## Arsitektur Keamanan

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
│  • Session timeout (12 jam)                             │
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

### Definisi Role

| Role | Level | Hak Akses |
|------|-------|-----------|
| **Admin** | 100 | Kontrol penuh sistem |
| **Member** | 50 | Media + Cloud Drive (quota 20GB) |
| **User** | 30 | Media + komentar (tanpa Drive) |
| **Guest** | 0 | View-only, tanpa interaksi |

### Implementasi di Code

**Auth Middleware (`auth/auth.php`):**
```php
// Proteksi halaman - redirect jika belum login
if (!isset($_SESSION['user_id'])) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: ../auth/login.php?next={$next}");
    exit;
}
```

**Role Check Pattern:**
```php
// Cek role admin
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_role = $stmt->get_result()->fetch_assoc()['role'] ?? 'user';

if ($user_role !== 'admin') {
    header("Location: ../index.php?error=ditolak");
    exit();
}
```

**Guest Blocking (Like/Dislike):**
```php
// Di like.php dan MediaViewer.php
if ($user['is_active'] != 1 || $user['role'] === 'guest') {
    http_response_code(403);
    exit;
}
```

**Drive Access Control (`DriveService.php`):**
```php
final class DriveUserContext {
    public function authorize(): void {
        if (!$this->isAllowedRole()) {
            die(include __DIR__ . '/../err/denied.php');
        }
    }
    
    public function isAllowedRole(): bool {
        return in_array($this->role, ['admin', 'member'], true);
    }
}
```

### Feature Gating per Role

| Fitur | Admin | Member | User | Guest |
|-------|-------|--------|------|-------|
| Nonton Video | ✅ | ✅ | ✅ | ✅ |
| Dengar Musik | ✅ | ✅ | ✅ | ✅ |
| Like/Dislike | ✅ | ✅ | ✅ | ❌ |
| Komentar | ✅ | ✅ | ✅ | ❌ |
| Upload Video | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Upload Musik | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Books | ✅ | ✅ | ✅ | ❌ |
| Cloud Drive | ✅ (unlimited) | ✅ (20GB) | ❌ | ❌ |
| Advanced Upload | ✅ | ✅ (rate-limited) | ✅ (rate-limited) | ❌ |
| Transcoder | ✅ | ✅ | ✅ | ❌ |
| Admin Panel | ✅ | ❌ | ❌ | ❌ |

---

## Session Management

### Session Configuration

```php
// auth/config.php
$timeout = 43200;              // 12 jam
ini_set('session.gc_maxlifetime', $timeout);
session_set_cookie_params($timeout, "/");
session_name('meel');           // Cookie name: "meel"
session_start();
```

### Path Configuration

Semua path penyimpanan media dikelola melalui konstanta terpusat:

```php
// auth/config.php
define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
// ... dan seterusnya
```

### Session Hijacking Prevention

**Mechanism:** Setiap user memiliki `last_session_id` di database. Jika session ID di browser berbeda dengan yang di database, session dianggap dibajak/ditendang.

```php
// auth/config.php - Timeout check
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > $timeout) {
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();

// activity_logger.php - Kick detection (Header Redirect)
if ($current_page !== 'banned.php' && $current_page !== 'revoked.php') {
    if ($user_status && $user_status['role'] !== 'admin') {
        if (!empty($user_status['last_session_id']) && $user_status['last_session_id'] !== $current_sid) {
            session_unset();
            session_destroy();

            $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
            $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            $relative_base = rtrim('/' . ltrim(str_replace($doc_root, '', $root_dir), '/'), '/');
            $revoked_url = $relative_base . '/err/revoked.php';
            header("Location: " . $revoked_url);
            exit();
        }
    }
}
```

### Admin Kick Feature

Admin dapat menendang paksa user dari admin panel:
```php
// controllers/fun.php
$stmt_kick = $conn->prepare("UPDATE users SET 
    last_session_id = 'KICKED', 
    last_page = 'KICKED BY ADMIN', 
    last_activity = DATE_SUB(NOW(), INTERVAL 10 MINUTE) 
    WHERE username = ?");
$stmt_kick->bind_param("s", $target_username);
```

---

## CSRF Protection

### Token Generation

```php
// auth/config.php
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

### Implementation in Forms

```php
// Setiap form POST harus menyertakan token
<input type="hidden" name="csrf_token" 
       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
```

### HTMX Integration

```php
// Untuk HTMX POST requests
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

### IP Validation

```php
function validate_and_format_ip($ip) {
    // Local access detection
    if (strpos($ip, '127.') === 0 || $ip === '::1' || $ip === 'localhost') {
        return ['ip' => 'LOCAL', 'display' => 'Local Access', 'is_local' => true];
    }
    // IPv4-mapped IPv6
    if (strpos($ip, '::ffff:') === 0) {
        // Extract IPv4 part
    }
    // IPv6 validation
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { ... }
    // IPv4 validation
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { ... }
}
```

### Ban Check (Real-time)

```php
// Di activity_logger.php - dijalankan di setiap halaman (Header Redirect)
$current_page = basename($_SERVER['PHP_SELF']);
if ($current_page !== 'banned.php' && $current_page !== 'revoked.php') {
    if ($ban_res->num_rows > 0) {
        // Jika bukan admin, baru di-redirect
        if ($session_role !== 'admin') {
            $row = $ban_res->fetch_assoc();
            $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
            $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            $relative_base = rtrim('/' . ltrim(str_replace($doc_root, '', $root_dir), '/'), '/');
            $banned_url = $relative_base . '/err/banned.php';
            header("Location: " . $banned_url . "?reason=" . urlencode($row['reason']));
            exit();
        }
    }
}
```

### Admin Ban Interface

Admin dapat memban IP via form di `admin/index.php`:
```php
$stmt = $conn->prepare("INSERT IGNORE INTO ip_ban (ip_address, reason) VALUES (?, ?)");
$stmt->bind_param("ss", $ip_to_ban, $reason);
```

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

Mencatat aktivitas user ke tabel `activity_log` dengan prepared statement. Null `$media_id` ditangani dengan query terpisah agar NULL tersimpan di database (bukan 0).

### Yang Dicatat (Terintegrasi)

`log_activity()` sudah terintegrasi langsung di berbagai entry point aplikasi:

| Event | Aksi | Lokasi Integrasi |
|-------|------|------------------|
| Login sukses | `login` | `auth/login.php` |
| Logout | `logout` | `auth/logout.php` |
| Upload video | `upload_video` | `video/upload.php` |
| Upload musik | `upload_music` | `music/upload.php` |
| Upload buku | `upload_book` | `books/upload.php` |
| Download URL | `upload_url` | `upload_advanced.php` |
| Ban IP | `ban_ip` | `controllers/admin/admin_actions.php` |
| Unban IP | `unban_ip` | `controllers/admin/admin_actions.php` |
| Approve user | `approve_user` | `controllers/admin/admin_actions.php` |
| Reject user | `reject_user` | `controllers/admin/admin_actions.php` |
| Delete user | `delete_user` | `controllers/admin/admin_actions.php` |
| Kick user | `kick_user` | `controllers/admin/admin_actions.php` |

### Admin Activity Log Viewer

Halaman `admin/activity_log.php` menyediakan viewer khusus untuk audit trail:

| Fitur | Detail |
|-------|--------|
| 🔍 **Filter** | By action type (dropdown), search username/IP, rentang waktu (7–365 hari) |
| 📄 **Pagination** | 50 entry per halaman dengan navigasi prev/next |
| 📊 **Stats Cards** | 7-day activity count, unique users, total entries, page info |
| 🏷️ **Action Badges** | Color-coded: login/logout (blue), upload (green), ban (red), admin (purple) |
| 🗑️ **Cleanup Manual** | Hapus log lama (>7, 14, 30, 90, 365 hari) dengan konfirmasi SweetAlert2 + CSRF |

### Live Activity Monitor

Selain `activity_log`, admin dashboard juga menampilkan aktivitas user real-time via tabel `users`:

```php
$result_monitor = $conn->query(
    "SELECT username, role, last_activity, last_page, 
            user_agent, access_via, ip_address 
     FROM users ORDER BY last_activity DESC LIMIT 10"
);
```

Detil yang ditampilkan:
- Status online/offline (300 detik threshold)
- Halaman terakhir yang dikunjungi
- Tipe koneksi (Local/IPv4/IPv6/Cloudflare)
- Device type (Smartphone/PC/Mac)
- IP Address (dengan badge tipe)

---

## API Rate Limiting

### Arsitektur

`modules/RateLimiter.php` menyediakan **file-based rate limiter** yang melindungi API endpoint dari abuse:

```
Request → RateLimiter::check(key, endpoint)
  ↓
Baca file cache di temp/ratelimit/{md5_hash}.cache
  ↓
flock(LOCK_EX) → Increment counter → ftruncate + fwrite
  ↓
Counter > max? → Ya → HTTP 429 Too Many Requests
  ↓ Tidak
Allow request
```

### Storage

Menggunakan file JSON di `temp/ratelimit/` (tanpa schema DB tambahan):
- Setiap key+endpoint punya file sendiri (`md5(key_endpoint).cache`)
- Isi: `{"count": N, "window_start": timestamp}`
- Auto-cleanup via `GarbageCollector::run()` setiap request

### Endpoint Limits

| Endpoint | Max Request | Window | Respons pada Limit |
|----------|:-----------:|:------:|--------------------|
| **Like/Dislike** | 30 | 1 menit | HTTP 429 + HTMX HTML snippet (badge kuning "Wait Xs" + disabled buttons) |
| **Comment** | 10 | 1 menit | Redirect dengan flash error message |
| **Upload** (video/music/books) | 3 | 1 jam | — |
| **Transcode** | 5 | 1 jam | — |
| **API Generic** | 60 | 1 menit | — |

### Integrasi

```php
// controllers/api/like.php — HTMX endpoint
$rateCheck = RateLimiter::check('user_'.$userId, 'like');
if (!$rateCheck['allowed']) {
    http_response_code(429);
    header('HX-Retarget: #like-dislike-container');
    // Return HTML dengan badge "⏱️ Wait Xs"
    exit;
}

// controllers/api/delete_comment.php — redirect-based
$rateCheck = RateLimiter::check('user_'.$userId, 'comment');
if (!$rateCheck['allowed']) {
    $_SESSION['error'] = 'Terlalu banyak request.';
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

// controllers/api/WatchController.php — comment rate limit
$rateCheck = RateLimiter::check('user_'.$userId, 'comment');
if (!$rateCheck['allowed']) {
    $_SESSION['error'] = 'Terlalu banyak komentar.';
    header("Location: watch.php?id={$id}#comment-section");
    exit;
}
```

### Admin Monitoring

Rate limit status bisa dimonitor di admin dashboard:
- **Active Keys:** Jumlah key rate limit yang sedang aktif
- **Endpoints Protected:** 5 endpoint dengan limit berbeda

### Cleanup

File expired (>1 jam) otomatis dibersihkan oleh `GarbageCollector::run()` yang dipanggil di setiap request.

---

## File Upload Security

### Extension Validation

```php
// Video
$allowed_ext = ['mp4', 'webm', 'mkv'];
if (!in_array($ext, $allowed_ext, true) || 
    preg_match('/\.(php|phtml|sh)/i', $files['video']['name'])) {
    return ['status' => 'error', 'msg' => "Security Error / Format ditolak!"];
}

// Music  
$allowed_ext = ['mp3', 'opus', 'ogg', 'm4a', 'wav', 'flac'];
if (!in_array($ext, $allowed_ext, true) || 
    preg_match('/\.(php|phtml|sh)/i', $files['media']['name'])) {
    return ['status' => 'error', 'msg' => "Security Error / Format ditolak!"];
}
```

### File Size Limits

```php
// Admin: 200MB
$max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
```

### Magic Bytes Validation (Drive)

```php
// DriveService.php - validateFileByMagicBytes()
$header = fread($handle, 16);
if ($detectedType === 'video') {
    // WebM/MKV: \x1A\x45\xDF\xA3
    // MP4/MOV: ftyp
}
if ($detectedType === 'audio') {
    // MP3: 0xFFFB, FLAC: 0x664C6143, OGG: 0x4F676753
}
```

### Duration Limits

```php
// Video: admin unlimited, user max 5 menit
$max_dur = ($this->user_role === 'admin') ? 3600 : 300;
```

---

## Apache .htaccess Protection

### Directori Protection

```apache
# auth/.htaccess - Block all direct access
Order Deny,Allow
Deny from all
<FilesMatch "^$|^[^.]+$">
    Allow from all
</FilesMatch>
```

Direktori yang diproteksi:
- `auth/` — Konfigurasi database & session
- `modules/` — Core business logic
- `partials/` — UI components (include-only)
- `admin/` — Admin panel (hanya index)
- `books/upload/` — File buku
- `music/upload/` — File musik
- `video/upload/` — File video

### Root .htaccess

```apache
# Root rewrite rules
RewriteEngine On
# Custom rules here
```

---

## Exception Handling

### Custom Exception Classes

Mulai PHP 8+, MEeL menggunakan 3 custom exception untuk error handling spesifik:

```php
// ProcessException — Gagal eksekusi proses eksternal (FFmpeg, yt-dlp)
// Gunakan untuk: I/O errors, exec() failures, environment issues
catch (RuntimeException $e) { /* disk space */ }
catch (ProcessException $e) { /* ffmpeg failed */ }
catch (TranscodeException $e) { /* HLS failed */ }
catch (DownloadException $e) { /* yt-dlp failed */ }
```

| Exception | Extends | Digunakan Untuk |
|-----------|---------|-----------------|
| `ProcessException` | `\RuntimeException` | Gagal proses eksternal: FFmpeg, yt-dlp, exec() non-zero |
| `DownloadException` | `\RuntimeException` | Gagal download URL: metadata parsing, koneksi |
| `TranscodeException` | `\RuntimeException` | Gagal transcoding: HLS segments, codec, output hilang |

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

Sebelum operasi download/transcoding, validasi disk space:
```php
function check_disk_space(int $required_bytes, string $path): array {
    // Cek free space, return ["ok" => bool, "free" => bytes, "required" => bytes]
}

function require_disk_space(int $required_bytes, string $path, string $label): void {
    // Throw RuntimeException jika disk tidak cukup
}
```

---

## Input Validation

### SQL Injection Prevention

Semua query database menggunakan **Prepared Statements**:

```php
// ✅ AMAN
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $user_input);
$stmt->execute();

// ❌ TIDAK AMAN - TIDAK DIGUNAKAN
// $result = $conn->query("SELECT * FROM users WHERE username = '$user_input'");
```

### XSS Prevention

Output selalu di-escape dengan `htmlspecialchars()`:

```php
echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
```

### CSRF in All Forms

```php
// Setiap form POST memiliki CSRF token
if (!verify_csrf()) {
    $error_msg = "Sesi keamanan kadaluarsa.";
}
```

### Login Rate Limiting

```php
$max_login_attempts = 5;
$lockout_time = 300; // 5 menit

if ($_SESSION['login_fail_count'] >= $max_login_attempts) {
    $_SESSION['login_locked_until'] = time() + $lockout_time;
}
```

### Register Rate Limiting

```php
$max_reg_attempts = 3;
$reg_time_window = 3600; // 1 jam
```

---

## Best Practices

### Untuk Developer

1. **Selalu gunakan Prepared Statements** untuk query database
2. **Sanitasi semua input** POST/GET
3. **Verifikasi CSRF token** di setiap form POST
4. **Jangan percaya user input** — validasi tipe file, ukuran, dan konten
5. **Escape output** dengan `htmlspecialchars()`
6. **Jangan expose error detail** ke user non-admin

### Security Checklist

- [ ] Database credentials hanya di `auth/config.php`
- [ ] Semua `.htaccess` terpasang di direktori sensitif
- [ ] Prepared statements di semua query SQL
- [ ] CSRF token di semua form POST
- [ ] Session timeout aktif (12 jam)
- [ ] IP banning system aktif
- [ ] File upload validation (tipe, ukuran, magic bytes)
- [ ] Role checking sebelum aksi sensitif

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
