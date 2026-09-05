# 🔌 API & Controller Documentation

Dokumentasi endpoint API, controllers, dan handler AJAX/HTMX di MEeL-HUB.

---

## 📋 Daftar Isi

- [Controllers Overview](#controllers-overview)
- [Authentication Flow](#authentication-flow)
- [MFA Endpoints](#mfa-endpoints)
- [Media Interaction Endpoints](#media-interaction-endpoints)
- [Upload Endpoints](#upload-endpoints)
- [Profile Endpoints](#profile-endpoints)
- [Admin Endpoints](#admin-endpoints)

---

## Controllers Overview

Semua endpoint API berada di direktori `controllers/` dan diakses via HTTP POST/GET menggunakan AJAX atau HTMX.

```
controllers/
├── api/
│   ├── WatchController.php   # Controller watch page (Video + Music)
│   ├── like.php              # Like/dislike toggle
│   ├── comment.php           # Tambah komentar (HTMX/AJAX)
│   ├── delete_comment.php    # Hapus komentar
│   ├── auto_metadata.php     # Auto-fetch metadata (yt-dlp info)
│   ├── pdf.php               # PDF viewer proxy
│   ├── download_transcode.php# Download hasil transcode
│   ├── post_encode.php       # Post-encode music (after yt-dlp)
│   ├── theme.php             # Theme preference (GET/POST) — light/dark
│   ├── ajax_refresh.php      # Refresh fragment AJAX (search, dll.)
│   ├── server_stats.php      # Statistik server (JSON)
│   └── server_stats_sse.php  # Statistik server via Server-Sent Events
├── profile/
│   ├── fun-manage.php        # Delete media, pending deletions, cleanup
│   └── profile_edit.php      # Update profil user
├── admin/
│   ├── admin_actions.php     # Admin actions (process POST)
│   └── admin_data.php        # Admin data queries
└── system/
    ├── UpdateManager.php     # Update changelog management (OOP)
    └── mfa.php               # MFA backend controller (TOTP verify, backup codes, email)
```

### MFA Pages (di `auth/` & `admin/`)

| File | Fungsi |
|---|---|
| `auth/mfa_setup.php` | Setup MFA — generate secret, verifikasi TOTP, backup codes |
| `auth/mfa_verify.php` | Verifikasi TOTP setelah login |
| `admin/mfa_reset.php` | Admin reset MFA user yang kehilangan akses Authenticator |

---

## WatchController

**File:** `controllers/api/WatchController.php`
**Method:** Constructor-based (bukan HTTP endpoint langsung)

Controller untuk halaman watch video & music. Data diambil via `getViewData()` dan di-`extract()` ke view.

### AbstractWatchController (Base Class)

`VideoWatchController` & `MusicWatchController` mewarisi `AbstractWatchController` — base class yang menampung state & behavior bersama:

```php
abstract class AbstractWatchController
{
    protected \mysqli $conn;
    protected ?int $user_id;
    protected int $id;
    protected MediaViewer $viewer;

    public function __construct(\mysqli $conn, ?int $user_id, int $id, string $media_type);
    public function handleRequest(): void;            // recordView + komentar (CSRF + rate limit)
    public function isLoggedIn(): bool;
    protected function commentRedirectUrl(): string;  // hook URL redirect komentar
    protected function baseViewData(array $v, $rekom = null): array; // key data bersama
}
```

- `handleRequest()` — catat view + proses POST komentar dengan verifikasi CSRF & rate limit (10/menit). Redirect memakai hook `commentRedirectUrl()`.
- `baseViewData()` — mengembalikan key yang sama di semua halaman watch: `id`, `user_id`, `is_logged_in`, `v`, `user_interaction`, `comments_grouped`, `user_map`, `rekom`.
- `commentRedirectUrl()` — default `music/watch?id=...#comment-section`; `MusicWatchController` me-*override* untuk menambah `&playlist_id=...`.

### VideoWatchController

```php
$ctrl = new VideoWatchController($conn, $user_id, $id);
$ctrl->handleRequest();  // Handle POST (komentar)
extract($ctrl->getViewData());  // → $v, $video_src, $is_hls, $subtitles, dll
```

**View data yang dikembalikan:**
| Variable | Tipe | Deskripsi |
|---|---|---|
| `$v` | array | Data video + uploader info |
| `$video_src` | string | Path ke file video / playlist.m3u8 |
| `$is_hls` | bool | Apakah video HLS |
| `$vtt_src` | string | Path ke VTT thumbnail (preview) |
| `$subtitles` | array | Daftar subtitle `.vtt` terdeteksi (src, lang, label) |
| `$comments_grouped` | array | Komentar yang sudah di-group by parent |
| `$user_map` | array | Map id → username komentar |
| `$rekom` | mysqli_result | Rekomendasi video lain |
| `$is_logged_in` | bool | Status login |
| `$user_interaction` | ?string | Status like/dislike user |

### MusicWatchController

```php
$ctrl = new MusicWatchController($conn, $user_id, $id, $playlist_id);
$ctrl->handleRequest();
extract($ctrl->getViewData());  // getViewData() memanggil requireMedia() internal (redirect ke index.php jika tidak ditemukan)
```

**View data yang dikembalikan:**
| Variable | Tipe | Deskripsi |
|---|---|---|
| `$v` | array | Data audio + uploader info |
| `$playlist_id` | int | ID playlist aktif |
| `$playlist_context` | int | ID playlist untuk link navigasi |
| `$queue_query` | mysqli_result\|null | Antrean playlist (jika ada) |
| `$next_song_url` | string | URL lagu berikutnya |
| `$file_size_bytes` | int | Ukuran file audio (bytes) |
| `$fmt_label` | string | Label format audio |
| `$deskripsi` | string | Deskripsi format audio |
| `$mimeType` | string | MIME type file |
| `$preloadVal` | string | Nilai preload player (`none`/`metadata`) |
| `$comments_grouped` | array | Komentar yang sudah di-group |
| `$rekom` | mysqli_result | Rekomendasi musik lain |
| `$is_logged_in` | bool | Status login |
| `$user_interaction` | ?string | Status like/dislike user |

### Comment Rendering Helpers (`modules/core/CommentRenderer.php`)

Helper komentar yang dipakai bersama halaman watch & endpoint AJAX:

| Fungsi | Deskripsi |
|---|---|
| `render_comments($parent_id, $grouped, $level, $theme, $playlist_context)` | Render komentar nested dengan 2 tema (video/music) |
| `comment_preview($grouped, $limit = 4): array` | Preview komentar terbaru → `['text' => ..., 'latest_comment' => ?array, 'items' => array]` (hingga `$limit` komentar terbaru) |
| `render_comment_empty_state($theme): void` | Empty state "Jadilah komentar pertama" theme-aware (video=gray-300, music=gray-700) |

---

## Authentication Flow

### Login

**Endpoint:** `auth/login` (handler: `auth/login.php`)
**Method:** POST
**Auth:** None (public)

**Request:**
```html
<form method="POST" action="auth/login">
  <input type="hidden" name="csrf_token" value="...">
  <input type="text" name="username" required>
  <input type="password" name="password" required>
  <button name="login">Login</button>
</form>
```

**Response:**
- Success: Redirect ke `index.php`
- Error: Render pesan error di halaman login
- Locked: Tampilkan countdown (5 menit setelah 5x gagal)

### Logout

**Endpoint:** `auth/logout` (handler: `auth/logout.php`)
**Method:** GET
**Auth:** Required

### MFA Verification Flow

```
POST login (password benar)
  ↓
Cek users.mfa_enabled == 1 && users.mfa_secret IS NOT NULL?
  ↓ Ya                             ↓ Tidak
Simpan mfa_temp_uid ke session    Set session langsung
  ↓                                ↓
Redirect ke auth/mfa-verify       Redirect ke index.php
  ↓
User input TOTP 6 digit
  ↓
Verify via TOTP (HMAC-SHA1, 30s step, window ±1)
  ↓ Gagal
Coba backup code (password_hash/bcrypt, sekali pakai)
  ↓ Gagal total
Increment fail count → max 10 → Lock 5 menit
  ↓ Valid
Set session penuh (user_id, username, role) + mfa_verified
  ↓
Hapus mfa_temp_uid → Redirect ke index.php (hub)
```

### Registrasi

**Endpoint:** `auth/register` (handler: `auth/register.php`)
**Method:** POST
**Auth:** None (public)

**Validasi:**
- Username min 8 karakter, alfanumerik + underscore
- Password min 8 karakter
- Username tidak boleh mengandung "guest"
- Max 3 registrasi per jam per session

**Flow:**
```
Register → CSRF Check → Validasi → Insert DB (is_active=2) 
  → Tunggu admin approve
```

---

## MFA Endpoints

### MFA Setup (`auth/mfa-setup`)

**Method:** POST
**Auth:** User (login required)
**Rate Limit:** Tidak ada (hanya untuk user sendiri)

Halaman multi-step untuk mengaktifkan, mengelola, atau menonaktifkan MFA.

#### Step 1: Generate Secret

```html
<form method="POST" action="auth/mfa-setup">
  <input type="hidden" name="csrf_token" value="...">
  <button name="generate_secret" value="1">Mulai Setup MFA</button>
</form>
```

**Proses:**
1. Generate random secret 20-byte → Base32 encoding → `VARCHAR(64)`
2. Generate `otpauth://` URL → QR Code (lokal via library qrcode.min.js, 100% offline)
3. Simpan secret sementara di `$_SESSION['mfa_pending_secret']`
4. Tampilkan QR Code + manual entry (secret key, tipe TOTP, akun)

#### Step 2: Verify Code

```html
<form method="POST" action="auth/mfa-setup">
  <input type="hidden" name="csrf_token" value="...">
  <input type="hidden" name="verify_code" value="1">
  <input type="text" name="code" maxlength="6" inputmode="numeric" placeholder="000000" required>
  <button type="submit">Verifikasi & Aktifkan</button>
</form>
```

**Response:**
- Success: Generate 8 backup codes, simpan ke DB, redirect ke step backup
- Error: "Kode tidak valid" — tetap di halaman verify

**Validasi:**
- `preg_match('/^[0-9]{6}$/', $code)` — hanya 6 digit angka
- `verify_totp($secret, $code)` — TOTP dengan toleransi window ±1 (90 detik)

| Error | Penyebab |
|---|---|
| `Sesi keamanan kadaluarsa` | CSRF token tidak valid |
| `Sesi setup MFA tidak ditemukan` | Session expired, mulai ulang |
| `Kode harus 6 digit angka` | Input tidak sesuai format |
| `Kode tidak valid` | TOTP salah (waktu tidak sinkron?) |

#### Step 3: Backup Codes

Setelah verifikasi berhasil, 8 backup codes (masing-masing 6 digit angka) ditampilkan **sekali saja**:

```html
<div class="backup-code">483920</div>
<div class="backup-code">710265</div>
<!-- ... 8 codes total -->

<button onclick="downloadBackupCodes()">Download Backup Codes (.txt)</button>
<form method="POST">
  <input type="hidden" name="csrf_token" value="...">
  <button name="backup_done" value="1">Saya Sudah Menyimpannya</button>
</form>
```

**Backup codes disimpan sebagai:**
- Database: `JSON array` berisi hash `password_hash()` (bcrypt) dari tiap kode
- Tidak bisa dibaca balik (one-way hash)
- Setelah dipakai, hash dihapus dari array

#### Disable MFA

Jika MFA sudah aktif, halaman menampilkan opsi untuk menonaktifkan:

```html
<form method="POST" action="auth/mfa-setup">
  <input type="hidden" name="csrf_token" value="...">
  <button name="disable_mfa" value="1">Nonaktifkan MFA</button>
</form>
```

**Proses:** `UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_backup_codes = NULL`

---

### MFA Verify (`auth/mfa-verify`)

**Method:** POST
**Auth:** Session temp (`mfa_temp_uid`)
**Rate Limit:** 10 percobaan gagal → lock 5 menit

Halaman verifikasi TOTP yang muncul setelah login jika user memiliki MFA aktif.

**Request:**
```html
<form method="POST" action="auth/mfa-verify">
  <input type="hidden" name="csrf_token" value="...">
  <input type="hidden" name="verify" value="1">
  <input type="text" name="code" maxlength="6" inputmode="numeric"
         autocomplete="one-time-code" placeholder="000000" required>
  <button type="submit">Verifikasi</button>
</form>
```

**Proses:**
1. Cek `$_SESSION['mfa_temp_uid']` — jika tidak ada, redirect ke login
2. Ambil `mfa_secret` + `mfa_backup_codes` dari database
3. Coba TOTP dulu (`verify_totp()`)
4. Jika gagal, coba backup code (`verify_backup_code()`)
5. Jika backup code valid, update DB (hapus kode yang dipakai)
6. Jika valid → set session penuh (`user_id`, `username`, `role`, `mfa_verified=true`)
7. Jika gagal → increment fail count, max 10 → lock 5 menit

**Response:**
- Success: Redirect ke `index.php`
- Error: Tampilkan error message di halaman
- Locked: Tampilkan countdown + auto-refresh saat lock habis

**Error Responses:**
| Kondisi | Respon |
|---|---|
| `mfa_temp_uid` tidak ada + belum login penuh | Redirect ke `login.php` |
| `mfa_temp_uid` tidak ada + sudah login penuh | Redirect ke `index.php` |
| Max 10 percobaan gagal | Lock 5 menit — render halaman dengan countdown + auto-refresh |
| MFA sudah dinonaktifkan di DB | Redirect ke `login.php` (login ulang) |
| CSRF token invalid | Render error message di halaman |

**Activity Logging:**
- `mfa_verify` — MFA berhasil (TOTP)
- `mfa_verify_failed` — MFA gagal

---

### MFA Backend Controller (`controllers/system/mfa.php`)

**Method:** POST
**Auth:** User (login required)
**Rate Limit:** Password verify: 5 percobaan → lock 5 menit (session-based)

Endpoint AJAX untuk operasi MFA backend. Semua request via `fetch()` + JSON.

#### Generate Backup Codes

**Request:**
```javascript
fetch('../controllers/system/mfa.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    action: 'generate_backup',
    csrf_token: '...',
    password: '********'  // Verifikasi ulang password
  })
});
```

**Response Sukses (JSON):**
```json
{
  "status": "success",
  "message": "Kode cadangan baru berhasil dibuat.",
  "codes": ["483920", "710265", ...]  // 8 kode (6 digit)
}
```

**Response Gagal (JSON):**
```json
{
  "status": "error",
  "message": "Password salah."
}
```

#### Download Backup Codes as TXT

**Request:**
```javascript
const form = new FormData();
form.append('action', 'download_backup');
form.append('csrf_token', '...');
form.append('password', '********');

fetch('../controllers/system/mfa.php', { method: 'POST', body: form });
```

**Response:** File download `MEeL-backup-codes-{username}.txt` dengan headers:
```
Content-Type: text/plain; charset=utf-8
Content-Disposition: attachment; filename="MEeL-backup-codes-{username}.txt"
Cache-Control: no-store, private
```

**Isi file:**
```
MEeL — MFA Backup Codes
User: {username}
Generated: 2026-01-15 14:30:00

Setiap kode hanya bisa digunakan SEKALI.
Simpan di tempat yang aman!

  483920
  710265
  ...
```

**Error Responses (JSON):**
| Status | Penyebab |
|---|---|
| `401` | User tidak login |
| `Silakan login terlebih dahulu.` | Session expired |
| `Sesi keamanan kadaluarsa.` | CSRF token tidak valid |
| `User tidak ditemukan.` | User ID tidak ada di DB |
| `MFA belum diaktifkan.` | User belum setup MFA |
| `Password salah.` | Verifikasi password gagal |
| `Terlalu banyak percobaan...` | Rate limit (5 gagal → 5 menit) |
| `Aksi tidak dikenal.` | Parameter `action` tidak valid |

---

### Admin MFA Reset (`admin/mfa-reset` + `controllers/admin/admin_actions.php`)

**Method:** GET (link dengan parameter)
**Auth:** Admin only
**Rate Limit:** Tidak ada

Admin dapat mereset MFA user yang kehilangan akses ke aplikasi Authenticator.

#### View Users with MFA

Halaman `admin/mfa-reset` menampilkan daftar user dengan MFA aktif:

| Kolom | Deskripsi |
|---|---|
| Username | Nama user + ID |
| Role | Admin/Member/User (badge warna) |
| Status | Active/Pending |
| Last Activity | Waktu terakhir aktif |
| Action | Tombol "Reset MFA" (tidak untuk admin lain) |

**Stats header:** `{total_mfa} / {total_all} users have MFA enabled`

#### Reset MFA Action

**Trigger:** Klik "Reset MFA" → konfirmasi SweetAlert2 → redirect

```
GET admin/mfa-reset?reset_mfa=1&user_id=123&csrf_token=...
  ↓
die(include admin_actions.php)
  ↓
Cek admin role → Cek target user → Cek target bukan admin
  ↓
UPDATE users SET mfa_enabled=0, mfa_secret=NULL, mfa_backup_codes=NULL WHERE id=?
  ↓
log_activity(admin_id, 'reset_mfa', 'user', target_id)
  ↓
Redirect ke admin/mfa-reset?msg=reset_ok&user={username}
```

**Response Messages:**
| Message | Deskripsi |
|---|---|
| `reset_ok` | ✅ MFA berhasil di-reset |
| `csrf_invalid` | ❌ CSRF token tidak valid |
| `user_not_found` | ❌ User ID tidak ditemukan |
| `cannot_reset_admin` | ❌ Tidak bisa reset MFA admin lain |
| `reset_failed` | ❌ Query gagal |

**Keamanan:**
- Admin **tidak bisa** mereset MFA admin lain
- Admin hanya bisa mereset user dengan role `member`, `user`, atau `guest`
- Aksi dicatat di `activity_log` dengan action `reset_mfa`
- Tidak ada rate limit khusus untuk admin

---

## Media Interaction Endpoints

### Like/Dislike

**Endpoint:** `api/like` (handler: `controllers/api/like.php`)
**Method:** POST (via HTMX)
**Auth:** User (non-guest, active)
**Rate Limit:** 30 requests per menit per user

**Request (via HTMX hx-vals):**
```json
{
  "id": 123,
  "media_type": "video",
  "type": "like"
}
```

| Parameter | Tipe | Deskripsi |
|---|---|---|
| `id` | int | ID media (video/music) |
| `media_type` | string | `video` atau `music` |
| `type` | string | `like` atau `dislike` |

**Response:** HTML fragment (button container with updated counts):
```html
<div id="like-dislike-container" class="flex items-center gap-2">
  <button class="...">Like <span>42</span></button>
  <button class="...">Dislike <span>3</span></button>
</div>
```

**Error Responses:**
- `401 Unauthorized` — User tidak login
- `403 Forbidden` — User inactive/guest
- `429 Too Many Requests` — Rate limit exceeded (HTMX HTML snippet dengan badge "⏱️ Wait Xs" + disabled buttons)

### Delete Comment

**Endpoint:** `api/delete-comment?id=123` (handler: `controllers/api/delete_comment.php`)
**Method:** GET
**Auth:** User (owner of comment)
**Rate Limit:** 10 requests per menit per user

**Response:**
- Success: Redirect ke referrer dengan flash message
- Error: Redirect dengan error message
- `429 Too Many Requests` — Redirect dengan `$_SESSION['error']` + CSRF flash message

### Auto Metadata

**Endpoint:** `api/auto-metadata` (handler: `controllers/api/auto_metadata.php`)
**Method:** POST
**Auth:** Admin

Mengambil metadata otomatis dari URL (yt-dlp) untuk formulir upload:
```json
// Response
{
  "title": "Judul Video",
  "description": "Deskripsi...",
  "duration": 360,
  "thumbnail": "https://...",
  "uploader": "Channel Name"
}
```

### PDF Proxy

**Endpoint:** `api/pdf?id=123` (handler: `controllers/api/pdf.php`)
**Method:** GET
**Auth:** User/Admin

Streaming PDF untuk viewer buku:
```php
// Proteksi akses file PDF — tidak bisa diakses langsung dari URL
header("Content-Type: application/pdf");
readfile($filePath);
```

### Download Transcode

**Endpoint:** `api/download-transcode` (handler: `controllers/api/download_transcode.php`)
**Method:** GET
**Auth:** User (login required)

Download file hasil transcoding video → audio dengan header Content-Disposition yang benar.

**Parameter:**
| Parameter | Tipe | Deskripsi |
|---|---|---|
| `file` | string | Nama file transcode (mis. `song-title.mp3`) |
| `title` | string | Judul media asli (untuk nama file download) |

**Validasi file:**
- Whitelist ekstensi: `mp3`, `ogg`, `m4a`, `opus`
- Ukuran minimum: 10KB (menolak file corrupt/stub)
- `basename()` proteksi path traversal

**Response headers:**
- `Content-Type`: MIME type yang benar untuk format
- `Content-Disposition`: `attachment` dengan nama file UTF-8 (RFC 5987)
- `X-Accel-Buffering: no` (nonaktifkan buffering proxy)

**Kode error:**
| Kode | Arti |
|---|---|
| `400` | Parameter tidak valid/tidak ada |
| `401` | Belum login |
| `404` | File tidak ditemukan atau expired |
| `410` | File tidak valid/terlalu kecil (cache corrupt — transcode ulang) |
| `500` | Error database |

---

## Upload Endpoints

### Upload Video (Lokal)

**Endpoint:** `video/upload` (handler: `video/upload.php`)
**Method:** POST
**Auth:** User/Admin

**Form Data:**
```html
<form enctype="multipart/form-data">
  <input type="file" name="video" accept=".mp4,.webm,.mkv">
  <input type="text" name="title">
  <input type="text" name="description">
  <input type="file" name="thumbnail" accept="image/*">
</form>
```

### Upload Music (Lokal)

**Endpoint:** `music/upload` (handler: `music/upload.php`)
**Method:** POST
**Auth:** User/Admin

**Form Data:**
```html
<form enctype="multipart/form-data">
  <input type="file" name="media" accept=".mp3,.ogg,.flac,.wav,.m4a">
  <input type="text" name="title">
  <input type="text" name="artist">
  <input type="text" name="album">
  <input type="file" name="thumbnail" accept="image/*">
</form>
```

### Upload Buku

**Endpoint:** `books/upload` (handler: `books/upload.php`)
**Method:** POST
**Auth:** User/Admin

**Form Data:**
```html
<form enctype="multipart/form-data">
  <input type="file" name="book_file" accept=".pdf,.zip,.cbz">
  <input type="text" name="title">
  <input type="text" name="author">
  <select name="type">
    <option value="manga">Manga</option>
    <option value="pdf">PDF</option>
  </select>
  <input type="file" name="thumbnail">
</form>
```

### Advanced Upload (yt-dlp URL)

**Endpoint:** `upload` (handler: `upload_advanced.php`)
**Method:** POST
**Auth:** Admin

**Form Data:**
```html
<form method="POST">
  <input type="hidden" name="csrf_token" value="...">
  <input type="url" name="url" placeholder="https://youtube.com/watch?v=...">
  <input type="radio" name="type" value="video"> Video
  <input type="radio" name="type" value="music"> Music
  <button name="start_upload">Mulai Proses</button>
</form>
```

**Response:** Real-time streaming via overlay (`partials/ui.php`):
```
Phase 1: Download (progress bar, speed, ETA)
Phase 2: Transcode (HLS segments visualization)
Phase 3: Sprite (VTT generation)
Phase 4: Done (links to media)
```

### Transcode Video → Audio

**Endpoint:** `transcode.php`
**Method:** POST
**Auth:** User/Admin

**Form Data:**
```html
<form method="POST">
  <input type="number" name="video_id" placeholder="Video ID">
  <select name="format">
    <option value="mp3">MP3 (128kbps)</option>
    <option value="ogg">OGG (Opus)</option>
    <option value="m4a">M4A (AAC)</option>
  </select>
  <button name="start_transcode">Mulai Transcode</button>
</form>
```

**Response:** Download link to converted file

---

## Profile Endpoints

### Edit Profile

**Endpoint:** `profile/edit` (handler: `controllers/profile/profile_edit.php`)
**Method:** POST
**Auth:** User

**Form Data:**
```html
<form enctype="multipart/form-data" method="POST">
  <textarea name="bio" placeholder="Bio..."></textarea>
  <input type="file" name="avatar" accept="image/*">
  <button name="update_profile">Simpan</button>
</form>
```

**Proses:**
1. Update bio di database
2. Upload & compress avatar (400px max, JPEG quality 80)
3. Simpan sebagai `user_[id].jpg`

### View Profile

**Endpoint:** `profile/{username}` (handler: `profile/index.php`; `profile/?u=username` lama di-301 ke URL bersih)
**Method:** GET
**Auth:** Public

### Media Deletion & Cleanup

**File:** `controllers/profile/fun-manage.php` (function-based)

| Fungsi | Deskripsi |
|---|---|
| `handleDeleteVideo(int $id, int $user_id, mysqli $conn): array` | Hapus video + HLS segments + DB record |
| `handleDeleteMusic(int $id, int $user_id, mysqli $conn): array` | Hapus audio file + thumbnail + DB record |
| `savePendingDeletions(array $pending): void` | Simpan antrian hapus (batch) |
| `cleanupPendingDeletions(): int` | Eksekusi antrian hapus yang pending |
| `removeDirectoryRecursive(string $dir): void` | Hapus folder rekursif (HLS segments) |
| `logActivity(mysqli $conn, int $user_id, string $action, string $media_type, int $media_id): void` | Catat aktivitas hapus |

**Flow delete video:**
```
handleDeleteVideo()
  → Hapus file HLD (.m3u8, .ts) di storage
  → Hapus thumbnail
  → DELETE FROM video WHERE id = ?
  → logActivity()
```

---

## Admin Endpoints

Endpoint admin tersebar di beberapa file:
- `controllers/admin/admin_actions.php` — Proses POST (ban, kick, queue, cleanup)
- `controllers/admin/admin_data.php` — Data queries (counts, scans)
- `admin/index.php` — UI panel

### User Management

> ⚠️ Semua aksi di bawah adalah **form POST dengan token CSRF** (sebelumnya
> link GET — link GET bisa dipicu tag `<img>`).

| Action | Parameter | Method | Deskripsi |
|---|---|---|---|
| Approve User | `approve_id` | POST | Set `is_active=1` |
| Reject User | `reject_id` | POST | Delete user (pending) |
| Delete User | `delete_user_id` | POST | Delete user (non-admin) |
| Kick User | `kick_user` | POST | Force user offline |

### IP Ban Management

| Action | Parameter | Method | Deskripsi |
|---|---|---|---|
| Ban IP | `ban_ip=1` + `ip_target` + `ban_reason` | POST | Insert ke ip_ban |
| Unban IP | `unban_ip` | POST | Delete dari ip_ban |

Setiap POST wajib menyertakan `csrf_token`.

### Queue Management

| Action | Parameter | Method | Deskripsi |
|---|---|---|---|
| Clean Stuck | `clean_stuck_queues=1` | POST | Delete all stuck queues |
| Force Stop | `force_stop_queue=1` + `queue_id` + `task_type` | POST | Stop specific queue |

### Orphan File Cleanup

| Action | Parameter | Method | Deskripsi |
|---|---|---|---|
| Clean Orphans | `clean_orphans=1` + `files_to_delete` (JSON) | POST | Delete files not in DB |

### Guest Cleanup

| Action | Parameter | Method | Deskripsi |
|---|---|---|---|
| Clear Guests | `clear_all_guests=1` | POST | Delete inactive guests |

### Content Management

| Action | Endpoint | Deskripsi |
|---|---|---|
| Edit Video | `admin/edit-video?id=123` (admin) / `profile/edit-video?id=123` (pemilik non-admin) | Edit title, description, delete |
| Edit Music | `admin/edit-music?id=123` (admin) / `profile/edit-music?id=123` (pemilik non-admin) | Edit title, artist, album, delete |
| Update Log | `controllers/UpdateManager.php` | CRUD changelog entries |

---

## HTMX Endpoints

### Video Search

**Trigger:** Enter key on search input
**Request:** `video/search?q=keyword` (handler: `video/search_video.php`)
**Target:** `#video-container`
**Swap:** `innerHTML`

### Video Load More

**Trigger:** Click "Muat Lebih Banyak"
**Request:** `video/load-more?offset=15` (handler: `video/load_more.php`)
**Target:** `#load-more-area`
**Swap:** `outerHTML`

### Music Search

**Trigger:** Enter key on search input
**Request:** `music/search?q=keyword` (handler: `music/search_music.php`)
**Target:** `#music-list`
**Swap:** `innerHTML`

### Music Load More

**Trigger:** Click "Load More"
**Request:** `music/load-more?offset=10&format=all&artist=all` (handler: `music/load_more_music.php`)
**Target:** `#music-list`
**Swap:** `beforeend`
**Pagination server-side** — search musik kini punya pagination (offset).

### Books Search

**Trigger:** Input pencarian
**Request:** `books/search?q=keyword&type=all&offset=0` (handler: `books/search_books.php`)
**Target:** `#book-grid`
**Swap:** `innerHTML`
**Pagination server-side** via `BookRepository::searchBooks()` (24 per halaman).

### Like/Dislike

**Trigger:** Click like/dislike button
**Request:** `api/like` (handler: `controllers/api/like.php`) with `hx-vals`
**Target:** `#like-dislike-container`
**Swap:** `outerHTML`

---

## Chess Multiplayer Endpoints (`arcade/chess/controller/`)

API polling catur real-time via LAN. **Semua endpoint wajib login** (JSON `401` +
`login_required: true`) dan **CSRF** pada panggilan yang mengubah state.

| Endpoint | Method | Auth | Deskripsi |
|---|---|---|---|
| `create_room.php` | POST | login + CSRF | Buat room, return kode 6 karakter + warna `white` |
| `join_room.php` | POST | login + CSRF | Gabung room pakai kode (`room` + `csrf_token` di FormData) |
| `save_move.php` | POST | login + CSRF (body JSON) | Simpan langkah dengan validasi legal move; token tidak pernah disimpan di `move_data` |
| `get_move.php?room=X&last=N` | GET | login | Poll langkah + status koneksi lawan: `{moves, opponent_online}` |
| `check_room_status.php?room=X` | GET | login | Status room: waiting/playing/ended |
| `game_action.php` | POST | login + CSRF | `resign`, `draw_offer`, `draw_accept`, `draw_decline`, `disconnect_win` (klaim menang — server verifikasi lawan offline via `users.last_activity`), `game_over` (client mencatat checkmate/stalemate agar game selesai dipertahankan) |

Helper client di `arcade/chess/assets/js/api.js` — saat `401` redirect ke login.

---

## Rhythm Game Endpoints (MEeL!Mania, `arcade/rhythm/api/`)

API rhythm game 4-lane (osu!mania-inspired). Semua endpoint JSON; endpoint
upload/delete wajib **login + CSRF** (`config.php` memanggil `meel_boot_session()`
dan helper auth yang sama dengan modul lain).

| Endpoint | Method | Auth | Deskripsi |
|---|---|---|---|
| `arcade/rhythm/api/songs` | GET | publik | Daftar lagu (builtin + custom) — `sort` (`bpm`/`difficulty`/`newest`/`plays`/`default`), `user_id`, `search`; limit 100; respons `{songs, total, builtin_count, custom_count}` |
| `arcade/rhythm/api/beatmap?id=X` | GET | publik | Ambil beatmap — slug builtin (`songs/<id>/beatmap.json`) atau ID numerik lagu custom (increment `play_count`); respons `{id, type, title, artist, bpm, difficulty, duration, note_count, beatmap, audio_url, cover_url}` |
| `arcade/rhythm/api/upload` | POST | login + CSRF | Upload lagu custom — non-admin max **10/jam**; MP3/OGG/OPUS/FLAC/WAV ≤ 20MB & ≤ 5 menit; beatmap 10–5000 notes (validasi `t`/`l`/`e`, di-sort by time); FLAC di-transcode ke Opus; cover (JPG/PNG/GIF/WebP ≤ 5MB) → WebP 512px; mendukung edit (`song_id`) |
| `arcade/rhythm/api/delete` | POST | login + CSRF | Hapus lagu custom — owner atau admin; hapus audio + cover + beatmap.json + record DB (transaksional) |

> ⚠️ Tabel `arcade_song` & `arcade_score` dibuat lewat `arcade/rhythm/migration.sql`
> (terpisah dari `database/schema.sql` / `database/migrate.php` v1–v12).

---

## Drive API

### Upload File

**Endpoint:** `drive/upload` (handler: `drive/upload.php`)
**Method:** POST
**Auth:** Member/Admin

**Form Data:**
```html
<form method="POST" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="...">
  <input type="hidden" name="scope" value="public|private">
  <input type="file" name="file_drive">
  <button name="submit_upload">Unggah</button>
</form>
```

### Download File

**Endpoint:** `drive/download?file=xxx&type=video&scope=public&csrf_token=...` (handler: `drive/download.php`)
**Method:** GET
**Auth:** Member/Admin

### Delete File

**Endpoint:** `drive/delete` (handler: `drive/delete.php`)
**Method:** POST
**Auth:** Member/Admin

**Form Data:**
```html
<form method="POST">
  <input type="hidden" name="csrf_token" value="...">
  <input type="hidden" name="file" value="filename.mp4">
  <input type="hidden" name="type" value="video">
  <input type="hidden" name="scope" value="public">
  <button type="submit">Delete</button>
</form>
```

---

## Theme Endpoints

### Theme Preference

**Endpoint:** `api/theme.php`
**Method:** GET / POST
**Auth:** User (login required)

#### GET — Baca Preferensi

**Response:**
```json
{
  "theme": "dark"
}
```

#### POST — Update Preferensi

**Request Body:**
```json
{
  "theme": "light",
  "csrf_token": "..."
}
```

**Response:**
```json
{
  "ok": true,
  "theme": "light"
}
```

**Storage:**
- Client: `localStorage` (source of truth, anti-flash)
- Server: `users.custom_theme` column (sync untuk logged-in user)

---

## Error Response Codes

| Kode | Deskripsi | Penyebab |
|---|---|---|
| 401 | Unauthorized | User belum login |
| 403 | Forbidden | User inactive/guest, IP banned |
| 404 | Not Found | Media/komentar tidak ditemukan |
| 405 | Method Not Allowed | HTTP method tidak didukung |
| 429 | Too Many Requests | Rate limit exceeded (like: 30/menit, comment: 10/menit) |
| 500 | Server Error | Database error, FFmpeg failure |
| 503 | Service Unavailable | HDD offline, server busy |

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
