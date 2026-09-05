# 🔌 API & Controller Documentation

Documentation of API endpoints, controllers, and AJAX/HTMX handlers in MEeL-HUB.

---

## 📋 Table of Contents

- [Controllers Overview](#controllers-overview)
- [WatchController](#watchcontroller)
- [Authentication Flow](#authentication-flow)
- [MFA Endpoints](#mfa-endpoints)
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
│   ├── comment.php           # Add comment (HTMX/AJAX)
│   ├── delete_comment.php    # Delete comment
│   ├── auto_metadata.php     # Auto-fetch metadata (yt-dlp info)
│   ├── pdf.php               # PDF viewer proxy
│   ├── download_transcode.php# Download transcoded file
│   ├── post_encode.php       # Post-encode music (after yt-dlp)
│   ├── theme.php             # Theme preference (GET/POST) — light/dark
│   ├── ajax_refresh.php      # AJAX fragment refresh (search, etc.)
│   ├── server_stats.php      # Server statistics (JSON)
│   └── server_stats_sse.php  # Server statistics via Server-Sent Events
├── profile/
│   ├── fun-manage.php        # Delete media, pending deletions, cleanup
│   └── profile_edit.php      # Update user profile
├── admin/
│   ├── admin_actions.php     # Admin actions (process POST)
│   └── admin_data.php        # Admin data queries
└── system/
    ├── UpdateManager.php     # Update changelog management (OOP)
    └── mfa.php               # MFA backend controller (TOTP verify, backup codes, email)
```

### MFA Pages (in `auth/` & `admin/`)

| File | Function |
|---|---|
| `auth/mfa_setup.php` | MFA Setup — generate secret, verify TOTP, backup codes |
| `auth/mfa_verify.php` | TOTP verification after login |
| `admin/mfa_reset.php` | Admin reset MFA for users who lost Authenticator access |

---

## WatchController

**File:** `controllers/api/WatchController.php`
**Method:** Constructor-based (not a direct HTTP endpoint)

Controller for video & music watch pages. Data fetched via `getViewData()` and `extract()`ed into the view.

### AbstractWatchController (Base Class)

`VideoWatchController` & `MusicWatchController` extend `AbstractWatchController` — a base class holding shared state & behavior:

```php
abstract class AbstractWatchController
{
    protected \mysqli $conn;
    protected ?int $user_id;
    protected int $id;
    protected MediaViewer $viewer;

    public function __construct(\mysqli $conn, ?int $user_id, int $id, string $media_type);
    public function handleRequest(): void;            // recordView + comment POST (CSRF + rate limit)
    public function isLoggedIn(): bool;
    protected function commentRedirectUrl(): string;  // comment redirect URL hook
    protected function baseViewData(array $v, $rekom = null): array; // shared view-data keys
}
```

- `handleRequest()` — records the view and processes comment POSTs with CSRF verification & rate limit (10/min). Redirects via the `commentRedirectUrl()` hook.
- `baseViewData()` — returns the keys shared by every watch page: `id`, `user_id`, `is_logged_in`, `v`, `user_interaction`, `comments_grouped`, `user_map`, `rekom`.
- `commentRedirectUrl()` — defaults to `music/watch?id=...#comment-section`; `MusicWatchController` overrides it to append `&playlist_id=...`.

### VideoWatchController

```php
$ctrl = new VideoWatchController($conn, $user_id, $id);
$ctrl->handleRequest();  // Handle POST (comments)
extract($ctrl->getViewData());  // → $v, $video_src, $is_hls, $subtitles, etc.
```

**Returned view data:**
| Variable | Type | Description |
|---|---|---|
| `$v` | array | Video data + uploader info |
| `$video_src` | string | Path to video file / playlist.m3u8 |
| `$is_hls` | bool | Whether video is HLS |
| `$vtt_src` | string | Path to VTT thumbnail (preview) |
| `$subtitles` | array | Detected `.vtt` subtitle tracks (src, lang, label) |
| `$comments_grouped` | array | Comments grouped by parent |
| `$user_map` | array | Comment id → username map |
| `$rekom` | mysqli_result | Other video recommendations |
| `$is_logged_in` | bool | Login status |
| `$user_interaction` | ?string | User like/dislike status |

### MusicWatchController

```php
$ctrl = new MusicWatchController($conn, $user_id, $id, $playlist_id);
$ctrl->handleRequest();
extract($ctrl->getViewData());  // getViewData() calls requireMedia() internally (redirects to index.php if not found)
```

**Returned view data:**
| Variable | Type | Description |
|---|---|---|
| `$v` | array | Audio data + uploader info |
| `$playlist_id` | int | Active playlist ID |
| `$playlist_context` | int | Playlist ID for navigation links |
| `$queue_query` | mysqli_result\|null | Playlist queue (if any) |
| `$next_song_url` | string | Next song URL |
| `$file_size_bytes` | int | Audio file size (bytes) |
| `$fmt_label` | string | Audio format label |
| `$deskripsi` | string | Audio format description |
| `$mimeType` | string | File MIME type |
| `$preloadVal` | string | Player preload value (`none`/`metadata`) |
| `$comments_grouped` | array | Comments grouped by parent |
| `$rekom` | mysqli_result | Other music recommendations |
| `$is_logged_in` | bool | Login status |
| `$user_interaction` | ?string | User like/dislike status |

### Comment Rendering Helpers (`modules/core/CommentRenderer.php`)

Comment helpers shared by the watch pages & AJAX endpoints:

| Function | Description |
|---|---|
| `render_comments($parent_id, $grouped, $level, $theme, $playlist_context)` | Nested comment rendering with 2 themes (video/music) |
| `comment_preview($grouped, $limit = 4): array` | Latest comment preview → `['text' => ..., 'latest_comment' => ?array, 'items' => array]` (up to `$limit` latest comments) |
| `render_comment_empty_state($theme): void` | "Jadilah komentar pertama" empty state, theme-aware (video=gray-300, music=gray-700) |

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
- Success: Redirect to `index.php`
- Error: Render error message on login page
- Locked: Show countdown (5 minutes after 5 failed attempts)

### Logout

**Endpoint:** `auth/logout` (handler: `auth/logout.php`)
**Method:** GET
**Auth:** Required

### Registration

**Endpoint:** `auth/register` (handler: `auth/register.php`)
**Method:** POST
**Auth:** None (public)

**Validation:**
- Username min 8 characters, alphanumeric + underscore
- Password min 8 characters
- Username must not contain "guest"
- Max 3 registrations per hour per session

**Flow:**
```
Register → CSRF Check → Validation → Insert DB (is_active=2) 
  → Wait for admin approval
```

### MFA Verification Flow

```
POST login (password correct)
  ↓
Check users.mfa_enabled == 1 && users.mfa_secret IS NOT NULL?
  ↓ Yes                           ↓ No
Save mfa_temp_uid to session      Set session directly
  ↓                                ↓
Redirect to auth/mfa-verify       Redirect to index.php
  ↓
User inputs TOTP 6-digit code
  ↓
Verify via TOTP (HMAC-SHA1, 30s step, window ±1)
  ↓ Failed
Try backup code (password_hash/bcrypt, single-use)
  ↓ Failed completely
Increment fail count → max 10 → Lock 5 minutes
  ↓ Valid
Set full session (user_id, username, role) + mfa_verified
  ↓
Remove mfa_temp_uid → Redirect to index.php
```

---

## MFA Endpoints

### MFA Setup (`auth/mfa-setup`)

**Method:** POST
**Auth:** User (login required)
**Rate Limit:** None (user's own account only)

Multi-step page for enabling, managing, or disabling MFA.

#### Step 1: Generate Secret

```html
<form method="POST" action="auth/mfa-setup">
  <input type="hidden" name="csrf_token" value="...">
  <button name="generate_secret" value="1">Start MFA Setup</button>
</form>
```

**Process:**
1. Generate random 20-byte secret → Base32 encoding → `VARCHAR(64)`
2. Generate `otpauth://` URL → QR Code (local via qrcode.min.js, 100% offline)
3. Temporarily store secret in `$_SESSION['mfa_pending_secret']`
4. Show QR Code + manual entry (secret key, TOTP type, account)

#### Step 2: Verify Code

```html
<form method="POST" action="auth/mfa-setup">
  <input type="hidden" name="csrf_token" value="...">
  <input type="hidden" name="verify_code" value="1">
  <input type="text" name="code" maxlength="6" inputmode="numeric" placeholder="000000" required>
  <button type="submit">Verify & Activate</button>
</form>
```

**Response:**
- Success: Generate 8 backup codes, save to DB, redirect to backup step
- Error: "Invalid code" — stay on verify page

**Validation:**
- `preg_match('/^[0-9]{6}$/', $code)` — only 6 digits
- `verify_totp($secret, $code)` — TOTP with window ±1 (90 seconds)

| Error | Cause |
|---|---|
| `Security session expired` | Invalid CSRF token |
| `MFA setup session not found` | Session expired, restart |
| `Code must be 6 digits` | Input format invalid |
| `Invalid code` | TOTP wrong (time not synced?) |

#### Step 3: Backup Codes

After verification succeeds, 8 backup codes (each 6 numeric digits) are displayed **once**:

```html
<div class="backup-code">483920</div>
<div class="backup-code">710265</div>
<!-- ... 8 codes total -->

<button onclick="downloadBackupCodes()">Download Backup Codes (.txt)</button>
<form method="POST">
  <input type="hidden" name="csrf_token" value="...">
  <button name="backup_done" value="1">I've Saved Them</button>
</form>
```

**Backup codes stored as:**
- Database: `JSON array` of `password_hash()` (bcrypt) hashes
- Cannot be reversed (one-way hash)
- After use, hash removed from array

#### Disable MFA

If MFA is already active, the page shows an option to disable:

```html
<form method="POST" action="auth/mfa-setup">
  <input type="hidden" name="csrf_token" value="...">
  <button name="disable_mfa" value="1">Disable MFA</button>
</form>
```

**Process:** `UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_backup_codes = NULL`

---

### MFA Verify (`auth/mfa-verify`)

**Method:** POST
**Auth:** Session temp (`mfa_temp_uid`)
**Rate Limit:** 10 failed attempts → lock 5 minutes

TOTP verification page shown after login if user has MFA enabled.

**Request:**
```html
<form method="POST" action="auth/mfa-verify">
  <input type="hidden" name="csrf_token" value="...">
  <input type="hidden" name="verify" value="1">
  <input type="text" name="code" maxlength="6" inputmode="numeric"
         autocomplete="one-time-code" placeholder="000000" required>
  <button type="submit">Verify</button>
</form>
```

**Process:**
1. Check `$_SESSION['mfa_temp_uid']` — if missing, redirect to login
2. Fetch `mfa_secret` + `mfa_backup_codes` from database
3. Try TOTP first (`verify_totp()`)
4. If failed, try backup code (`verify_backup_code()`)
5. If backup code valid, update DB (remove used code)
6. If valid → set full session (`user_id`, `username`, `role`, `mfa_verified=true`)
7. If failed → increment fail count, max 10 → lock 5 minutes

**Response:**
- Success: Redirect to `index.php`
- Error: Show error message on page
- Locked: Show countdown + auto-refresh when lock expires

**Error Responses:**
| Condition | Response |
|---|---|
| `mfa_temp_uid` missing + not fully logged in | Redirect to `login.php` |
| `mfa_temp_uid` missing + already logged in | Redirect to `index.php` |
| Max 10 failed attempts | Lock 5 minutes — render page with countdown + auto-refresh |
| MFA disabled in DB | Redirect to `login.php` (re-login) |
| Invalid CSRF token | Render error message on page |

**Activity Logging:**
- `mfa_verify` — MFA successful (TOTP)
- `mfa_verify_failed` — MFA failed

---

### MFA Backend Controller (`controllers/system/mfa.php`)

**Method:** POST
**Auth:** User (login required)
**Rate Limit:** Password verify: 5 attempts → lock 5 minutes (session-based)

AJAX endpoint for MFA backend operations. All requests via `fetch()` + JSON.

#### Generate Backup Codes

**Request:**
```javascript
fetch('../controllers/system/mfa.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    action: 'generate_backup',
    csrf_token: '...',
    password: '********'  // Re-verify password
  })
});
```

**Success Response (JSON):**
```json
{
  "status": "success",
  "message": "New backup codes successfully created.",
  "codes": ["483920", "710265", ...]  // 8 codes (6 digits)
}
```

**Error Response (JSON):**
```json
{
  "status": "error",
  "message": "Wrong password."
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

**Response:** File download `MEeL-backup-codes-{username}.txt` with headers:
```
Content-Type: text/plain; charset=utf-8
Content-Disposition: attachment; filename="MEeL-backup-codes-{username}.txt"
Cache-Control: no-store, private
```

**File content:**
```
MEeL — MFA Backup Codes
User: {username}
Generated: 2026-01-15 14:30:00

Each code can only be used ONCE.
Store in a safe place!

  483920
  710265
  ...
```

**Error Responses (JSON):**
| Status | Cause |
|---|---|
| `401` | User not logged in |
| `Please log in first.` | Session expired |
| `Security session expired.` | Invalid CSRF token |
| `User not found.` | User ID not in DB |
| `MFA not enabled.` | User hasn't set up MFA |
| `Wrong password.` | Password verification failed |
| `Too many attempts...` | Rate limit (5 failures → 5 min) |
| `Unknown action.` | Invalid `action` parameter |

---

### Admin MFA Reset (`admin/mfa-reset` + `controllers/admin/admin_actions.php`)

**Method:** GET (link with parameters)
**Auth:** Admin only
**Rate Limit:** None

Admins can reset MFA for users who lost access to their Authenticator app.

#### View Users with MFA

Page `admin/mfa-reset` shows a list of users with MFA enabled:

| Column | Description |
|---|---|
| Username | User name + ID |
| Role | Admin/Member/User (color badge) |
| Status | Active/Pending |
| Last Activity | Last active time |
| Action | "Reset MFA" button (not for other admins) |

**Stats header:** `{total_mfa} / {total_all} users have MFA enabled`

#### Reset MFA Action

**Trigger:** Click "Reset MFA" → SweetAlert2 confirmation → redirect

```
GET admin/mfa-reset?reset_mfa=1&user_id=123&csrf_token=...
  ↓
die(include admin_actions.php)
  ↓
Check admin role → Check target user → Check target is not admin
  ↓
UPDATE users SET mfa_enabled=0, mfa_secret=NULL, mfa_backup_codes=NULL WHERE id=?
  ↓
log_activity(admin_id, 'reset_mfa', 'user', target_id)
  ↓
Redirect to admin/mfa-reset?msg=reset_ok&user={username}
```

**Response Messages:**
| Message | Description |
|---|---|
| `reset_ok` | ✅ MFA successfully reset |
| `csrf_invalid` | ❌ Invalid CSRF token |
| `user_not_found` | ❌ User ID not found |
| `cannot_reset_admin` | ❌ Cannot reset another admin's MFA |
| `reset_failed` | ❌ Query failed |

**Security:**
- Admins **cannot** reset another admin's MFA
- Admins can only reset users with role `member`, `user`, or `guest`
- Action logged in `activity_log` with action `reset_mfa`
- No special rate limit for admin

---

## Media Interaction Endpoints

### Like/Dislike

**Endpoint:** `api/like` (handler: `controllers/api/like.php`)
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
|---|---|---|
| `id` | int | Media ID (video/music) |
| `media_type` | string | `video` or `music` |
| `type` | string | `like` or `dislike` |

**Error Responses:**
- `401 Unauthorized` — User not logged in
- `403 Forbidden` — User inactive/guest
- `429 Too Many Requests` — Rate limit exceeded (HTMX HTML snippet with "⏱️ Wait Xs" badge)

### Delete Comment

**Endpoint:** `api/delete-comment?id=123` (handler: `controllers/api/delete_comment.php`)
**Method:** GET
**Auth:** User (comment owner)
**Rate Limit:** 10 requests per minute per user

**Response:**
- Success: Redirect to referrer with flash message
- Error: Redirect with error message
- `429 Too Many Requests` — Redirect with `$_SESSION['error']`

### Auto Metadata

**Endpoint:** `api/auto-metadata` (handler: `controllers/api/auto_metadata.php`)
**Method:** POST
**Auth:** Admin

Fetches automatic metadata from URL (yt-dlp) for upload forms.

### PDF Proxy

**Endpoint:** `api/pdf?id=123` (handler: `controllers/api/pdf.php`)
**Method:** GET
**Auth:** User/Admin

Streams PDF for book viewer with access protection.

### Download Transcode

**Endpoint:** `api/download-transcode` (handler: `controllers/api/download_transcode.php`)
**Method:** GET
**Auth:** User (login required)

Downloads transcoded video→audio files with proper Content-Disposition headers.

**Parameters:**
| Parameter | Type | Description |
|---|---|---|
| `file` | string | Transcoded filename (e.g. `song-title.mp3`) |
| `title` | string | Original media title (used for download filename) |

**File validation:**
- Extension whitelist: `mp3`, `ogg`, `m4a`, `opus`
- Minimum file size: 10KB (rejects corrupt/stub files)
- `basename()` path traversal protection

**Response headers:**
- `Content-Type`: correct MIME type for the format
- `Content-Disposition`: `attachment` with UTF-8 filename (RFC 5987)
- `X-Accel-Buffering: no` (disable proxy buffering)

**Error codes:**
| Code | Meaning |
|---|---|
| `400` | Missing/invalid parameters |
| `401` | Not logged in |
| `404` | File not found or expired |
| `410` | File invalid/too small (corrupt cache — re-transcode) |
| `500` | Database error |

---

## Upload Endpoints

### Upload Video (Local)

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

### Upload Music (Local)

**Endpoint:** `music/upload` (handler: `music/upload.php`)
**Method:** POST
**Auth:** User/Admin

### Upload Book

**Endpoint:** `books/upload` (handler: `books/upload.php`)
**Method:** POST
**Auth:** User/Admin

### Advanced Upload (yt-dlp URL)

**Endpoint:** `upload` (handler: `upload_advanced.php`)
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

**Endpoint:** `profile/edit` (handler: `controllers/profile/profile_edit.php`)
**Method:** POST
**Auth:** User

**Process:**
1. Update bio in database
2. Upload & compress avatar (400px max, JPEG quality 80)
3. Save as `user_[id].jpg`

### View Profile

**Endpoint:** `profile/{username}` (handler: `profile/index.php`; legacy `profile/?u=username` 301-redirects to it)
**Method:** GET
**Auth:** Public

**Query Parameters:**
| Parameter | Values | Default | Description |
|---|---|---|---|
| `tab` | `all`, `video`, `music` | `all` | Content filter tab |

**Profile as Channel:** Profile page doubles as a public channel. For logged-in users, it renders a content grid (initial batch: 12 items) with HTMX-powered infinite scroll. Guest profiles see only the profile card without content tabs or grid.

**Canonical Redirects:**
- `profile/?u=X` → 301 → `profile/X`
- `profile/<user>/<all|video|music>` → 301 → `profile/<user>?tab=<type>`

### Profile Channel (HTMX Load More)

**Endpoint:** `profile/channel-more` (handler: `profile/channel_more.php`)
**Method:** GET
**Auth:** Public

| Parameter | Required | Description |
|---|---|---|
| `u` | Yes | Target username |
| `tab` | No | `all` (default), `video`, `music` |
| `offset` | Yes | Pagination offset (starts at 12) |

Returns HTML fragment (content cards + next load-more button or "All Content Loaded" marker). Used by `profile/index.php` via `hx-get` for infinite scroll.

### Media Deletion & Cleanup

**File:** `controllers/profile/fun-manage.php` (function-based)

| Function | Description |
|---|---|
| `handleDeleteVideo(int $id, int $user_id, mysqli $conn): array` | Delete video + HLS segments + DB record |
| `handleDeleteMusic(int $id, int $user_id, mysqli $conn): array` | Delete audio + thumbnail + DB record |
| `cleanupPendingDeletions(): int` | Execute pending deletion queue |

---

## Admin Endpoints

### User Management

> ⚠️ All actions below are **POST forms with CSRF token** (they were migrated
> from GET links — a GET link can be triggered by an `<img>` tag).

| Action | Parameter | Method | Description |
|---|---|---|---|
| Approve User | `approve_id` | POST | Set `is_active=1` |
| Reject User | `reject_id` | POST | Delete user (pending) |
| Delete User | `delete_user_id` | POST | Delete user (non-admin) |
| Kick User | `kick_user` | POST | Force user offline |

### IP Ban Management

| Action | Parameter | Method | Description |
|---|---|---|---|
| Ban IP | `ban_ip=1` + `ip_target` + `ban_reason` | POST | Insert into ip_ban |
| Unban IP | `unban_ip` | POST | Delete from ip_ban |

Every POST must carry `csrf_token`.

### Queue Management

| Action | Parameter | Method | Description |
|---|---|---|---|
| Clean Stuck | `clean_stuck_queues=1` | POST | Delete all stuck queues |
| Force Stop | `force_stop_queue=1` + `queue_id` + `task_type` | POST | Stop specific queue |

### Activity Log Cleanup

| Action | Parameter | Method | Description |
|---|---|---|---|
| Clean Logs | `clean_logs=1` + `days` | POST | Delete logs older than N days |

---

## HTMX Endpoints

### Video Search

**Trigger:** Enter key on search input
**Request:** `video/search?q=keyword` (handler: `video/search_video.php`)
**Target:** `#video-container`
**Swap:** `innerHTML`

### Video Load More

**Trigger:** Click "Load More"
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

### Books Search

**Trigger:** Search input
**Request:** `books/search?q=keyword&type=all&offset=0` (handler: `books/search_books.php`)
**Target:** `#book-grid`
**Swap:** `innerHTML`
**Server-side pagination** via `BookRepository::searchBooks()` (24 per page).

### Like/Dislike

**Trigger:** Click like/dislike button
**Request:** `api/like` (handler: `controllers/api/like.php`) with `hx-vals`
**Target:** `#like-dislike-container`
**Swap:** `outerHTML`

---

## Chess Multiplayer Endpoints (`arcade/chess/controller/`)

Real-time LAN chess polling API. **All endpoints require login** (JSON `401` +
`login_required: true`) and **CSRF** on state-changing calls.

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `create_room.php` | POST | login + CSRF | Create room, return 6-char room code + color `white` |
| `join_room.php` | POST | login + CSRF | Join room with code (`room` + `csrf_token` in FormData) |
| `save_move.php` | POST | login + CSRF (JSON body) | Save move with legal move validation; token never stored in `move_data` |
| `get_move.php?room=X&last=N` | GET | login | Poll moves + opponent connectivity: `{moves, opponent_online}` |
| `check_room_status.php?room=X` | GET | login | Room status: waiting/playing/ended |
| `game_action.php` | POST | login + CSRF | `resign`, `draw_offer`, `draw_accept`, `draw_decline`, `disconnect_win` (claim win — server verifies opponent offline via `users.last_activity`), `game_over` (client records checkmate/stalemate so finished games are kept) |

Client helpers live in `arcade/chess/assets/js/api.js` — on `401` it redirects to login.

---

## Rhythm Game Endpoints (MEeL!Mania, `arcade/rhythm/api/`)

4-lane rhythm game API (osu!mania-inspired). All endpoints return JSON;
upload/delete require **login + CSRF** (`config.php` calls `meel_boot_session()`
and the same auth helpers as the other modules).

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `arcade/rhythm/api/songs` | GET | public | Song list (builtin + custom) — `sort` (`bpm`/`difficulty`/`newest`/`plays`/`default`), `user_id`, `search`; limit 100; response `{songs, total, builtin_count, custom_count}` |
| `arcade/rhythm/api/beatmap?id=X` | GET | public | Fetch beatmap — builtin slug (`songs/<id>/beatmap.json`) or custom numeric ID (increments `play_count`); response `{id, type, title, artist, bpm, difficulty, duration, note_count, beatmap, audio_url, cover_url}` |
| `arcade/rhythm/api/upload` | POST | login + CSRF | Upload custom song — non-admin max **10/hour**; MP3/OGG/OPUS/FLAC/WAV ≤ 20MB & ≤ 5 min; beatmap 10–5000 notes (validates `t`/`l`/`e`, sorted by time); FLAC auto-transcoded to Opus; cover (JPG/PNG/GIF/WebP ≤ 5MB) → WebP 512px; supports edit (`song_id`) |
| `arcade/rhythm/api/delete` | POST | login + CSRF | Delete custom song — owner or admin; removes audio + cover + beatmap.json + DB record (transactional) |

> ⚠️ The `arcade_song` & `arcade_score` tables come from `arcade/rhythm/migration.sql`
> (separate from `database/schema.sql` / `database/migrate.php` v1–v12).

---

## Drive API

### Upload File

**Endpoint:** `drive/upload` (handler: `drive/upload.php`)
**Method:** POST
**Auth:** Member/Admin

### Download File

**Endpoint:** `drive/download?file=xxx&type=video&scope=public&csrf_token=...` (handler: `drive/download.php`)
**Method:** GET
**Auth:** Member/Admin

### Delete File

**Endpoint:** `drive/delete` (handler: `drive/delete.php`)
**Method:** POST
**Auth:** Member/Admin
**Body:** `csrf_token` + `file` + `type` + `scope`

---

## Theme Endpoints

### Theme Preference

**Endpoint:** `api/theme.php`
**Method:** GET / POST
**Auth:** User (login required)

#### GET — Read Preference

**Response:**
```json
{
  "theme": "dark"
}
```

#### POST — Update Preference

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
- Server: `users.custom_theme` column (sync for logged-in users)

---

## Error Response Codes

| Code | Description | Cause |
|---|---|---|
| 401 | Unauthorized | User not logged in |
| 403 | Forbidden | Inactive/guest user, IP banned |
| 404 | Not Found | Media/comment not found |
| 405 | Method Not Allowed | HTTP method not supported |
| 429 | Too Many Requests | Rate limit exceeded (like: 30/min, comment: 10/min) |
| 500 | Server Error | Database error, FFmpeg failure |
| 503 | Service Unavailable | HDD offline, server busy |

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
