# 🚀 MEeL Installation Guide

Complete guide to install and run MEeL-HUB on your local server.

---

## 📋 Table of Contents

- [Automatic Installer (install.sh)](#automatic-installer-installsh)
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

## ⚡ Automatic Installer (install.sh)

An automatic installer that runs almost every step of this guide in a single
command (tested on Ubuntu/Debian):

```bash
./install.sh                 # interactive mode (asks for configuration)
./install.sh --yes           # non-interactive, uses all defaults
./install.sh --hdd=/path     # set MEEL_HDD_BASE directly
./install.sh --skip-apt      # skip system package installation (already present)
./install.sh --xsendfile     # enable MEEL_USE_XSENDFILE (requires Apache mod_xsendfile)
```

Steps it runs (idempotent for most of them):

1. **Check dependencies** — PHP 8.0+ with the `mysqli`, `pdo_mysql`,
   `fileinfo`, `mbstring`, `intl`, `gd`, `zip`, `xml`, `curl` extensions, plus
   MariaDB/MySQL; installed via apt when needed (`--skip-apt` to skip).
2. **Database setup** — create the database and import `database/schema.sql`
   (the hardcoded `USE \`MEeL\`` line in the schema is rewritten to the
   configured DB name); if the DB already exists it is safely skipped
   (reimport only on explicit confirmation).
3. **Application configuration** — create `auth/settings.php` &
   `auth/config.php` from the templates, patch the DB credentials +
   `MEEL_HDD_BASE`, and (optionally) enable `MEEL_USE_XSENDFILE` via
   `--xsendfile`.
4. **Storage directories** — create the full tree under `MEEL_HDD_BASE`
   (including `music/upload/{file,thumbnail}` and
   `books/upload/{manga,pdf,thumbnail}`), point deploy-time symlinks
   `{video,music,books}/upload` and `data_drive/public` at the centralized
   storage, and **copy the `.htaccess` hardening to the target** (symlinks are
   never committed).
5. **Apache** — enable `mod_rewrite` (best-effort).
6. **Migration** — `php database/migrate.php`.
7. **Final verification** — `php tests/check_deploy.php`; **exit code `1` on
   any FAIL** and a final banner that distinguishes "deployment healthy" from
   "server not ready".

> 💡 Step 7 validates the **real `MEEL_HDD_BASE` from `auth/settings.php`**
> (no `--hdd` override) — so the configuration the app actually uses is what
> gets tested.

---

## System Requirements

### Minimum Requirements

| Component | Version | Notes |
|---|---|---|
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

> 💡 `install.sh` installs & verifies all of the extensions above automatically
> (including `pdo_mysql` and `fileinfo`) — see
> [Automatic Installer](#automatic-installer-installsh).

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

> ⚠️ `schema.sql` hardcodes `CREATE DATABASE IF NOT EXISTS \`MEeL\`` +
> `USE \`MEeL\`;` — manual imports (CLI/phpMyAdmin) always target a database
> named `MEeL`. `install.sh` rewrites both lines to the configured database
> name, so a non-default DB name (e.g. for staging) is safe to use through the
> installer.

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
> ⚠️ `books/upload`, `music/upload`, `video/upload` are **real directories**
> tracked in the repo (placeholder `.gitkeep` + hardened `.htaccess`) — **NOT
> symlinks**. Their contents (uploaded files) are gitignored; media files live
> under `MEEL_HDD_BASE` and are served through PHP endpoints (see
> [5a. Media Storage](#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)).
> ℹ️ `data_drive/public` and `data_drive/private_admins` are **real directories**
> tracked in the repo (not symlinks) — they are the built-in fallback storage for
> the Drive module and are created/used automatically. For Drive storage on an
> external HDD, set `MEEL_HDD_DRIVE` in `auth/settings.php` (see below). **Never
> commit symlinks inside `data_drive/`** — `.gitignore` blocks them.

### 5a. Media Storage (MEEL_HDD_BASE) — PHP Endpoint + Rewrite (no symlinks)

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

#### How media files are served (no symlinks needed)

The repository tracks `books/upload`, `music/upload`, and `video/upload` as
**real directories** (placeholder `.gitkeep` + hardened `.htaccess`) — the
built-in fallback storage. **No symlinks are committed or required.** Uploaded
media is stored under `MEEL_HDD_BASE` (via the derived `MEEL_HDD_*_UPLOAD`
constants) and served through **PHP endpoints** mapped by an **internal
rewrite** in the root `.htaccess`:

```apache
RewriteRule ^video/upload/(.+)$ video/stream.php?f=$1 [L,QSA,B]
RewriteRule ^music/upload/(.+)$ music/file.php?f=$1 [L,QSA,B]
RewriteRule ^books/upload/(.+)$ books/file.php?f=$1 [L,QSA,B]
```

The browser URL stays `.../upload/...` (so relative HLS segments like `.ts`
resolve correctly), but Apache internally hands the request to the endpoint,
which resolves the real file via `meel_media_base_path()`. The `B` flag
escapes backreferences so filenames containing spaces or special characters
(e.g. `I'm My Own Girlfriend - ch 1-30 001 page 00.jpg`) survive the rewrite
without breaking the query string:

- `MEEL_HDD_*_UPLOAD` defined → file read from the HDD path;
- not defined → file read from the repo fallback folder `<root>/{module}/upload`.

Endpoints enforce path-traversal protection, an extension whitelist, Range
support (206 — needed for HLS `.ts` and large video), and a referer gate for
HLS video (anti-hotlink). Audio playback uses `music/stream?id=...`
(session authorization + strict referer gate; optional X-Sendfile
acceleration — see
[mod_xsendfile](#enable-mod_xsendfile-optional--for-streaming-acceleration)).

`.gitignore` explicitly **blocks committing symlinks** named after these folders
(they were previously committed as absolute `/media/<user>/...` symlinks that
leaked the OS username and broke on other machines).

#### Cloud Drive storage — `MEEL_HDD_DRIVE`

The **Drive module uses the same constant-based pattern**: it reads its base
path directly from `MEEL_HDD_DRIVE` (derived from `MEEL_HDD_BASE` in
`auth/settings.php`), exactly like Video/Music/Books read their
`MEEL_HDD_*_UPLOAD` constants — no symlinks anywhere:

```php
// auth/settings.php
define('MEEL_HDD_DRIVE', MEEL_HDD_BASE . '/drive/'); // base = public/ + private_admins/
```

- **HDD mode (`MEEL_HDD_DRIVE` defined):** Drive reads/writes files directly under
  `<MEEL_HDD_DRIVE>/public/<type>` and
  `<MEEL_HDD_DRIVE>/private_admins/<username>/<type>`; both sub-trees are created
  automatically. Private files are served **only** through the authorized
  `drive/stream.php` / `drive/download.php` endpoints (which read
  `MEEL_HDD_DRIVE` directly), so **no** symlink is needed for `private_admins`.
  Public previews use web paths under `data_drive/public/...`, so to keep
  in-browser previews working in HDD mode, point a **deploy-time** symlink at the
  storage (never commit it) — **`install.sh` does this automatically**:
  ```bash
  rm -f data_drive/public && ln -s "$BASE/drive/public" data_drive/public
  ```
  `tests/check_deploy.php` rates this deploy-time symlink **PASS** (target
  inside `MEEL_HDD_DRIVE`) and only warns when the target points outside the
  Drive storage (e.g. a committed `/media/<user>/...` symlink).
- **Fallback mode (`MEEL_HDD_DRIVE` not defined):** Drive uses the repo-tracked
  folders `data_drive/public` & `data_drive/private_admins` (real directories,
  auto-created by `DriveStorage::ensureDirectoryExists()`). This is the
  out-of-the-box behavior on a fresh clone.
- **Never commit symlinks inside `data_drive/`**: `.gitignore` blocks
  `data_drive/public` / `data_drive/private_admins` as symlinks.
  `tests/check_deploy.php` only warns about symlinks pointing OUTSIDE
  `MEEL_HDD_DRIVE` (e.g. `/media/<user>/...`); deploy-time symlinks pointing
  inside `MEEL_HDD_DRIVE` are rated PASS. A committed absolute symlink leaks
  your OS username through the public repo and crashes the Drive module on
  other machines (`RuntimeException: Folder penyimpanan gagal dibuat`).
- The private subtree stays hard-denied by the tracked `data_drive/.htaccess`
  (`RewriteRule ^private_admins/ - [F,L]`) plus
  `data_drive/private_admins/.htaccess` (`Require all denied` — tracked in the
  fallback folder and recreated at deploy time on the external storage target).

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
mkdir -p "$BASE"/drive/public "$BASE"/drive/private_admins
```

#### 3. No symlinks to create

The books/music/video upload folders are real directories in the repo — there
is **nothing to symlink** for a manual install. **`install.sh` automatically
creates deploy-time symlinks** `<root>/{video,music,books}/upload →
$MEEL_HDD_BASE/{m}/upload` when `MEEL_HDD_BASE` differs from the repo folder,
and **copies the `.htaccess` hardening to the target** so `check_deploy` keeps
passing (symlinks are never committed — `.gitignore` blocks them).

For a manual install, symlinks are optional — if you prefer Apache to serve
files directly (bypassing PHP) for performance, you may point the folder at
your HDD storage **at deploy time**:

```bash
cd /opt/lampp/htdocs/MEeL
for d in books music video; do
    rm -rf "$d/upload"
    ln -s "$BASE/$d/upload" "$d/upload"
done
ls -la books/upload music/upload video/upload   # harus menunjuk ke BASE Anda
```

> ⚠️ If you create symlinks manually, make sure the `.htaccess` hardening
> (pattern of `data_drive/.htaccess`: `php_flag engine off` + `ForceType` +
> `Options -Indexes`) exists on the **target** storage — `install.sh` does this
> automatically. `tests/check_deploy.php` rates symlinks pointing at
> `MEEL_HDD_BASE` as PASS and anything else as WARN.

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
> (see [5a. Media Storage](#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)).

#### 7. Verify storage

```bash
df -h "$BASE"
test -d "$BASE/video/upload/video" && echo "storage OK"
php -r "require 'auth/settings.php'; echo defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : 'NOT SET';"
```

#### 8. Automated deployment check (one command)

The project ships a CLI health-check that verifies the deployment-critical
areas in a single run — `MEEL_HDD_BASE`, upload dirs (real folders or
deploy-time symlinks), **non-auto-created subdirectories**
(`music/upload/file`, `music/upload/thumbnail`, `books/upload/pdf`,
`books/upload/thumbnail` — the music/books modules do **not** create them
automatically, so a missing one is a **FAIL** → deployment not ready, exit
code `1`), upload-dir `.htaccess` hardening, the `data_drive/`
portability guard (deploy-time symlinks inside `MEEL_HDD_DRIVE` = PASS;
pointing outside = WARN), and the PWA `mod_rewrite` rule:

```bash
php tests/check_deploy.php                           # local check + auto HTTP probe
php tests/check_deploy.php --url=http://localhost/MEeL   # explicit HTTP probe
php tests/check_deploy.php --hdd=/tmp/meel-storage/media  # override MEEL_HDD_BASE (testing/CI)
php tests/check_deploy.php --no-color                # no ANSI colors (for CI/logs)
```

Each item is reported as `PASS` / `WARN` / `FAIL` with a summary; exit code is
`0` when healthy and `1` when at least one check fails (CI-friendly). If the
storage is not mounted yet, the script reports `FAIL` on the storage / upload
dir / upload-`.htaccess` areas — mount the storage, set `MEEL_HDD_BASE`, ensure
upload dirs have their `.htaccess`, then re-run until you see
`✅ Deployment sehat.`

> 💡 `install.sh` runs this health-check as its final verification step
> **without `--hdd`** (the real `auth/settings.php` config is tested) and
> **exits with code `1`** on any FAIL — the final banner distinguishes
> "deployment healthy" from "server not ready".

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
       # File media kini dibaca langsung dari storage terpusat (MEEL_HDD_BASE),
       # bukan dari folder webroot — whitelist path HDD-nya:
       XSendFilePath "/media/<user>/MEeL/media"
       XSendFilePath "/opt/lampp/htdocs/MEeL/data_drive"
   </IfModule>
   ```

   > ⚠️ `XSendFilePath` harus mencakup path tempat file media sebenarnya berada
   > (nilai `MEEL_HDD_BASE` di `auth/settings.php`). Sejak refactor portabilitas,
   > `music/upload` dkk adalah folder nyata di repo (bukan symlink), jadi path
   > webroot lama seperti `/opt/lampp/htdocs/MEeL/music/upload/file` TIDAK lagi
   > menjadi lokasi file — hanya path HDD yang benar. Jika `XSendFilePath` tidak
   > mencakup storage, Apache mengembalikan 404 "Object not found" saat streaming
   > (lihat [5a. Media Storage](#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)).

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
**v1–v12** (automatic tracker in the `db_version` table):
- **v1–v5:** FULLTEXT indexes, performance indexes, structural sync, foreign keys, title type
- **v6–v7:** `activity_log` table, UNIQUE KEY on username
- **v8–v9:** role column sync, **MFA columns** (`mfa_secret`, `mfa_backup_codes`, `mfa_enabled`)
- **v10:** composite indexes on `comments` `(video_id, created_at)` & `(music_id, created_at)`
- **v11:** `interactions` unique keys split into `(user_id, video_id)` & `(user_id, music_id)`
- **v12:** bind user identity to chess rooms (`white_user_id`, `black_user_id`) — prevents illegal access via `room_code`

> 💡 **Rhythm module (MEeL!Mania) has its own DB migration** — the `arcade_song`
> & `arcade_score` tables come from `arcade/rhythm/migration.sql`, **not** part of
> `database/migrate.php` (v1–v12). Import once:
> ```bash
> mysql MEeL < arcade/rhythm/migration.sql
> ```

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
- Make sure the storage is mounted and the media endpoints resolve it:
  ```bash
  php -r "require 'auth/settings.php'; require 'modules/core/helpers.php'; echo meel_media_base_path('video');"
  # Output harus path storage Anda, mis. /media/<user>/MEeL/media/video/upload
  ```
- The upload folders (`books/upload`, `music/upload`, `video/upload`) are real
  directories tracked in the repo (no symlinks needed). Media is served through
  the PHP endpoints (`video/stream.php`, `music/file.php`, `books/file.php`)
  mapped by the `.htaccess` rewrite — see
  [5a. Media Storage](#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks).
- Or disable temporarily for development

### ❌ Deployment Check reports FAIL on upload folders

- **Symptom:** `php tests/check_deploy.php` → `FAIL` on upload dirs /
  `.htaccess` upload dirs (storage not mounted or `MEEL_HDD_BASE` wrong).
- **Cause:** storage HDD (`MEEL_HDD_BASE`) not mounted / misconfigured in
  `auth/settings.php`. Upload folders are real directories in the repo — no
  symlinks to fix. A deploy-time symlink pointing at the wrong target also
  triggers a warning.
- **Fix:** mount the storage, set `MEEL_HDD_BASE` correctly, and ensure each
  upload dir has its `.htaccess` (see
  [5a. Media Storage](#5a-media-storage-meel_hdd_base--php-endpoint--rewrite-no-symlinks)).
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
