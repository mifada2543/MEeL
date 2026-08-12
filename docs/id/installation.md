# 🚀 Panduan Instalasi MEeL

Panduan lengkap untuk menginstal dan menjalankan MEeL-HUB di server lokal Anda.

---

## 📋 Daftar Isi

- [Persyaratan Sistem](#persyaratan-sistem)
- [Instalasi Langkah demi Langkah](#instalasi-langkah-demi-langkah)
- [Setup Database](#setup-database)
- [Konfigurasi Aplikasi](#konfigurasi-aplikasi)
- [Direktori Runtime & Perizinan](#direktori-runtime--perizinan)
- [Konfigurasi Apache](#konfigurasi-apache)
- [Verifikasi Instalasi](#verifikasi-instalasi)
- [Setup FFmpeg & yt-dlp](#setup-ffmpeg--yt-dlp)
- [Troubleshooting Instalasi](#troubleshooting-instalasi)

---

## Persyaratan Sistem

### Minimum Requirements

| Komponen | Versi | Keterangan |
|----------|-------|------------|
| **PHP** | 8.0+ | **8.1+** sangat disarankan |
| **MySQL** | 5.7+ / MariaDB 10.2+ | Encoding `utf8mb4` |
| **Apache** | 2.4+ | Wajib `mod_rewrite` |
| **FFmpeg** | 6.0+ | Untuk HLS & transcoding |
| **FFprobe** | (bundled with FFmpeg) | Untuk probing media |
| **yt-dlp** | Versi terbaru | Untuk download URL |
| **RAM** | 2 GB (4 GB+) | 4 GB+ untuk transcoding |
| **Storage** | 10 GB+ | Tergantung ukuran media |

### Translator mecab
```bash
sudo apt install mecab mecab-ipadic-utf8 libmecab-dev
```

### PHP Extensions yang Diperlukan

```bash
# Pada Ubuntu/Debian
sudo apt install php8.1-mysqli php8.1-pdo-mysql php8.1-gd php8.1-fileinfo \
                 php8.1-mbstring php8.1-intl php8.1-zip php8.1-xml
```

Atau aktifkan di `php.ini`:
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

> ⚠️ **Ekstensi `intl`** wajib untuk fitur transliterasi nama file (karakter Jepang/Kana → Romaji).
> ⚠️ **Ekstensi `zip`** diperlukan untuk upload manga (ZIP/CBZ).
> ⚠️ **Ekstensi `mecab`** Diperlukan untuk translate yang lebih baik

### OS yang Direkomendasikan

**Linux (Ubuntu Server / Debian)** sangat direkomendasikan. Windows memiliki keterbatasan:
- Manajemen sinyal proses FFmpeg berbeda
- Sistem permission file berbeda
- Case-sensitive path pada file PHP

---

## Instalasi Langkah demi Langkah

### 1. Install Web Server (XAMPP/LAMPP atau Manual)

**Opsi A — LAMPP (Linux):**
```bash
# Download XAMPP for Linux
wget https://www.apachefriends.org/xampp-files/8.2.12/xampp-linux-x64-8.2.12-0-installer.run
chmod +x xampp-linux-*.run
sudo ./xampp-linux-*.run
```

**Opsi B — Manual (Ubuntu/Debian):**
```bash
sudo apt update
sudo apt install apache2 mysql-server php8.1 php8.1-mysqli php8.1-gd \
                 php8.1-mbstring php8.1-intl php8.1-zip php8.1-fileinfo
```

### 2. Kloning Repositori

```bash
cd /opt/lampp/htdocs  # Untuk XAMPP/LAMPP
# atau
cd /var/www/html      # Untuk Apache manual

git clone https://github.com/mifada2543/MEeL.git MEeL
cd MEeL
```

### 3. Setup Database

> **📁 File skema database sudah tersedia di [`database/schema.sql`](../../database/schema.sql).**
> Setelah impor, jalankan migrasi untuk menyelesaikan setup.

#### Opsi A — Via MySQL CLI (cepat):
```bash
mysql -u root -p < database/schema.sql
```

#### Opsi B — Via MySQL prompt:
```bash
mysql -u root -p
```
```sql
SOURCE /path/ke/MEeL/database/schema.sql;
```

#### Opsi C — Via phpMyAdmin / GUI lainnya:
1. Buka phpMyAdmin → tab **Import**
2. Pilih file `database/schema.sql`
3. Klik **Go**

### 4. Konfigurasi Aplikasi

```bash
cd /opt/lampp/htdocs/MEeL/auth
cp settings.example.php settings.php
cp config.example.php config.php
```

Edit `auth/settings.php`:
```php
$server   = "localhost";
$username = "root";       // User database Anda
$password = "";           // Password database Anda
$db       = "MEeL";       // Nama database
```

> `auth/config.php` adalah entry point yang me-require `auth/settings.php`.

Setelah `auth/config.php` sudah diisi, jalankan migrasi database:
```bash
php database/migrate.php
```

> 💡 Migrasi akan menambahkan FULLTEXT index, foreign key, dan pengoptimalan lainnya secara otomatis.

### 5. Buat Direktori Runtime

```bash
cd /opt/lampp/htdocs/MEeL
mkdir -p data_drive/public data_drive/private_admins temp profile/upload music/upload/file music/upload/thumbnail books/upload/manga books/upload/pdf books/upload/thumbnail

sudo chown -R www-data:www-data data_drive temp profile/upload music/upload books/upload
sudo chmod -R 775 data_drive temp profile/upload music/upload books/upload
```

> 💡 Jika `www-data` tidak berfungsi, coba `daemon` atau `nobody`.
> ⚠️ `books/upload`, `music/upload`, `video/upload` adalah **symlink git** yang
> menunjuk ke HDD storage — cek & perbaiki SEBELUM membuat sub-direktori di
> bawahnya (lihat [5a. Media Storage](#5a-media-storage-meel_hdd_base--upload-symlinks)).

### 5a. Media Storage (MEEL_HDD_BASE) & Upload Symlinks

Semua path media terpusat di `auth/settings.php` — ubah **satu baris** dan
seluruh sistem mengikuti:

```php
// auth/settings.php
// WAJIB DIGANTI sebelum produksi — nilai default hanya placeholder!
define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');

// Path turunan (otomatis):
//   MEEL_HDD_VIDEO_UPLOAD = MEEL_HDD_BASE . '/video/upload/'
//   MEEL_HDD_VIDEO_DIR    = MEEL_HDD_BASE . '/video/upload/video/'
//   MEEL_HDD_THUMB_DIR    = MEEL_HDD_BASE . '/video/upload/thumbnail/'
//   MEEL_HDD_MUSIC_UPLOAD = MEEL_HDD_BASE . '/music/upload/'
//   MEEL_HDD_BOOKS_UPLOAD = MEEL_HDD_BASE . '/books/upload/'
//   MEEL_HDD_DRIVE        = MEEL_HDD_BASE . '/drive/'
```

#### Cara kerja symlink upload

Repositori men-track `books/upload`, `music/upload`, dan `video/upload` sebagai
**symlink** (mode git `120000`) yang menunjuk ke path HDD *pemilik sebelumnya*
(mis. `/media/<user>/MEeL/media/books/upload`). Pada clone baru, symlink itu
**broken** sampai Anda mengarahkannya ke `MEEL_HDD_BASE` Anda sendiri.

#### 1. Mount / buat storage

```bash
df -h   # cek mount point yang tersedia

# Contoh /etc/fstab untuk mount permanen (opsional):
# /dev/sdb1  /media/<user>/MEeL/media  ext4  defaults,nofail  0 2
sudo mount -a
```

#### 2. Buat struktur direktori storage

```bash
BASE=/media/<user>/MEeL/media   # ganti dengan path ANDA
mkdir -p "$BASE"/video/upload/video "$BASE"/video/upload/thumbnail
mkdir -p "$BASE"/music/upload/file   "$BASE"/music/upload/thumbnail
mkdir -p "$BASE"/books/upload/manga  "$BASE"/books/upload/pdf "$BASE"/books/upload/thumbnail
mkdir -p "$BASE"/drive
```

#### 3. Cek symlink yang ter-track

```bash
ls -la books/upload music/upload video/upload
readlink books/upload
```

**Gejala symlink broken** (storage tidak ter-mount atau path berubah):

```
books/upload: broken symbolic link to /media/[devuser]/MEeL/media/books/upload
```

#### 4. Buat ulang symlink ke path Anda

```bash
cd /opt/lampp/htdocs/MEeL
for d in books music video; do
    rm -f "$d/upload"
    ln -s "$BASE/$d/upload" "$d/upload"
done
ls -la books/upload music/upload video/upload   # harus menunjuk ke BASE Anda
```

#### 5. Perizinan

```bash
sudo chown -R www-data:www-data "$BASE"
sudo chmod -R 775 "$BASE"
```

#### 6. .htaccess keamanan di folder upload (wajib)

Setiap direktori upload wajib berisi `.htaccess` yang mematikan eksekusi PHP
(`php_flag engine off`), guard MIME `ForceType`, dan `Options -Indexes` — pola
yang sama dengan `data_drive/.htaccess`. `tests/security_test.php` memverifikasi ini:

```bash
php tests/security_test.php
```

> Security test sekarang melaporkan **0 FAIL di semua environment** — aturan deny
> `.htaccess` folder upload ter-track di repo dan diverifikasi secara statis.
> Ia bisa mengeluarkan **5 warning non-kritis** (review query mentah MediaViewer,
> cek MIME profile_edit, dan deteksi parameter session) — itu item review,
> bukan masalah deployment. Untuk verifikasi level storage gunakan
> `tests/check_deploy.php` (lihat [5a. Media Storage](#5a-media-storage-meel_hdd_base--upload-symlinks)).

#### 7. Verifikasi storage

```bash
df -h "$BASE"
test -d "$BASE/video/upload/video" && echo "storage OK"
php -r "require 'auth/settings.php'; echo defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : 'NOT SET';"
```

#### 8. Cek deployment otomatis (satu perintah)

Project menyertakan health-check CLI yang memverifikasi empat area kritis
deployment dalam satu kali jalan — `MEEL_HDD_BASE`, symlink upload, hardening
`.htaccess` folder upload, dan aturan `mod_rewrite` PWA:

```bash
php tests/check_deploy.php                           # cek lokal + probe HTTP otomatis
php tests/check_deploy.php --url=http://localhost/MEeL   # probe HTTP eksplisit
php tests/check_deploy.php --hdd=/tmp/meel-storage/media  # override MEEL_HDD_BASE (testing/CI)
php tests/check_deploy.php --no-color                # tanpa warna ANSI (untuk CI/log)
```

Setiap item dilaporkan sebagai `PASS` / `WARN` / `FAIL` beserta ringkasan; exit
code `0` saat sehat dan `1` saat ada minimal satu FAIL (ramah CI). Jika storage
belum ter-mount, script melaporkan FAIL pada area storage / symlink / `.htaccess`
upload — mount storage, perbaiki symlink, tambahkan file `.htaccess`, lalu
jalankan ulang sampai muncul `✅ Deployment sehat.`

### 6. Konfigurasi Apache

#### Aktifkan mod_rewrite:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Pastikan AllowOverride aktif:
Edit `/etc/apache2/apache2.conf`:
```apache
<Directory /var/www/html>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

#### 📱 mod_rewrite & PWA (wajib)

**PWA bergantung pada mod_rewrite + pemrosesan .htaccess**: service worker
dibangkitkan oleh `sw.js.php` dan disajikan sebagai `/sw.js` via rewrite
`.htaccess` root. Verifikasi bahwa ini bekerja:

```bash
curl -sI http://localhost/MEeL/sw.js | grep -i content-type
# Content-Type: application/javascript; charset=utf-8   ← benar
```

Jika `.htaccess` tidak diproses (`AllowOverride` dinonaktifkan), `/sw.js`
mengembalikan 404 dan PWA menurun secara diam-diam — situs tetap berfungsi,
tetapi **mode offline dan "Add to Home Screen" berhenti bekerja**.

#### ⚡ Aktifkan mod_xsendfile (Opsional — untuk akselerasi streaming)

mod_xsendfile mempercepat streaming file besar (FLAC 33MB+, MKV 4K) dengan
membiarkan Apache mengirim file langsung dari disk tanpa melalui PHP.

**Langkah-langkah:**

1. Download source:
   ```bash
   git clone --depth 1 https://github.com/nmaier/mod_xsendfile.git /tmp/mod_xsendfile
   cd /tmp/mod_xsendfile
   ```

2. Kompilasi dengan `apxs` milik server:
   ```bash
   # Untuk LAMPP:
   /opt/lampp/bin/apxs -c mod_xsendfile.c
   
   # Untuk Apache standar:
   sudo apxs -c mod_xsendfile.c
   ```

   > 💡 Jika `apxs` gagal dengan `libtool: compile: you must specify a compilation command`,
   > kompilasi manual dengan gcc:
   > ```bash
   > gcc -c -I/opt/lampp/include -I/opt/lampp/include/apr-1 -fPIC -DPIC mod_xsendfile.c -o mod_xsendfile.o
   > gcc -shared -o mod_xsendfile.so mod_xsendfile.o -L/opt/lampp/lib -lapr-1
   > ```

3. Install modul:
   ```bash
   sudo cp mod_xsendfile.so /opt/lampp/modules/  # atau direktori modules Apache Anda
   sudo chmod 755 /opt/lampp/modules/mod_xsendfile.so
   ```

4. Tambahkan ke `httpd.conf`:
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

6. Verifikasi:
   ```bash
   sudo /opt/lampp/bin/httpd -M | grep xsend
   # Output: xsendfile_module (shared)
   ```

7. Aktifkan di aplikasi — edit `auth/settings.php`:
   ```php
   define('MEEL_USE_XSENDFILE', true);
   ```

### 7. Setup FFmpeg

```bash
# Ubuntu/Debian
sudo apt install ffmpeg

# Verifikasi
ffmpeg -version
ffprobe -version

# Cek lokasi binary
which ffmpeg    # Output: /usr/bin/ffmpeg
which ffprobe   # Output: /usr/bin/ffprobe
```

### 8. Setup yt-dlp

```bash
# Install via pip
sudo apt install python3-pip
pip3 install yt-dlp

# Atau download langsung
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp

# Verifikasi
yt-dlp --version
```

### 10. Migration System

Setelah semua setup selesai, jalankan migrasi database untuk mengoptimalkan skema:
```bash
# Dari root proyek
/opt/lampp/bin/php database/migrate.php
```

Migration bersifat **idempotent** — aman dijalankan berulang kali. Mengelola
**v1–v11** (tracker otomatis di tabel `db_version`):
- **v1–v5:** FULLTEXT index, performance index, sinkronisasi struktural, foreign key, tipe title
- **v6–v7:** tabel `activity_log`, UNIQUE KEY pada username
- **v8–v9:** sync kolom role, **kolom MFA** (`mfa_secret`, `mfa_backup_codes`, `mfa_enabled`)
- **v10:** index komposit `comments` `(video_id, created_at)` & `(music_id, created_at)`
- **v11:** unique key `interactions` dipecah menjadi `(user_id, video_id)` & `(user_id, music_id)`

### 11. Setup cookies.txt (untuk yt-dlp)

Untuk download dari YouTube dan platform lain, ekspor cookie browser:
1. Install ekstensi [Get cookies.txt LOCALLY](https://chrome.google.com/webstore/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc)
2. Login ke YouTube di browser
3. Ekspor cookies ke format Netscape
4. Simpan sebagai `cookies.txt` di root proyek:

```bash
cp /path/to/cookies.txt /opt/lampp/htdocs/MEeL/cookies.txt
```

---

## Verifikasi Instalasi

1. Nyalakan Apache & MySQL:
   ```bash
   # LAMPP
   sudo /opt/lampp/lampp start
   
   # Manual
   sudo systemctl start apache2 mysql
   ```

2. Buka browser: `http://localhost/MEeL/`

3. Login dengan:
   - **Username:** `Admin`
   - **Password:** `Admin#123`

4. Cek halaman Admin: `http://localhost/MEeL/admin/`

---

## Troubleshooting Instalasi

### ❌ "Koneksi ke database gagal"
- Pastikan MySQL/MariaDB berjalan: `sudo systemctl status mysql`
- Verifikasi kredensial di `auth/settings.php`
- Coba: `mysql -u root -p -e "SHOW DATABASES;"`

### ❌ "Penyimpanan Offline" / Redirect ke maintenance
- Periksa path HDD di `auth/settings.php`:
  ```php
  define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');
  ```
- Sesuaikan dengan mount point Anda: `df -h` untuk cek mount point
- Pastikan storage ter-mount dan symlink upload valid:
  ```bash
  readlink books/upload music/upload video/upload
  ls -ld books/upload music/upload video/upload   # jangan ada "broken symbolic link"
  ```
- Atau nonaktifkan sementara untuk development

### ❌ Deployment Check melaporkan FAIL pada folder upload
- **Gejala:** `php tests/check_deploy.php` → `FAIL` pada symlink upload /
  `.htaccess` folder upload (storage tidak ter-mount atau symlink broken).
- **Penyebab:** storage HDD (`MEEL_HDD_BASE`) tidak ter-mount, atau symlink
  upload yang ter-track masih menunjuk ke path pemilik sebelumnya setelah cloning.
- **Perbaikan:** mount storage, buat ulang symlink, dan pastikan setiap folder
  upload punya `.htaccess` (lihat [5a. Media Storage](#5a-media-storage-meel_hdd_base--upload-symlinks)).
  Jalankan ulang `php tests/check_deploy.php` sampai muncul `✅ Deployment sehat.`

### ❌ "403 Forbidden" pada halaman
- Periksa `.htaccess` di direktori terkait
- Pastikan `AllowOverride All` di konfigurasi Apache

### ❌ "500 Internal Server Error"
- Cek error log: `sudo tail -f /var/log/apache2/error.log`
- Aktifkan error reporting di PHP: `ini_set('display_errors', 1);`

### ❌ FFmpeg/yt-dlp tidak ditemukan
- Pastikan binary terinstall: `which ffmpeg && which yt-dlp`
- Transcoder dan Uploader sudah auto-detect via `resolveBinary()`

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
