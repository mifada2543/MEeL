# 🔌 API & Controller Documentation

Documentation of API endpoints, controllers, and AJAX/HTMX handlers in MEeL-HUB.

---

## 📋 Table of Contents

- [Controllers Overview](#controllers-overview)
- [Authentication Flow](#authentication-flow)
- [Media Interaction Endpoints](#media-interaction-endpoints)
- [Upload Endpoints](#upload-endpoints)
- [Profile Endpoints](#profile-endpoints)
- [Admin Endpoints](#admin-endpoints)

---

## Controllers Overview

All API endpoints are located in the `controllers/` directory and accessed via HTTP POST/GET using AJAX or HTMX.

```
controllers/
├── api/
│   ├── WatchController.php   # Watch page controller (Video + Music)
│   ├── like.php              # Like/dislike toggle
│   ├── delete_comment.php    # Delete comment
│   ├── auto_metadata.php     # Auto-fetch metadata (yt-dlp info)
│   ├── pdf.php               # PDF viewer proxy
│   ├── download_transcode.php# Download transcoded file
│   └── post_encode.php       # Post-encode music (after yt-dlp)
├── profile/
│   ├── fun-manage.php        # Delete media, pending deletions, cleanup
│   └── profile_edit.php      # Update user profile
├── admin/
│   ├── admin_actions.php     # Admin actions (process POST)
│   └── admin_data.php        # Admin data queries
└── system/
    └── UpdateManager.php     # Update changelog management (OOP)
```

---

## Authentication Flow

### Login

**Endpoint:** `auth/login.php`  
**Method:** POST  
**Auth:** None (public)

**Response:**
- Success: Redirect to `index.php`
- Error: Render error message on login page
- Locked: Show countdown (5 minutes after 5 failed attempts)

### Logout

**Endpoint:** `auth/logout.php`  
**Method:** GET  
**Auth:** Required

### Registration

**Endpoint:** `auth/register.php`  
**Method:** POST  
**Auth:** None (public)

**Flow:**
```
Register → CSRF Check → Validation → Insert DB (is_active=2) 
  → Wait for admin approval
```

---

## Media Interaction Endpoints

### Like/Dislike

**Endpoint:** `controllers/like.php`  
**Method:** POST (via HTMX)  
**Auth:** User (non-guest, active)  
**Rate Limit:** 30 requests per minute per user

**Request (via HTMX hx-vals):**
```json
{
  "id": 123,
  "media_type": "video",
  "type": "like"
}
```

| Parameter | Type | Description |
|-----------|------|-----------|
| `id` | int | Media ID (video/music) |
| `media_type` | string | `video` or `music` |
| `type` | string | `like` or `dislike` |

**Error Responses:**
- `401 Unauthorized` — User not logged in
- `403 Forbidden` — User inactive/guest
- `429 Too Many Requests` — Rate limit exceeded (HTMX HTML snippet with "⏱️ Wait Xs" badge)

### Delete Comment

**Endpoint:** `controllers/api/delete_comment.php?id=123`  
**Method:** GET  
**Auth:** User (comment owner)  
**Rate Limit:** 10 requests per minute per user

**Response:**
- Success: Redirect to referrer with flash message
- Error: Redirect with error message
- `429 Too Many Requests` — Redirect with `$_SESSION['error']`

### Auto Metadata

**Endpoint:** `controllers/api/auto_metadata.php`  
**Method:** POST  
**Auth:** Admin

Fetches automatic metadata from URL (yt-dlp) for upload forms.

### PDF Proxy

**Endpoint:** `controllers/api/pdf.php?id=123`  
**Method:** GET  
**Auth:** User/Admin

Streams PDF for book viewer with access protection.

### Download Transcode

**Endpoint:** `controllers/api/download_transcode.php`  
**Method:** POST  
**Auth:** User/Admin

Downloads transcoded video→audio files.

---

## Upload Endpoints

### Upload Video (Local)

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

### Upload Music (Local)

**Endpoint:** `music/upload.php`  
**Method:** POST  
**Auth:** User/Admin

### Upload Book

**Endpoint:** `books/upload.php`  
**Method:** POST  
**Auth:** User/Admin

### Advanced Upload (yt-dlp URL)

**Endpoint:** `upload_advanced.php`  
**Method:** POST  
**Auth:** Admin

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

---

## Profile Endpoints

### Edit Profile

**Endpoint:** `controllers/profile_edit.php`  
**Method:** POST  
**Auth:** User

**Process:**
1. Update bio in database
2. Upload & compress avatar (400px max, JPEG quality 80)
3. Save as `user_[id].jpg`

### View Profile

**Endpoint:** `profile/index.php?u=username`  
**Method:** GET  
**Auth:** Public

### Media Deletion & Cleanup

**File:** `controllers/profile/fun-manage.php` (function-based)

| Function | Description |
|----------|-----------|
| `handleDeleteVideo(int $id, int $user_id, mysqli $conn): array` | Delete video + HLS segments + DB record |
| `handleDeleteMusic(int $id, int $user_id, mysqli $conn): array` | Delete audio + thumbnail + DB record |
| `cleanupPendingDeletions(): int` | Execute pending deletion queue |

---

## Admin Endpoints

### User Management

| Action | Parameter | Method | Description |
|--------|-----------|--------|-----------|
| Approve User | `?approve_id=123` | GET | Set `is_active=1` |
| Reject User | `?reject_id=123` | GET | Delete user (pending) |
| Delete User | `?delete_user_id=123` | GET | Delete user (non-admin) |
| Kick User | `?kick_user=username` | GET | Force user offline |

### IP Ban Management

| Action | Parameter | Method | Description |
|--------|-----------|--------|-----------|
| Ban IP | `ban_ip=1` + `ip_target` + `ban_reason` | POST | Insert into ip_ban |
| Unban IP | `?unban_ip=192.168.1.1` | GET | Delete from ip_ban |

### Queue Management

| Action | Parameter | Method | Description |
|--------|-----------|--------|-----------|
| Clean Stuck | `clean_stuck_queues=1` | POST | Delete all stuck queues |
| Force Stop | `force_stop_queue=1` + `queue_id` + `task_type` | POST | Stop specific queue |

### Activity Log Cleanup

| Action | Parameter | Method | Description |
|--------|-----------|--------|-----------|
| Clean Logs | `clean_logs=1` + `days` | POST | Delete logs older than N days |

---

## Error Response Codes

| Code | Description | Cause |
|------|-----------|----------|
| 401 | Unauthorized | User not logged in |
| 403 | Forbidden | Inactive/guest user, IP banned |
| 404 | Not Found | Media/comment not found |
| 429 | Too Many Requests | Rate limit exceeded (like: 30/min, comment: 10/min) |
| 500 | Server Error | Database error, FFmpeg failure |
| 503 | Service Unavailable | HDD offline, server busy |

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
