# 🔧 MEeL Troubleshooting Guide

Solutions for common issues encountered while using MEeL-HUB.

---

## 📋 Table of Contents

- [HLS Streaming Issues](#hls-streaming-issues)
- [Upload Problems](#upload-problems)
- [FFmpeg/yt-dlp Errors](#ffmpegyt-dlp-errors)
- [Database Issues](#database-issues)
- [Permission Problems](#permission-problems)
- [Browser/Player Issues](#browserplayer-issues)
- [Session & Auth Issues](#session--auth-issues)
- [Storage & Disk Issues](#storage--disk-issues)
- [HTMX/AJAX Issues](#htmxajax-issues)

---

## HLS Streaming Issues

### ❌ Video doesn't play (black screen)

**Symptoms:**
- Player loads but screen stays black
- "No compatible source" error
- Infinite buffering

**Causes & Solutions:**

1. **HLS segments not generated:**
   ```bash
   # Check if .m3u8 and .ts files exist
   ls /media/[user]/MEeL/media/video/upload/video/[folder]/
   # Should show: [name].m3u8, [name]_000.ts, ...
   ```
   If missing, re-transcode the video.

2. **FFmpeg version too old:**
   ```bash
   ffmpeg -version  # Must be 6.0+
   # Update if needed
   sudo apt update && sudo apt upgrade ffmpeg
   ```

3. **Wrong file path in database:**
   Check `video` table — `path_folder` must match actual folder name.

4. **Browser doesn't support HLS:**
   - HLS.js works on all modern browsers
   - Check console for HLS.js errors
   - Try Chrome/Firefox/Edge (Safari has native HLS)

5. **CORS issues:**
   If using external storage, ensure proper CORS headers:
   ```apache
   Header set Access-Control-Allow-Origin "*"
   ```

### ❌ Video stutters or buffers frequently

**Causes & Solutions:**

1. **Network bandwidth:** HLS adaptive bitrate should handle this
2. **Server load:** Check CPU usage during streaming
3. **HDD speed:** If using USB HDD, ensure USB 3.0+
4. **Too many concurrent streams:** Each stream uses ~10-50 Mbps for 1080p

---

## Upload Problems

### ❌ Upload fails with no error message

**Troubleshooting:**
```bash
# Check PHP error log
tail -f /opt/lampp/logs/php_error_log

# Check upload directory permissions
ls -la /path/to/upload/
# Should be writable by www-data

# Check PHP upload limits
php -i | grep -i upload_max_filesize
php -i | grep -i post_max_size
```

**Common fixes:**
1. Increase `upload_max_filesize` and `post_max_size` in `php.ini`
2. Ensure upload directory exists and is writable
3. Check disk space: `df -h`

### ❌ "File type not allowed"

**Check:**
- File extension is in the allowed list
- Magic bytes match the declared type
- File isn't renamed with fake extension

### ❌ Upload takes too long

**Solutions:**
1. Increase `max_execution_time` in `php.ini`
2. Use smaller files (split large videos)
3. Use Advanced Upload (yt-dlp) for URL downloads

---

## FFmpeg/yt-dlp Errors

### ❌ "FFmpeg not found"

**Check:**
```bash
which ffmpeg      # Should return path
ffmpeg -version   # Should return version
```

**Solution:**
```bash
sudo apt install ffmpeg
# Or set path in auth/config.php
```

### ❌ "yt-dlp not found"

**Check:**
```bash
which yt-dlp
yt-dlp --version
```

**Solution:**
```bash
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp
```

### ❌ "HTTP Error 403" when downloading

**Cause:** YouTube/other platforms blocking requests.

**Solutions:**
1. Update yt-dlp: `sudo yt-dlp -U`
2. Export fresh cookies (cookies.txt)
3. Use a different user-agent

---

## Database Issues

### ❌ "Connection failed"

**Check:**
```bash
systemctl status mysql
mysql -u root -p -e "SHOW DATABASES;"
```

**Solutions:**
- Start MySQL: `sudo systemctl start mysql`
- Check credentials in `auth/config.php`
- Check MySQL port (default: 3306)

### ❌ "Table not found"

**Solution:**
```bash
# Import schema
mysql -u root -p MEeL < database/schema.sql

# Run migrations
php database/migrate.php
```

---

## Permission Problems

### ❌ "Permission denied" when uploading

**Fix:**
```bash
# Find your web server user
ps aux | grep apache | head -1
# Usually: www-data, daemon, or nobody

# Set correct ownership
sudo chown -R www-data:www-data /path/to/upload/dir
sudo chmod -R 775 /path/to/upload/dir
```

### ❌ "Storage Offline" error

**Check:**
```php
// auth/config.php
define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');
```

Verify the mount point exists and is accessible.

---

## Browser/Player Issues

### ❌ Player controls not showing

**Check:**
- Plyr.js is loaded (check Network tab)
- No JavaScript console errors
- CSS files are loading

### ❌ "Lucide icons not loading"

**Solution:**
```html
<script src="/MEeL/assets/js/lucide.js"></script>
<script>lucide.createIcons();</script>
```

### ❌ HTMX not working

**Check:**
1. HTMX script is loaded
2. Target element exists in DOM
3. Response is valid HTML
4. No JavaScript errors

---

## Session & Auth Issues

### ❌ "Session expired" frequently

**Check:**
```php
// auth/config.php
$timeout = 43200; // 12 hours in seconds
```

### ❌ "Access Denied" for valid users

**Check:**
- User role in database: `SELECT role, is_active FROM users WHERE username = '...'`
- IP is not banned: `SELECT * FROM ip_ban`

### ❌ Can't login

**Solutions:**
1. Check password: use password reset or direct DB update
2. Check if account is active: `is_active` must be 1
3. Check ban status

---

## Storage & Disk Issues

### ❌ "Disk full" error

**Check:**
```bash
df -h
du -sh /media/[user]/MEeL/
```

**Solutions:**
- Delete unnecessary files
- Move storage to larger HDD
- Run orphan cleanup from admin panel

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
