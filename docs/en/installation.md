# 🚀 MEeL Installation Guide

Complete guide to install and run MEeL-HUB on your local server.

---

## 📋 Table of Contents

- [System Requirements](#system-requirements)
- [Step-by-Step Installation](#step-by-step-installation)
- [Database Setup](#database-setup)
- [Application Configuration](#application-configuration)
- [Runtime Directories & Permissions](#runtime-directories--permissions)
- [Apache Configuration](#apache-configuration)
- [Installation Verification](#installation-verification)
- [FFmpeg & yt-dlp Setup](#ffmpeg--yt-dlp-setup)
- [Installation Troubleshooting](#installation-troubleshooting)

---

## System Requirements

### Minimum Requirements

| Component | Version | Notes |
|----------|-------|------------|
| **PHP** | 8.0+ | **8.1+** highly recommended |
| **MySQL** | 5.7+ / MariaDB 10.2+ | Encoding `utf8mb4` |
| **Apache** | 2.4+ | Requires `mod_rewrite` |
| **FFmpeg** | 6.0+ | For HLS & transcoding |
| **FFprobe** | (bundled with FFmpeg) | For media probing |
| **yt-dlp** | Latest version | For URL downloads |
| **RAM** | 2 GB (4 GB+) | 4 GB+ for transcoding |
| **Storage** | 10 GB+ | Depends on media size |

### mecab Translator
```bash
sudo apt install mecab mecab-ipadic-utf8 libmecab-dev
```

### Required PHP Extensions

```bash
# On Ubuntu/Debian
sudo apt install php8.1-mysqli php8.1-pdo-mysql php8.1-gd php8.1-fileinfo \
                 php8.1-mbstring php8.1-intl php8.1-zip php8.1-xml
```

Or enable in `php.ini`:
```ini
extension=mysqli
extension=pdo_mysql
extension=gd
extension=fileinfo
extension=json
extension=mbstring
extension=intl
extension=zip
```

> ⚠️ **`intl` extension** is required for filename transliteration (Japanese/Kana → Romaji).
> ⚠️ **`zip` extension** is required for manga uploads (ZIP/CBZ).
> ⚠️ **mecab extension** Required for better translation support.

### Recommended OS

**Linux (Ubuntu Server / Debian)** is highly recommended. Windows has limitations:
- Different FFmpeg process signal management
- Different file permission systems
- Case-sensitive PHP file paths

---

## Step-by-Step Installation

### 1. Install Web Server (XAMPP/LAMPP or Manual)

**Option A — LAMPP (Linux):**
```bash
# Download XAMPP for Linux
wget https://www.apachefriends.org/xampp-files/8.2.12/xampp-linux-x64-8.2.12-0-installer.run
chmod +x xampp-linux-*.run
sudo ./xampp-linux-*.run
```

**Option B — Manual (Ubuntu/Debian):**
```bash
sudo apt update
sudo apt install apache2 mysql-server php8.1 php8.1-mysqli php8.1-gd \
                 php8.1-mbstring php8.1-intl php8.1-zip php8.1-fileinfo
```

### 2. Clone Repository

```bash
cd /opt/lampp/htdocs  # For XAMPP/LAMPP
# or
cd /var/www/html      # For manual Apache

git clone https://github.com/mifada2543/MEeL.git MEeL
cd MEeL
```

### 3. Database Setup

> **📁 The database schema file is available at [`database/schema.sql`](../../database/schema.sql).**
> After importing, run the migration to complete the setup.

#### Option A — Via MySQL CLI (fast):
```bash
mysql -u root -p < database/schema.sql
```

#### Option B — Via MySQL prompt:
```bash
mysql -u root -p
```
```sql
SOURCE /path/to/MEeL/database/schema.sql;
```

#### Option C — Via phpMyAdmin / other GUI:
1. Open phpMyAdmin → **Import** tab
2. Select the `database/schema.sql` file
3. Click **Go**

### 4. Application Configuration

```bash
cd /opt/lampp/htdocs/MEeL/auth
cp settings.example.php settings.php
cp config.example.php config.php
```

Edit `auth/settings.php`:
```php
$server   = "localhost";
$username = "root";       // Your database user
$password = "";           // Your database password
$db       = "MEeL";       // Database name
```

> `auth/config.php` is the entry point that requires `auth/settings.php`.

After configuring `auth/config.php`, run the database migration:
```bash
php database/migrate.php
```

> 💡 The migration will automatically add FULLTEXT indexes, foreign keys, and other optimizations.

### 5. Create Runtime Directories

```bash
cd /opt/lampp/htdocs/MEeL
mkdir -p data_drive/public data_drive/private_admins temp profile/upload music/upload/file music/upload/thumbnail books/upload/manga books/upload/pdf books/upload/thumbnail

sudo chown -R www-data:www-data data_drive temp profile/upload music/upload books/upload
sudo chmod -R 775 data_drive temp profile/upload music/upload books/upload
```

> 💡 If `www-data` doesn't work, try `daemon` or `nobody`.
> ⚠️ `books/upload`, `music/upload`, `video/upload` are **git symlinks** pointing
> to the storage HDD — check & fix them BEFORE creating the sub-directories below
> (see [5a. Media Storage](#5a-media-storage-meel_hdd_base--upload-symlinks)).

### 5a. Media Storage (MEEL_HDD_BASE) & Upload Symlinks

All media paths are centralized in `auth/settings.php` — change **one line** and
the whole system follows:

```php
// auth/settings.php
// WAJIB DIGANTI sebelum produksi — nilai default adalah placeholder!
define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');

// Path turunan (otomatis):
//   MEEL_HDD_VIDEO_UPLOAD = MEEL_HDD_BASE . '/video/upload/'
//   MEEL_HDD_VIDEO_DIR    = MEEL_HDD_BASE . '/video/upload/video/'
//   MEEL_HDD_THUMB_DIR    = MEEL_HDD_BASE . '/video/upload/thumbnail/'
//   MEEL_HDD_MUSIC_UPLOAD = MEEL_HDD_BASE . '/music/upload/'
//   MEEL_HDD_BOOKS_UPLOAD = MEEL_HDD_BASE . '/books/upload/'
//   MEEL_HDD_DRIVE        = MEEL_HDD_BASE . '/drive/'
```

#### How the upload symlinks work

The repository tracks `books/upload`, `music/upload`, and `video/upload` as
**symlinks** (git mode `120000`) pointing to the *previous owner's* HDD path
(e.g. `/media/<user>/MEeL/media/books/upload`). On a fresh clone they are
**broken** until you point them at **your** `MEEL_HDD_BASE`.

#### 1. Mount / create the storage

```bash
df -h   # cek mount point yang tersedia

# Contoh /etc/fstab untuk mount permanen (opsional):
# /dev/sdb1  /media/<user>/MEeL/media  ext4  defaults,nofail  0 2
sudo mount -a
```

#### 2. Create the storage tree

```bash
BASE=/media/<user>/MEeL/media   # ganti dengan path ANDA
mkdir -p "$BASE"/video/upload/video "$BASE"/video/upload/thumbnail
mkdir -p "$BASE"/music/upload/file   "$BASE"/music/upload/thumbnail
mkdir -p "$BASE"/books/upload/manga  "$BASE"/books/upload/pdf "$BASE"/books/upload/thumbnail
mkdir -p "$BASE"/drive
```

#### 3. Check the tracked symlinks

```bash
ls -la books/upload music/upload video/upload
readlink books/upload
```

**Symptom of a broken symlink** (storage not mounted or path changed):

```
books/upload: broken symbolic link to /media/muhammaddaffa/MEeL/media/books/upload
```

#### 4. Recreate the symlinks for your path

```bash
cd /opt/lampp/htdocs/MEeL
for d in books music video; do
    rm -f "$d/upload"
    ln -s "$BASE/$d/upload" "$d/upload"
done
ls -la books/upload music/upload video/upload   # harus menunjuk ke BASE Anda
```

#### 5. Permissions

```bash
sudo chown -R www-data:www-data "$BASE"
sudo chmod -R 775 "$BASE"
```

#### 6. Security .htaccess in upload dirs (required)

Every upload directory must contain a `.htaccess` that disables PHP execution
(`php_flag engine off`), a `ForceType` MIME guard, and `Options -Indexes` — the
same pattern as `data_drive/.htaccess`. `tests/security_test.php` verifies these:

```bash
php tests/security_test.php
```

> The security test now reports **0 failures in all environments** — the upload-dir
> `.htaccess` deny rules are tracked in the repo and verified statically. It may
> emit **5 non-critical warnings** (MediaViewer raw-query review, profile_edit
> MIME check, session parameter detection) — those are review items, not
> deployment issues. For storage-level verification use `tests/check_deploy.php`
> (see [5a. Media Storage](#5a-media-storage-meel_hdd_base--upload-symlinks)).

#### 7. Verify storage

```bash
df -h "$BASE"
test -d "$BASE/video/upload/video" && echo "storage OK"
php -r "require 'auth/settings.php'; echo defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : 'NOT SET';"
```

#### 8. Automated deployment check (one command)

The project ships a CLI health-check that verifies the four deployment-critical
areas in a single run — `MEEL_HDD_BASE`, upload symlinks, upload-dir `.htaccess`
hardening, and the PWA `mod_rewrite` rule:

```bash
php tests/check_deploy.php                           # local check + auto HTTP probe
php tests/check_deploy.php --url=http://localhost/MEeL   # explicit HTTP probe
php tests/check_deploy.php --hdd=/tmp/meel-storage/media  # override MEEL_HDD_BASE (testing/CI)
php tests/check_deploy.php --no-color                # no ANSI colors (for CI/logs)
```

Each item is reported as `PASS` / `WARN` / `FAIL` with a summary; exit code is
`0` when healthy and `1` when at least one check fails (CI-friendly). If the
storage is not mounted yet, the script reports `FAIL` on the storage / symlink /
upload-`.htaccess` areas — mount the storage, fix the symlinks, add the
`.htaccess` files, then re-run until you see `✅ Deployment sehat.`

### 6. Apache Configuration

#### Enable mod_rewrite:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Ensure AllowOverride is active:
Edit `/etc/apache2/apache2.conf`:
```apache
<Directory /var/www/html>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

#### 📱 mod_rewrite & PWA (required)

The **PWA depends on mod_rewrite + .htaccess processing**: the service worker
is generated by `sw.js.php` and served as `/sw.js` via the root `.htaccess`
rewrite. Verify it works:

```bash
curl -sI http://localhost/MEeL/sw.js | grep -i content-type
# Content-Type: application/javascript; charset=utf-8   ← benar
```

If `.htaccess` is not processed (`AllowOverride` disabled), `/sw.js` returns
404 and the PWA silently degrades — the site still works, but **offline mode and
"Add to Home Screen" stop working**.

#### ⚡ Enable mod_xsendfile (Optional — for streaming acceleration)

mod_xsendfile speeds up streaming large files (FLAC 33MB+, MKV 4K) by letting Apache send files directly from disk without going through PHP.

**Steps:**

1. Download source:
   ```bash
   git clone --depth 1 https://github.com/nmaier/mod_xsendfile.git /tmp/mod_xsendfile
   cd /tmp/mod_xsendfile
   ```

2. Compile with server's `apxs`:
   ```bash
   # For LAMPP:
   /opt/lampp/bin/apxs -c mod_xsendfile.c
   
   # For standard Apache:
   sudo apxs -c mod_xsendfile.c
   ```

   > 💡 If `apxs` fails with `libtool: compile: you must specify a compilation command`,
   > compile manually with gcc:
   > ```bash
   > gcc -c -I/opt/lampp/include -I/opt/lampp/include/apr-1 -fPIC -DPIC mod_xsendfile.c -o mod_xsendfile.o
   > gcc -shared -o mod_xsendfile.so mod_xsendfile.o -L/opt/lampp/lib -lapr-1
   > ```

3. Install module:
   ```bash
   sudo cp mod_xsendfile.so /opt/lampp/modules/
   sudo chmod 755 /opt/lampp/modules/mod_xsendfile.so
   ```

4. Add to `httpd.conf`:
   ```apache
   LoadModule xsendfile_module modules/mod_xsendfile.so

   <IfModule xsendfile_module>
       XSendFile on
       XSendFilePath "/opt/lampp/htdocs/MEeL/music/upload/file"
   </IfModule>
   ```

5. Restart Apache:
   ```bash
   sudo /opt/lampp/lampp restart
   ```

6. Verify:
   ```bash
   sudo /opt/lampp/bin/httpd -M | grep xsend
   # Output: xsendfile_module (shared)
   ```

7. Enable in app — edit `auth/settings.php`:
   ```php
   define('MEEL_USE_XSENDFILE', true);
   ```

### 7. FFmpeg Setup

```bash
# Ubuntu/Debian
sudo apt install ffmpeg

# Verify
ffmpeg -version
ffprobe -version

# Check binary location
which ffmpeg    # Output: /usr/bin/ffmpeg
which ffprobe   # Output: /usr/bin/ffprobe
```

### 8. yt-dlp Setup

```bash
# Install via pip
sudo apt install python3-pip
pip3 install yt-dlp

# Or download directly
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp

# Verify
yt-dlp --version
```

### 10. Migration System

After all setup is complete, run the database migration to optimize the schema:
```bash
# From project root
/opt/lampp/bin/php database/migrate.php
```

The migration is **idempotent** — safe to run multiple times. It manages
**v1–v11** (automatic tracker in the `db_version` table):
- **v1–v5:** FULLTEXT indexes, performance indexes, structural sync, foreign keys, title type
- **v6–v7:** `activity_log` table, UNIQUE KEY on username
- **v8–v9:** role column sync, **MFA columns** (`mfa_secret`, `mfa_backup_codes`, `mfa_enabled`)
- **v10:** composite indexes on `comments` `(video_id, created_at)` & `(music_id, created_at)`
- **v11:** `interactions` unique keys split into `(user_id, video_id)` & `(user_id, music_id)`

### 11. Setup cookies.txt (for yt-dlp)

To download from YouTube and other platforms, export your browser cookies:
1. Install the [Get cookies.txt LOCALLY](https://chrome.google.com/webstore/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc) extension
2. Log in to YouTube in your browser
3. Export cookies to Netscape format
4. Save as `cookies.txt` in the project root:

```bash
cp /path/to/cookies.txt /opt/lampp/htdocs/MEeL/cookies.txt
```

---

## Installation Verification

1. Start Apache & MySQL:
   ```bash
   # LAMPP
   sudo /opt/lampp/lampp start
   
   # Manual
   sudo systemctl start apache2 mysql
   ```

2. Open browser: `http://localhost/MEeL/`

3. Login with:
   - **Username:** `Admin`
   - **Password:** `Admin#123`

4. Check Admin page: `http://localhost/MEeL/admin/`

---

## Installation Troubleshooting

### ❌ "Database connection failed"
- Make sure MySQL/MariaDB is running: `sudo systemctl status mysql`
- Verify credentials in `auth/settings.php`
- Try: `mysql -u root -p -e "SHOW DATABASES;"`

### ❌ "Storage Offline" / Redirected to maintenance
- Check HDD path in `auth/settings.php`:
  ```php
  define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');
  ```
- Adjust to your mount point: `df -h` to check mounts
- Make sure the storage is mounted and the upload symlinks are valid:
  ```bash
  readlink books/upload music/upload video/upload
  ls -ld books/upload music/upload video/upload   # jangan "broken symbolic link"
  ```
- Or disable temporarily for development

### ❌ Deployment Check reports FAIL on upload folders
- **Symptom:** `php tests/check_deploy.php` → `FAIL` on upload symlinks /
  `.htaccess` upload dirs (storage not mounted or broken symlinks).
- **Cause:** storage HDD (`MEEL_HDD_BASE`) not mounted, or the tracked upload
  symlinks still point to the previous owner's path after cloning.
- **Fix:** mount the storage, recreate the symlinks, and ensure each upload dir
  has its `.htaccess` (see [5a. Media Storage](#5a-media-storage-meel_hdd_base--upload-symlinks)).
  Re-run `php tests/check_deploy.php` until it reports `✅ Deployment sehat.`

### ❌ "403 Forbidden" on pages
- Check `.htaccess` in the relevant directory
- Make sure `AllowOverride All` is set in Apache config

### ❌ "500 Internal Server Error"
- Check error log: `sudo tail -f /var/log/apache2/error.log`
- Enable error reporting in PHP: `ini_set('display_errors', 1);`

### ❌ FFmpeg/yt-dlp not found
- Verify binary is installed: `which ffmpeg && which yt-dlp`
- Transcoder and Uploader auto-detect via `resolveBinary()`

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
