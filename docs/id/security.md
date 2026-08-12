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
- [Proteksi SSRF (Advanced Upload)](#proteksi-ssrf-advanced-upload)
- [Proteksi Private Drive](#proteksi-private-drive)
- [Multi-Factor Authentication (MFA)](#multi-factor-authentication-mfa)
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
│              6. Multi-Factor Authentication (MFA)       │
│  • TOTP (Time-based One-Time Password) via Authenticator │
│  • Backup codes untuk recovery                          │
│  • Brute-force protection (10 attempts → 5 menit lock)  │
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

### Definisi Role

| Role | Level | Hak Akses |
|---|---|---|
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
|---|---|---|---|---|
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
| Chess Multiplayer | ✅ | ✅ | ✅ | ❌ |

---

## Session Management

### Session Configuration

```php
// modules/core/helpers/session.php — meel_boot_session()
// (auth/config.php & auth/auth_helpers.php mendelegasikan ke fungsi ini)
$timeout = 43200;              // 12 jam
ini_set('session.gc_maxlifetime', $timeout);

// Flag cookie aman (auto-detect HTTPS / X-Forwarded-Proto)
$secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

session_set_cookie_params([
    'lifetime' => $timeout,
    'path'     => '/',
    'secure'   => $secure_cookie,  // hanya via HTTPS
    'httponly' => true,            // tidak bisa dibaca JavaScript
    'samesite' => 'Lax',           // mitigasi CSRF
]);
session_name('meel');           // Cookie name: "meel"
session_start();
```

| Flag | Nilai | Proteksi |
|---|---|---|
| `Secure` | auto (HTTPS) | Cookie tidak pernah terkirim lewat HTTP polos — cegah sniffing |
| `HttpOnly` | `true` | XSS tidak bisa mencuri session cookie via JavaScript |
| `SameSite` | `Lax` | Request POST lintas-situs tidak membawa cookie (CSRF layer 1) |

Konfigurasi di atas kini **dipusatkan** di `modules/core/helpers/session.php` sebagai fungsi
`meel_boot_session()` — satu-satunya sumber kebenaran inisialisasi session. Semua entry point
(index, video, music, auth, controllers/api, err, admin) memanggilnya menggantikan pola lama
`session_name('meel'); session_start();` yang tersebar di banyak file, sehingga cookie sesi
dijamin selalu memakai flag aman. Fungsi ini **idempotent** (no-op jika session sudah aktif),
`auth/config.php` dan `auth_boot_session()` di `auth/auth_helpers.php` kini mendelegasikan
ke fungsi ini:

```php
// Cara pakai di entry point baru
require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();
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

## Multi-Factor Authentication (MFA)

### Arsitektur MFA

```
Login Flow:
  POST login → password benar
    ↓
  Cek users.mfa_enabled == 1?
    ↓ Ya                             ↓ Tidak
  Simpan mfa_temp_uid ke session    Set session langsung
    ↓                                ↓
  Redirect ke mfa_verify.php       Redirect ke index
```

### TOTP Implementation

MEeL menggunakan **TOTP (Time-based One-Time Password)** dengan algoritma:
- **HMAC-SHA1** — standard TOTP
- **6 digit** — kode verifikasi
- **30 detik** — time step
- **Window ±1** — toleransi 90 detik

### Secret Generation

```php
// modules/core/helpers.php
function generate_mfa_secret(): string {
    $random = random_bytes(20);  // 160-bit random
    $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    foreach (str_split($random) as $byte) {
        $secret .= $base32[ord($byte) & 31];
        // XOR carry untuk 5-bit encoding
    }
    return $secret;
}
```

Secret disimpan di kolom `mfa_secret` (VARCHAR(64)) di tabel `users`, di-hash? **Tidak** — secret TOTP harus bisa dibaca untuk verifikasi. Namun, jika database bocor, attacker butuh akses ke Google Authenticator atau backup codes untuk login.

### Backup Codes

- **8 backup codes** — masing-masing 8 karakter alfanumerik
- **Disimpan sebagai SHA256 hash** — tidak bisa dibaca balik
- **Sekali pakai** — setelah digunakan, hash dihapus dari daftar

```php
function generate_backup_codes(): array {
    $codes = [];
    $hashes = [];
    for ($i = 0; $i < 8; $i++) {
        $plain = bin2hex(random_bytes(4)); // 8 karakter hex
        $codes[] = $plain;
        $hashes[] = hash('sha256', $plain);
    }
    return ['plain' => $codes, 'hashed' => $hashes];
}

function verify_backup_code(string $hashedCodes, string $code): array {
    $codes = json_decode($hashedCodes, true) ?? [];
    foreach ($codes as $i => $hash) {
        if (hash_equals($hash, hash('sha256', $code))) {
            array_splice($codes, $i, 1); // Hapus yang sudah dipakai
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => $codes];
}
```

### Brute-Force Protection

```php
// Max 10 percobaan MFA gagal, lock 5 menit
$max_mfa_attempts = 10;
$mfa_lockout_time = 300; // 5 menit

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

Admin dapat mereset MFA user dari halaman `admin/mfa_reset.php`:
- **Tidak bisa reset admin lain** — hanya admin yang bersangkutan bisa menonaktifkan sendiri
- **Aksi dicatat** — `log_activity($conn, $admin_id, 'reset_mfa', 'user', $target_id)`
- **User perlu setup ulang** — MFA di-reset ke default (nonaktif)

### MFA di Profile

Halaman `profile/index.php` menampilkan status MFA dengan toggle switch visual:
- Hijau "Aktif" → link ke `auth/mfa_setup.php` untuk kelola/disable
- Abu-abu "Nonaktif" → link ke `auth/mfa_setup.php` untuk setup
- Jika aktif, backup codes juga ditampilkan di profil

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
// verify_csrf_token() — didefinisikan di modules/core/helpers.php
// Menggunakan hash_equals() untuk timing-attack safety
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
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

### Admin Actions — Form POST (bukan link GET)

Aksi admin yang mengubah state (approve/reject/delete user, kick user, unban IP)
telah dipindah dari link GET ke **form POST dengan token CSRF** — link GET bisa
dipicu oleh tag `<img>` (CSRF), form POST tidak:

```html
<form method="POST" class="inline" onsubmit="return meelConfirmForm(event, {...})">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="approve_id" value="<?= (int)$u['id'] ?>">
    <button type="submit">APPROVE</button>
</form>
```

Handler di `controllers/admin/admin_actions.php` kini membaca `$_POST`, dan
endpoint catur admin `catur.php?auto_cleanup=1` juga wajib `csrf_token`
(bridge `window.MEEL_ADMIN_CSRF`).

### Chess Multiplayer — Guard Login + CSRF

Semua endpoint `arcade/chess/controller/*.php` mewajibkan:
- **Login** — JSON `401` + `login_required: true` (client `arcade/chess/assets/js/api.js` redirect ke login).
- **CSRF** — setiap POST yang mengubah state membawa `csrf_token` (body JSON untuk `save_move`, `FormData` untuk `create_room`/`join_room`).
- Token **tidak pernah disimpan** di `moves.move_data` (tidak ter-expose ke lawan).

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

### Gerbang Trusted Proxy (`MEEL_TRUST_PROXY_HEADERS`)

Header proxy **hanya** boleh dipercaya jika request lewat proxy/CDN yang Anda
kendalikan. Konfigurasi di `auth/settings.php`:

```php
define('MEEL_TRUST_PROXY_HEADERS', false); // default aman: pakai REMOTE_ADDR saja
```

> Jika diset `true` padahal server diakses langsung, attacker bisa memalsukan
> `X-Forwarded-For` untuk mem-bypass IP-ban atau membanjiri activity log.

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
|---|---|---|
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
|---|---|
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

`modules/core/RateLimiter.php` menyediakan **file-based rate limiter** yang melindungi API endpoint dari abuse:

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
|---|:---:|:---:|---|
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

Direktori private Drive juga di-deny untuk akses HTTP langsung — lihat [Proteksi Private Drive](#proteksi-private-drive).

---

## Proteksi SSRF (Advanced Upload)

Fitur Advanced Upload (`upload_advanced.php`) mengizinkan user terautentikasi mengunduh media dari URL eksternal melalui yt-dlp. Karena yt-dlp berjalan **di server**, URL yang di-supply attacker dan me-resolve ke alamat internal bisa disalahgunakan sebagai vektor Server-Side Request Forgery (SSRF) — memindai layanan loopback, endpoint metadata cloud (`169.254.169.254`), atau mesin lain di jaringan lokal.

### Arsitektur

`modules/core/SsrfGuard.php` adalah **satu-satunya sumber kebenaran** untuk setiap request keluar yang dibuat atas nama user:

```
User URL → SsrfGuard::validate()
    ↓
Allowlist protokol (hanya http/https)
    ↓
Denylist hostname (defense-in-depth: localhost, .local, .internal, …)
    ↓
Resolusi DNS → SEMUA record A/AAAA dicek terhadap range IPv4/IPv6
private/reserved secara eksplisit (satu record buruk = tolak)
    ↓
Transcoder::processDownload() me-pin koneksi HTTP ke IP publik yang
sudah divalidasi + memaksa Host header asli
```

### Aturan Validasi

| Aturan | Detail |
|---|---|
| **Protokol** | Hanya `http` dan `https`. URL tanpa skema, protocol-relative (`//host/…`), dan skema lain ditolak |
| **Kredensial** | `user:pass@` yang disematkan di URL ditolak (sering dipakai untuk menyembunyikan host tujuan) |
| **Denylist hostname** | `localhost`, `*.local`, `*.internal`, `*.lan`, `*.test`, `*.onion`, … (hanya defense-in-depth) |
| **DNS** | Setiap record A/AAAA yang dikembalikan divalidasi — satu record non-publik menolak URL (memblokir trik DNS mixed-answer) |
| **IP literal** | Literal IPv4 & IPv6 divalidasi langsung tanpa round-trip DNS |
| **Fail closed** | Host yang tidak bisa di-resolve, host IDN/punycode, dan IP yang tidak bisa di-parse ditolak |
| **Batas panjang** | URL lebih dari 2048 karakter ditolak |

### Range IP yang Diblokir

**IPv4:** `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10` (CGNAT), `127.0.0.0/8`, `169.254.0.0/16` (link-local), `172.16.0.0/12`, `192.0.0.0/24`, `192.0.2.0/24` (TEST-NET-1), `192.168.0.0/16`, `198.18.0.0/15`, `198.51.100.0/24` (TEST-NET-2), `203.0.113.0/24` (TEST-NET-3), multicast/reserved `224.0.0.0/4`.

**IPv6:** `::/128`, `::1/128`, `::ffff:0:0/96` (IPv4-mapped — dicek ulang dengan logika IPv4), `64:ff9b::/96` (NAT64), `100::/64` (discard-only), `2001:db8::/32`, `2001:10::/28` (ORCHID), `2002::/16` (6to4), `3fff::/20`, `fc00::/7` (unique-local), `fe80::/10` (link-local), `fec0::/10` (site-local), `ff00::/8` (multicast).

### DNS Rebinding & HTTP Pinning

Alur "validasi sekali, lalu request" yang naif rentan terhadap **DNS rebinding**: hostname me-resolve ke IP publik saat validasi, lalu ke IP private saat request sungguhan dilakukan. Untuk menutup celah ini, `SsrfGuard::pinHttpUrl()` menulis ulang URL `http://` yang sudah divalidasi agar koneksi langsung ke **IP publik yang sudah divalidasi**, sementara yt-dlp menerima `--add-header Host: <hostname-asli>`:

```php
[$dl_url, $dl_extra] = $ssrf->pinHttpUrl($url);
// $dl_url   = http://<ip-publik>[:port]/path  (tidak ada DNS lookup kedua yang dipengaruhi attacker)
// $dl_extra = --add-header 'Host: example.com'
```

URL `https://` dikembalikan apa adanya — validasi SNI dan sertifikat TLS membutuhkan hostname.

### Validating Forward Proxy (penegakan per-redirect)

Validasi awal sekali tidak bisa melindungi dari **open redirect**: yt-dlp mengikuti redirect sendiri dan me-resolve ulang setiap target tanpa mengembalikannya ke `SsrfGuard`. Untuk menutup celah ini, pipeline download mengarahkan **semua** trafik yt-dlp (metadata + download + setiap hop redirect) melalui validating forward proxy:

```
URL → SsrfGuard::validate() + pinHttpUrl()   (pre-flight, fast fail)
   ↓
yt-dlp --proxy http://127.0.0.1:<ephemeral>  (di-spawn per Transcoder)
   ↓
Validating forward proxy (validating_proxy_server.php)
   • HTTP  absolute-URI → validasi host → tulis ulang origin-form → teruskan
   • HTTPS CONNECT host:port → validasi host → tunnel 200 → relay byte
   • Target ditolak (IP private/reserved, hostname diblokir, host tidak bisa
     di-resolve, request cacat) → 502, tidak pernah diteruskan
   ↓
Stream diteruskan kembali ke yt-dlp
```

Properti kunci:

| Properti | Detail |
|---|---|
| **Setiap hop divalidasi** | Redirect ke `127.0.0.1`, `10.x`, metadata cloud, dsb. menjadi request CONNECT/absolute-URI baru ke proxy, yang menerapkan `SsrfGuard::resolvePublicAddresses()` ke **tujuan hop tersebut** dan menolaknya |
| **Loopback-only** | Proxy bind ke `127.0.0.1` pada port ephemeral — tidak ada pihak eksternal yang bisa memakainya sebagai open proxy |
| **DNS-rebinding aman per hop** | Setiap hop di-resolve sekali dan koneksi menuju IP publik yang sudah divalidasi (tidak ada lookup terpisah belakangan) |
| **Host header dipertahankan** | Header `Host:` dari client dipertahankan untuk request upstream, sehingga routing virtual-host dan SNI TLS tidak terganggu |
| **Fail closed** | Jika proxy tidak bisa dijalankan, download **ditolak** — tidak ada fallback tanpa proteksi |
| **Siklus hidup** | Di-spawn lazy saat download pertama via `ValidatingProxy` (proc_open script CLI), di-terminate oleh destructor `Transcoder` — tidak ada proses yatim |

Keterbatasan `https://` dengan demikian teratasi: meskipun URL HTTPS asli tidak bisa di-pin (SNI TLS), setiap tujuan redirect tetap divalidasi ulang oleh proxy sebelum koneksi dibuka.

### Defense in Depth

`Transcoder::fetchMetadata()` juga memanggil `SsrfGuard::validate()` secara independen dan memaksa flag proxy, sehingga calon pemanggil lain yang melewati `processDownload()` pun tidak bisa meneruskan URL yang belum divalidasi — atau hop yang tidak terlindungi — ke yt-dlp.

### Titik Integrasi

| File | Peran |
|---|---|
| `modules/core/SsrfGuard.php` | Validasi sentral: allowlist protokol, cek range IPv4/IPv6 eksplisit, validasi semua record DNS, HTTP pinning |
| `modules/core/ValidatingProxy.php` | Spawn/terminate proses proxy, expose URL `--proxy` (loopback-only) |
| `modules/core/validating_proxy_server.php` | CLI forward proxy: SsrfGuard di setiap hop (HTTP absolute-URI + CONNECT tunnel) |
| `modules/core/Transcoder.php` | `processDownload()` / `fetchMetadata()` memanggil guard dan mengarahkan yt-dlp lewat `--proxy`; fail closed jika proxy tidak bisa start |
| `modules/autoload.php` | Mengautoload kelas `SsrfGuard` |

---

## Proteksi Private Drive

Cloud Drive MEeL menyimpan file private user di bawah `data_drive/private_admins/<username>/...`. Karena subtree ini berada di dalam document root web, web server bisa melayani file secara langsung — melewati otorisasi level aplikasi. Dua lapisan kontrol menutup celah ini.

### Lapisan 1 — Deny Web Server (ter-track di repo)

`data_drive/.htaccess` ter-commit di repository, sehingga aturan deny berlaku di **semua deployment** — termasuk saat `private_admins/` adalah symlink ke storage eksternal:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^private_admins/ - [F,L]
</IfModule>
```

Request langsung apa pun ke `data_drive/private_admins/...` mengembalikan **HTTP 403**, apa pun tipe filenya (mp3/mp4/jpg/png/pdf/zip — semuanya) atau metode HTTP-nya. `Options -Indexes` saja TIDAK cukup — itu hanya menyembunyikan daftar direktori, tidak memblokir akses langsung ke file yang nama persisnya diketahui.

### Lapisan 2 — Hard Deny di Root Storage

`data_drive/private_admins/.htaccess` (dibuat saat deploy di target storage):

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

### Jalur Akses yang Ber-otorisasi

File private hanya disajikan lewat endpoint terautentikasi:

| Endpoint | Fungsi | Pemeriksaan |
|---|---|---|
| `drive/stream.php` | Preview / streaming inline (video, audio, gambar, PDF) | session + CSRF + kepemilikan |
| `drive/download.php` | Unduh file private | session + CSRF + kepemilikan |

Kedua endpoint me-resolve file melalui `DriveStorage::getFileForDownload()`, yang:

1. **Menormalkan** scope (`public`/`private`) dan tipe (`video`/`audio`/`dokumen`)
2. Menerapkan `basename()` — menghapus komponen path-traversal
3. Memanggil `verifyPrivateFileAccess()` — membandingkan `realpath()` file dengan `realpath()` root private peminta, sehingga file harus berada di dalam direktori milik peminta sendiri (symlink escape dan traversal `..` gagal)

`stream.php` juga mendukung HTTP `Range` agar seeking video/audio tetap berfungsi.

### URL Preview

`DriveStorage::listFilesByType()` tidak pernah mengeluarkan path web langsung untuk file private. Sebagai gantinya ia membangun URL `stream.php?file=…&type=…&scope=private&csrf_token=…`, sehingga browser tidak pernah menyentuh path storage mentah (yang juga membocorkan struktur filesystem).

### Hardening Race Condition (Upload Drive)

Dua race TOCTOU ditutup di `DriveStorage::upload()`:

- **Kuota** — rangkaian "cek usage → cek kuota → tulis file" berjalan di dalam `flock()` per-user (lock file `temp/drive_quota_<md5(username)>.lock`) dengan usage dihitung segar (melewati cache 5 menit), sehingga upload berbarengan tidak bisa melewati kuota member secara kolektif. Jika lock tidak bisa dibuat, pengecekan kuota tetap berjalan non-atomik — tidak pernah dilewati.
- **Bentrok nama file** — `fopen($path, 'x')` (O_CREAT|O_EXCL) mengklaim nama file unik secara atomik sebelum `move_uploaded_file()`, sehingga dua upload bersamaan dengan nama sama tidak bisa sama-sama menang (menutup TOCTOU `file_exists()` → move).

### Test Regresi

| File test | Cakupan |
|---|---|
| `tests/unit/SsrfGuardTest.php` | Allowlist protokol, range IP private/publik (v4 & v6), resolusi DNS termasuk record campuran, denylist hostname, HTTP pinning |
| `tests/unit/ValidatingProxyTest.php` | Probe proxy nyata: CONNECT/GET ke target private ditolak, target publik di-tunnel, bind loopback-only, siklus hidup proses |
| `tests/unit/DriveSecurityTest.php` | Akses cross-user, path traversal, symlink escape, boundary realpath, penegakan kuota, reservasi nama atomik |
| `tests/security_test.php` / `tests/functional_test.php` | Pemeriksaan statis wiring: guard dipanggil, flag proxy ter-wire, aturan deny `.htaccess` ada, endpoint stream dipakai untuk preview private |

**Jalankan semuanya dengan satu perintah:** `scripts/verify_security.sh`
menjalankan ketiga suite keamanan (subset PHPUnit keamanan,
`security_test.php`, `functional_test.php`) plus probe live 403 Private Drive
dan keluar dengan exit code ramah-CI (lihat [test.md](test.md)).

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
|---|---|---|
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
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
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

- [ ] Database credentials hanya di `auth/settings.php`
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
