# 📥 Advanced Upload Issue Resolution

Troubleshooting guide for yt-dlp downloads and background queue processing.

---

## 📋 Table of Contents

- [Download Queue System](#download-queue-system)
- [Common Download Issues](#common-download-issues)
- [YouTube & Platform Issues](#youtube--platform-issues)
- [Process Management](#process-management)
- [Resource Issues](#resource-issues)
- [Queue Stuck Resolution](#queue-stuck-resolution)

---

## Download Queue System

### How the Queue Works

```
Request → Queue → Process → Download → Transcode → Complete
                          
                          ↓ (if failed)
                        Retry (3x max)
                          ↓ (if still failed)
                        Mark as failed
```

The system uses `upload_queue` and `transcode_queue` tables to manage concurrent downloads.

### Queue Limitations

- Maximum 2 simultaneous processes
- Maximum 3 retry attempts per download
- Queue timeout: 900 seconds (15 minutes)

---

## Common Download Issues

### ❌ Download stuck at 0%

**Causes:**
1. **yt-dlp not found**: Check installation
2. **No internet connection**: Verify network
3. **URL blocked**: Check if URL is accessible
4. **Cookies expired**: Re-export cookies.txt

**Solutions:**
```bash
# Verify yt-dlp
yt-dlp --version

# Test URL directly
yt-dlp --simulate "https://www.youtube.com/watch?v=..."
```

### ❌ "HTTP Error 403" when downloading

**Cause:** YouTube blocking automated requests.

**Solutions:**
1. **Update yt-dlp:**
   ```bash
   sudo yt-dlp -U
   ```
2. **Re-export cookies:**
   - Use browser extension to export cookies
   - Save as `cookies.txt` in project root
3. **Try different user-agent**

### ❌ Download completes but no file found

**Causes:**
- Wrong format selection
- FFmpeg not installed or too old
- Disk space full

**Solutions:**
```bash
# Check FFmpeg
ffmpeg -version

# Check disk space
df -h

# Clear temp directory
rm -rf /opt/lampp/htdocs/MEeL/temp/*
```

---

## YouTube & Platform Issues

### ❌ "This video is unavailable" / Private video

**Solutions:**
- Ensure you're logged into YouTube in browser before exporting cookies
- Check if the video is region-locked
- Some private/age-restricted videos may not be downloadable

### ❌ "Sign in to confirm your age"

**Solution:**
Export cookies after signing into a YouTube account that has age verification.

### ❌ Geo-restricted content

**Workarounds:**
- Use a VPN in the target country
- Use proxy settings with yt-dlp

---

## Process Management

### ❌ Stuck queue items

**Solution:**
Use the admin panel:
1. Go to Admin Panel → Queue Management
2. Click "Clean Stuck Queues"
3. Or manually force stop individual queues

### ❌ Too many processes running

**Check active downloads:**
```bash
ps aux | grep yt-dlp
ps aux | grep ffmpeg
```

**Kill stuck processes:**
```bash
sudo killall yt-dlp
sudo killall ffmpeg
```

---

## Resource Issues

### ❌ CPU usage too high during transcoding

**Adjust FFmpeg threads:**
```php
// modules/Transcoder.php
private const FFMPEG_THREADS = 4; // Reduce from 8
```

### ❌ Not enough disk space

**Check space:**
```bash
df -h
du -sh /opt/lampp/htdocs/MEeL/temp/
```

**Clean temp directory:**
```bash
rm -rf /opt/lampp/htdocs/MEeL/temp/*
```

---

## Queue Stuck Resolution

### Force Reset All Queues

```bash
# Via MySQL
mysql -u root -p -e "UPDATE MEeL.upload_queue SET status='failed' WHERE status='processing';"
mysql -u root -p -e "UPDATE MEeL.transcode_queue SET status='failed' WHERE status='processing';"
```

### Manual Process Kill

```bash
# Find and kill stuck processes
ps aux | grep -E 'yt-dlp|ffmpeg' | grep -v grep
sudo kill -9 [PID]
```

---

## Prevention Tips

1. **Regularly update yt-dlp:** `sudo yt-dlp -U`
2. **Keep cookies fresh:** Re-export monthly
3. **Monitor disk space:** Set up alerts at 80% usage
4. **Use wired connection:** WiFi can cause timeout issues for large downloads
5. **Don't queue too many items:** Maximum 2 concurrent downloads

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
