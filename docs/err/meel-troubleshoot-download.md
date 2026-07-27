# MEeL Download/Upload Troubleshooting Guide for AI Agents

> **Audience:** AI agents (Buffy, code-searcher, browser-use, etc.)  
> **Project:** MEeL — PHP media hub (XAMPP, yt-dlp, FFmpeg)  
> **Typical issue:** "upload_advanced.php tidak mau download video"

---

## 1. 🧠 Understand the Download Flow

```
upload_advanced.php (form POST)
  → Transcoder::processDownload($url, $type)
    → fetchMetadata()          [yt-dlp --skip-download --print-json]
    → showMEeLOverlay()        [include partials/ui.php → streaming UI]
    → popen("timeout 900 yt-dlp ...")
    → finalizeVideo()          [HLS transcode + sprite + move to HDD]
      → meelDone() JS
```

**Key classes:** `modules/core/Transcoder.php`, `modules/core/Uploader.php`  
**Key dependencies:** yt-dlp, FFmpeg, FFprobe, node, cookies.txt

---

## 2. 🔴 Most Common Bug: Wrong `base_path`

### Symptom
- Download overlay doesn't appear at all
- No progress shown, page seems to do nothing
- Or error in console: `meelError is not defined`

### Root Cause
Files in `modules/core/` use `dirname(__DIR__)` which resolves to `.../modules/`, NOT the project root.

### Fix
Change `dirname(__DIR__)` to `dirname(__DIR__, 2)` in files inside `modules/core/`.

```php
// WRONG (modules/core/Transcoder.php)
$this->base_path = dirname(__DIR__);    // → .../MEeL/modules/

// CORRECT
$this->base_path = dirname(__DIR__, 2); // → .../MEeL/
```

### What it affects (when `base_path` is wrong):
| Path used | Correct location | Wrong location |
|-----------|-----------------|----------------|
| `cookies.txt` | `.../MEeL/cookies.txt` | `.../MEeL/modules/cookies.txt` |
| `partials/ui.php` | `.../MEeL/partials/ui.php` | `.../MEeL/modules/partials/ui.php` |
| `music/upload/file/` | `.../MEeL/music/upload/file/` | `.../MEeL/modules/music/upload/file/` |

### 🔍 How to check
```bash
php -r "echo dirname(__DIR__);" 2>/dev/null   # Test from modules/core/
```
Or just look at the file location. Files in `modules/core/` need `dirname(__DIR__, 2)`.
Files in `auth/`, `drive/`, `partials/`, `tests/` need only `dirname(__DIR__)`.

---

## 3. 🔧 Systematic Diagnostic Steps

### Step 1: Check Environment Basics
```bash
which yt-dlp          # Must be installed
which ffmpeg          # Must be installed
which ffprobe         # Must be installed
which node            # Must be installed (yt-dlp JS runtime)
ls -la cookies.txt    # Must exist at project root
php -v                # Must be 7.0+ (for dirname($path, 2))
```

### Step 2: Check PHP Functions
```bash
php -r "echo function_exists('popen') ? 'YES' : 'NO';"
php -r "echo ini_get('disable_functions') ?: 'none';"
```
Required: `popen`, `exec`, `shell_exec`, `proc_open`, `proc_close` must NOT be disabled.

### Step 3: Check Storage Paths
```bash
ls -la /media/muhammaddaffa/MEeL/media/     # HDD mount
ls -la /dev/shm/meel/temp/                  # RAM disk staging
df -h /dev/shm                              # Need > 512MB free
```

### Step 4: Check File Paths used by PHP
For each `dirname(__DIR__)` in the codebase, verify the resolved path:
```bash
# Check which files have the pattern
grep -rn "dirname(__DIR__)" modules/core/
```
Compare the file's depth from project root. If file is at `modules/core/X.php`, `dirname(__DIR__)` goes up 1 level to `modules/` — which is wrong if it expects project root.

### Step 5: Test with Browser Agent
```json
{
  "agent_type": "browser-use",
  "prompt": "1. Login at /auth/login.php with credentials
   2. Navigate to /upload_advanced.php
   3. Submit a short YouTube URL
   4. Check if overlay appears and progresses through phases",
  "params": { "url": "http://localhost/auth/login.php" }
}
```

---

## 4. ⚠️ Other Common Issues

### Double `ui.php` include
`showMEeLOverlay()` includes `ui.php`, then the main template includes it again.
**Fix:** Wrap the body include with a condition:
```php
<?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $message === 'busy' || $message === 'rate_limit'): ?>
    <?php include 'partials/ui.php'; ?>
<?php endif; ?>
```

### Early error handlers before overlay loads
The global error handler and try-catch blocks call `meelError()`, but if it's called before `showMEeLOverlay()` includes `ui.php`, the function is undefined.
**Fix:** Add a fallback in the catch blocks:
```php
echo "<script>
  if (typeof meelError === 'function') {
    meelError(" . json_encode($msg) . ");
  } else {
    document.write('<div style=\"...\">' + " . json_encode($msg) . " + '</div>');
  }
</script>";
```

### yt-dlp format string too restrictive
YouTube video format `bestvideo[height<=1080][vcodec^=avc1]` requires H.264. If only VP9/AV1 available, download fails.
**Fix:** Relax to `bestvideo[height<=1080]+bestaudio/best[height<=1080]` or use `--format-sort` for fallback.

---

## 5. 🧪 Testing Procedure

After making changes:

1. **Syntax check**
   ```bash
   php -l modules/core/Transcoder.php
   php -l modules/core/Uploader.php
   ```

2. **Browser test** (browser-use agent) with a real YouTube video URL

3. **Check for**:
   - Overlay appears immediately → `meelPhase('download')`
   - Progress updates → `meelDlPct(...)`
   - Transcode begins → `meelPhase('transcode')`
   - Completion → `meelPhase('done')`
   - No console errors

4. **Check error log** if download fails:
   ```bash
   cat /tmp/ytdlp_error.log
   ```

---

## 6. 📁 Relevant Files Reference

| File | Purpose |
|------|---------|
| `upload_advanced.php` | Advanced upload form + POST handler |
| `modules/core/Transcoder.php` | Download, HLS transcode, finalize |
| `modules/core/Uploader.php` | Direct file upload processing |
| `modules/core/helpers.php` | `resolve_binary()`, `require_disk_space()` |
| `modules/core/System.php` | Server busy, rate limit, queue mgmt |
| `modules/core/japanese.php` | `getRomajiName()` filename sanitization |
| `modules/transcoder/FfmpegUtils.php` | `probeDuration()`, `generateSpriteAndVTT()`, `moveFile()` |
| `partials/ui.php` | MEeL Engine overlay HTML/CSS/JS |
| `controllers/api/post_encode.php` | Music post-encode (Opus/OGG) |
| `auth/config.php` | DB connection, path constants, security headers |
