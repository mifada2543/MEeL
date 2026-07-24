# 🔌 API & Controller Documentation

Dokumentasi endpoint API, controllers, dan handler AJAX/HTMX di MEeL-HUB.

---

## 📋 Daftar Isi

- [Controllers Overview](#controllers-overview)
- [Authentication Flow](#authentication-flow)
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
│   ├── delete_comment.php    # Hapus komentar
│   ├── auto_metadata.php     # Auto-fetch metadata (yt-dlp info)
│   ├── pdf.php               # PDF viewer proxy
│   ├── download_transcode.php# Download hasil transcode
│   └── post_encode.php       # Post-encode music (after yt-dlp)
├── profile/
│   ├── fun-manage.php        # Delete media, pending deletions, cleanup
│   └── profile_edit.php      # Update profil user
├── admin/
│   ├── admin_actions.php     # Admin actions (process POST)
│   └── admin_data.php        # Admin data queries
└── system/
    └── UpdateManager.php     # Update changelog management (OOP)
```

---

## WatchController

**File:** `controllers/api/WatchController.php`  
**Method:** Constructor-based (bukan HTTP endpoint langsung)

Controller untuk halaman watch video & music. Data diambil via `getViewData()` dan di-`extract()` ke view.

### VideoWatchController

```php
$ctrl = new VideoWatchController($conn, $user_id, $id);
$ctrl->handleRequest();  // Handle POST (komentar)
extract($ctrl->getViewData());  // → $v, $video_src, $is_hls, $comments_grouped, dll
```

**View data yang dikembalikan:**
| Variable | Tipe | Deskripsi |
|----------|------|-----------|
| `$v` | array | Data video + uploader info |
| `$video_src` | string | Path ke file video / playlist.m3u8 |
| `$is_hls` | bool | Apakah video HLS |
| `$vtt_src` | string | Path ke VTT thumbnail |
| `$comments_grouped` | array | Komentar yang sudah di-group by parent |
| `$rekom` | mysqli_result | Rekomendasi video lain |
| `$is_logged_in` | bool | Status login |
| `$user_interaction` | ?string | Status like/dislike user |

### MusicWatchController

```php
$ctrl = new MusicWatchController($conn, $user_id, $id, $playlist_id);
$ctrl->handleRequest();
extract($ctrl->getViewData());
```

**View data yang dikembalikan:**
| Variable | Tipe | Deskripsi |
|----------|------|-----------|
| `$v` | array | Data audio + uploader info |
| `$file_size` | int | Ukuran file audio (bytes) |
| `$mime_type` | string | MIME type file |
| `$playlist` | array | Queue playlist (jika ada) |
| `$grouped_comments` | array | Komentar yang sudah di-group |
| `$rekomendasi` | array | Rekomendasi musik lain |
| `$playlist_id_in` | ?int | ID playlist aktif |
| `$is_logged_in` | bool | Status login |
| `$user_interaction` | ?string | Status like/dislike user |

---

## Authentication Flow

### Login

**Endpoint:** `auth/login.php`  
**Method:** POST  
**Auth:** None (public)

**Request:**
```html
<form method="POST" action="auth/login.php">
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

**Endpoint:** `auth/logout.php`  
**Method:** GET  
**Auth:** Required

### Registrasi

**Endpoint:** `auth/register.php`  
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

## Media Interaction Endpoints

### Like/Dislike

**Endpoint:** `controllers/like.php`  
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
|-----------|------|-----------|
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

**Endpoint:** `controllers/api/delete_comment.php?id=123`  
**Method:** GET  
**Auth:** User (owner of comment)  
**Rate Limit:** 10 requests per menit per user

**Response:**
- Success: Redirect ke referrer dengan flash message
- Error: Redirect dengan error message
- `429 Too Many Requests` — Redirect dengan `$_SESSION['error']` + CSRF flash message

### Auto Metadata

**Endpoint:** `controllers/api/auto_metadata.php`  
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

**Endpoint:** `controllers/api/pdf.php?id=123`  
**Method:** GET  
**Auth:** User/Admin

Streaming PDF untuk viewer buku:
```php
// Proteksi akses file PDF — tidak bisa diakses langsung dari URL
header("Content-Type: application/pdf");
readfile($filePath);
```

### Download Transcode

**Endpoint:** `controllers/api/download_transcode.php`  
**Method:** POST  
**Auth:** User/Admin

Download file hasil transcoding video → audio:
```
POST → cek file → kirim sebagai download attachment
```

---

## Upload Endpoints

### Upload Video (Lokal)

**Endpoint:** `video/upload.php`  
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

**Endpoint:** `music/upload.php`  
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

**Endpoint:** `books/upload.php`  
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

**Endpoint:** `upload_advanced.php`  
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

**Endpoint:** `controllers/profile_edit.php`  
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

**Endpoint:** `profile/index.php?u=username`  
**Method:** GET  
**Auth:** Public

### Media Deletion & Cleanup

**File:** `controllers/profile/fun-manage.php` (function-based)

| Fungsi | Deskripsi |
|--------|-----------|
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

| Action | Parameter | Method | Deskripsi |
|--------|-----------|--------|-----------|
| Approve User | `?approve_id=123` | GET | Set `is_active=1` |
| Reject User | `?reject_id=123` | GET | Delete user (pending) |
| Delete User | `?delete_user_id=123` | GET | Delete user (non-admin) |
| Kick User | `?kick_user=username` | GET | Force user offline |

### IP Ban Management

| Action | Parameter | Method | Deskripsi |
|--------|-----------|--------|-----------|
| Ban IP | `ban_ip=1` + `ip_target` + `ban_reason` | POST | Insert ke ip_ban |
| Unban IP | `?unban_ip=192.168.1.1` | GET | Delete dari ip_ban |

### Queue Management

| Action | Parameter | Method | Deskripsi |
|--------|-----------|--------|-----------|
| Clean Stuck | `clean_stuck_queues=1` | POST | Delete all stuck queues |
| Force Stop | `force_stop_queue=1` + `queue_id` + `task_type` | POST | Stop specific queue |

### Orphan File Cleanup

| Action | Parameter | Method | Deskripsi |
|--------|-----------|--------|-----------|
| Clean Orphans | `clean_orphans=1` + `files_to_delete` (JSON) | POST | Delete files not in DB |

### Guest Cleanup

| Action | Parameter | Method | Deskripsi |
|--------|-----------|--------|-----------|
| Clear Guests | `clear_all_guests=1` | POST | Delete inactive guests |

### Content Management

| Action | Endpoint | Deskripsi |
|--------|----------|-----------|
| Edit Video | `admin/edit-video.php?id=123` | Edit title, description, delete |
| Edit Music | `admin/edit-music.php?id=123` | Edit title, artist, album, delete |
| Update Log | `controllers/UpdateManager.php` | CRUD changelog entries |

---

## HTMX Endpoints

### Video Search

**Trigger:** Enter key on search input  
**Request:** `video/search_video.php?q=keyword`  
**Target:** `#video-container`  
**Swap:** `innerHTML`

### Video Load More

**Trigger:** Click "Muat Lebih Banyak"  
**Request:** `video/load_more.php?offset=15`  
**Target:** `#load-more-area`  
**Swap:** `outerHTML`

### Music Search

**Trigger:** Enter key on search input  
**Request:** `music/search_music.php?q=keyword`  
**Target:** `#music-list`  
**Swap:** `innerHTML`

### Music Load More

**Trigger:** Click "Load More"  
**Request:** `music/load_more_music.php?offset=10&format=all&artist=all`  
**Target:** `#music-list`  
**Swap:** `beforeend`

### Like/Dislike

**Trigger:** Click like/dislike button  
**Request:** `controllers/like.php` with `hx-vals`  
**Target:** `#like-dislike-container`  
**Swap:** `outerHTML`

---

## Drive API

### Upload File

**Endpoint:** `drive/upload.php`  
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

**Endpoint:** `drive/download.php?file=xxx&type=video&scope=public&csrf_token=...`  
**Method:** GET  
**Auth:** Member/Admin

### Delete File

**Endpoint:** `drive/delete.php`  
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

## Error Response Codes

| Kode | Deskripsi | Penyebab |
|------|-----------|----------|
| 401 | Unauthorized | User belum login |
| 403 | Forbidden | User inactive/guest, IP banned |
| 404 | Not Found | Media/komentar tidak ditemukan |
| 429 | Too Many Requests | Rate limit exceeded (like: 30/menit, comment: 10/menit) |
| 500 | Server Error | Database error, FFmpeg failure |
| 503 | Service Unavailable | HDD offline, server busy |

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
