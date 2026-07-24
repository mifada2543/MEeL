# 🔧 Troubleshooting & FAQ

Panduan pemecahan masalah umum di MEeL-HUB.

---

## 📋 Daftar Isi

- [Masalah Database](#masalah-database)
- [Masalah Storage & HDD](#masalah-storage--hdd)
- [Masalah Transcoding](#masalah-transcoding)
- [Masalah yt-dlp](#masalah-yt-dlp)
- [Masalah Sesi & Login](#masalah-sesi--login)
- [Masalah Upload](#masalah-upload)
- [Masalah Performa](#masalah-performa)
- [Error Codes](#error-codes)

---

## Masalah Database

### ❌ "Koneksi ke database gagal"

**Penyebab:**
- MySQL/MariaDB tidak berjalan
- Kredensial di `auth/config.php` salah
- Database `MEeL` belum dibuat

**Solusi:**
```bash
# Cek status MySQL
sudo systemctl status mysql
# atau untuk LAMPP
sudo /opt/lampp/lampp status

# Jika tidak berjalan
sudo systemctl start mysql

# Verifikasi kredensial
mysql -u root -p -e "SHOW DATABASES;"

# Buat database jika belum ada
mysql -u root -p -e "CREATE DATABASE MEeL DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
```

### ❌ "Tabel tidak ditemukan"

**Penyebab:** SQL belum di-import

**Solusi:**
```bash
mysql -u root -p MEeL < database/schema.sql
```
File `database/schema.sql` berisi seluruh skema (16 tabel + admin default) — import langsung!

### ❌ "Column 'description' cannot be null" (atau error kolom lain)

**Penyebab:** Versi skema database tidak sesuai dengan kode

**Solusi:** Jalankan ALTER TABLE untuk menambahkan kolom yang hilang:
```sql
ALTER TABLE video ADD COLUMN description text DEFAULT NULL;
ALTER TABLE music ADD COLUMN description text DEFAULT NULL;
ALTER TABLE video ADD COLUMN duration int(11) DEFAULT 0;
ALTER TABLE music ADD COLUMN duration int(11) DEFAULT 0;
ALTER TABLE video ADD COLUMN search_metadata text DEFAULT NULL;
ALTER TABLE music ADD COLUMN search_metadata text DEFAULT NULL;
ALTER TABLE users ADD COLUMN last_session_id varchar(128) DEFAULT NULL;
ALTER TABLE users ADD COLUMN access_via varchar(100) DEFAULT NULL;
ALTER TABLE comments ADD COLUMN comment text NOT NULL;
```

---

## Masalah Storage & HDD

### ❌ Redirect ke `err/maintance.php`

**Penyebab:** `MEEL_HDD_BASE` di `auth/config.php` tidak cocok dengan mount point.

**Solusi:**

1. Cek mount point HDD:
   ```bash
   df -h
   lsblk
   ```

2. Sesuaikan path di `auth/config.php` — **hanya satu baris**:
   ```php
   define('MEEL_HDD_BASE', '/path/yang/benar');
   ```
   Semua modul akan otomatis mengikuti.

3. Untuk development/testing, nonaktifkan HDD check sementara:
   ```php
   // modules/helpers.php - comment out the check
   // if (!is_dir($hdd_check_path)) { ... }
   ```

4. Perbaiki permission jika perlu:
   ```bash
   sudo chown -R www-data:www-data /media/[user]/MEeL
   sudo chmod -R 775 /media/[user]/MEeL
   ```

### ❌ "File tidak ditemukan di HDD" setelah upload

**Penyebab:** Permission filesystem atau `MEEL_HDD_BASE` salah.

**Solusi:**
```bash
# Cek apakah folder tujuan bisa diakses
ls -la /media/[user]/MEeL/media/video/upload/

# Perbaiki permission
sudo setfacl -R -m u:daemon:rx /media/[user]/MEeL/media

# Atau berikan akses penuh
sudo chmod -R 777 /opt/lampp/htdocs/MEeL/temp/
```

### Debug Mode untuk Admin

Buka `err/maintance.php` sebagai admin untuk melihat diagnosa lengkap path storage, termasuk:
- Status exists/is_dir/readable/executable per path
- Permission (numeric + ACL)
- Owner:Group
- Rekomendasi perbaikan otomatis

---

## Masalah Transcoding

### ❌ Proses Transcoding Gagal / Stuck

**Penyebab 1: Queue Stuck**
1. Buka Admin Panel → **Active Background Tasks**
2. Klik **Clean Stuck Queues** atau **Force Stop** per task

**Penyebab 2: FFmpeg Error**
```bash
# Cek FFmpeg installation
ffmpeg -version
which ffmpeg

# Transcoder.php sudah auto-detect via resolveBinary()
# Jika ingin custom path, ubah array candidate di constructor
```

**Penyebab 3: Resource Limit**
```bash
# Monitor CPU/RAM
htop

# Cek proses berjalan
ps aux | grep ffmpeg

# Kill paksa jika perlu
killall -9 ffmpeg
```

### ❌ "FFmpeg gagal menghasilkan file output"

**Solusi:**
1. Periksa disk space:
   ```bash
   df -h
   ```

2. Periksa permission temp folder:
   ```bash
   ls -la /opt/lampp/htdocs/MEeL/temp/
   sudo chmod -R 777 /opt/lampp/htdocs/MEeL/temp/
   ```

3. Coba transcode manual untuk debugging:
   ```bash
   ffmpeg -i input.mp4 -codec copy -hls_time 10 output.m3u8
   ```

### ❌ Transcoding Lambat

**Optimasi:**
```php
// Sesuaikan FFMPEG_THREADS dengan CPU Anda
// Di modules/Transcoder.php
private const FFMPEG_THREADS = 8; // Ganti dengan jumlah core CPU Anda (nproc)
```

Cek CPU:
```bash
nproc  # Jumlah core
lscpu  # Detail CPU
```

---

## Masalah yt-dlp

### ❌ "Gagal ambil metadata" atau "Gagal parsing metadata"

**Penyebab 1: yt-dlp tidak terinstall**
```bash
which yt-dlp
# Jika tidak ditemukan:
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp
```

**Penyebab 2: Node.js tidak terinstall**
```bash
which node
# Jika tidak ditemukan:
sudo apt install nodejs
```

**Penyebab 3: Cookies tidak valid/expired**
- Export ulang cookies dari browser
- Pastikan Anda login ke YouTube/platform target
- Simpan sebagai `cookies.txt` di root proyek

**Penyebab 4: Update yt-dlp**
```bash
yt-dlp -U
# atau
pip3 install -U yt-dlp
```

### ❌ "Download gagal" / yt-dlp error

Cek log error:
```bash
cat /tmp/ytdlp_error.log
```

**Solusi umum:**
1. Update yt-dlp ke versi terbaru
2. Update cookies.txt
3. Coba URL di terminal langsung:
   ```bash
   yt-dlp --cookies cookies.txt "https://youtube.com/watch?v=..."
   ```
4. Jika video di-private/region-locked, tidak bisa di-download

### ❌ Debug Metadata

Saat metadata gagal di-parsing, Transcoder akan menampilkan debug overlay:
```
╔═══════════════════════════════════════════════════╗
║ ⚠️ DEBUG: GAGAL PARSING METADATA                 ║
║                                                   ║
║ URL: https://youtube.com/watch?v=...              ║
║ Return Code: 1 (0 = sukses)                       ║
║ Output: ...                                       ║
║                                                   ║
║ Cek Path System:                                  ║
║ - Node:    /usr/bin/node                          ║
║ - yt-dlp:  /usr/local/bin/yt-dlp                  ║
║ - ffmpeg:  /usr/bin/ffmpeg                        ║
╚═══════════════════════════════════════════════════╝
```

---

## Masalah Sesi & Login

### ❌ Sesi Tiba-tiba Berakhir

**Penyebab:**
- Session timeout (12 jam)
- Session di-kick oleh admin
- Login dari perangkat lain

**Solusi:**
1. Login ulang
2. Jika sering terjadi, cek waktu server:
   ```bash
   timedatectl status
   ```
3. Pastikan folder session PHP writable:
   ```bash
   sudo chmod 777 /var/lib/php/sessions
   # atau
   sudo chmod 777 /tmp
   ```

### ❌ Terus menerus dialihkan ke `/err/revoked.php` (Session Revoked)

**Penyebab:** Session ID di browser Anda tidak cocok dengan yang terdaftar di database (misalnya karena ter-kick oleh Admin, masuk dari perangkat lain, atau cookie sesi terhapus/tidak persisten).

**Solusi:**
1. Klik tombol **Kembali Ke Login** pada layar error dan lakukan login ulang.
2. Bersihkan cache dan cookie browser Anda khusus untuk domain platform ini.
3. Jika menggunakan Cloudflare Tunnel, pastikan pengaturan kuki sesi diatur agar tetap persisten.
4. Coba akses menggunakan Mode Incognito / Private Window di browser Anda.

### ❌ Dialihkan ke `/err/banned.php` (Access Blocked)

**Penyebab:** Alamat IP Anda terdaftar pada tabel pemblokiran firewall internal (`ip_ban`).

**Solusi:**
1. Hubungi Admin MEeL-HUB untuk menghapus IP Anda dari daftar cek di Admin Panel -> **Firewall & Banned IPs**.
2. Jika Anda adalah Admin, Anda dapat mem-bypass blokir secara default (karena role admin dikecualikan dari pengecekan ban di `activity_logger.php`), atau masuk menggunakan koneksi lokal/IP lain terlebih dahulu untuk menghapus IP tersebut melalui dashboard.

### ❌ Login Gagal Terus (Locked)

**Penyebab:** 5x gagal login dalam waktu singkat

**Solusi:**
- Tunggu 5 menit (countdown otomatis)
- Atau hapus session lock di database:
  ```sql
  -- Hapus login_fail_count dari session (tidak bisa langsung dari DB)
  -- Alternatif: restart Apache
  sudo systemctl restart apache2
  ```

---

## Masalah Upload

### ❌ Upload Gagal "File terlalu besar"

**Solusi:** Perbesar `upload_max_filesize` di `php.ini`:
```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 300
max_input_time = 300
```

Restart Apache setelah mengubah:
```bash
sudo systemctl restart apache2
```

### ❌ "Batas upload tercapai!"

Non-admin memiliki limit:
- **2 upload per jam** untuk video & music
- **10 upload per jam** untuk drive

Tunggu hingga kuota ter-reset, atau minta admin untuk meng-upload-kan.

### ❌ Upload Advanced URL Error

**Ceklist:**
1. URL harus valid (https://)
2. URL tidak lebih dari 500 karakter
3. Server tidak sedang sibuk (max 2 proses simultan)
4. yt-dlp terinstall dan cookies valid
5. Cukup storage space

---

## Masalah Performa

### ❌ Halaman Lambat

**Optimasi:**
1. **Aktifkan OPcache** di `php.ini`:
   ```ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=4000
   ```

2. **Monitor database**:
   ```sql
   SHOW PROCESSLIST;
   EXPLAIN SELECT * FROM video ORDER BY upload_date DESC;
   ```
   Tambahkan index jika perlu:
   ```sql
   ALTER TABLE video ADD INDEX idx_upload_date (upload_date);
   ALTER TABLE music ADD INDEX idx_upload_date (upload_date);
   ```

3. **Cek koneksi internet** untuk asset eksternal (jika ada loading CDN seperti Google Fonts di file testing)

### ❌ CPU 100% saat Transcoding

**Penyebab:** Transcoding HLS atau sprite generation

**Solusi:**
1. Turunkan `FFMPEG_THREADS`:
   ```php
   private const FFMPEG_THREADS = 4; // Dari 8
   ```
2. Batasi proses simultan (already implemented: max 2)
3. Atur transcoding di jam sepi menggunakan cron job

### ❌ HDD/SSD Penuh

**Cleanup rutin:**
1. Admin Panel → **Database Sync Check** → Bersihkan file orphan
2. Hapus file temporary:
   ```bash
   rm -rf /opt/lampp/htdocs/MEeL/temp/*
   ```
3. Bersihkan guest users: Admin Panel → **Clean Inactive Guests**
4. Hapus queue stuck: Admin Panel → **Clean Stuck Queues**
5. Hapus file drive yang tidak perlu

---

## Error Codes

### HTTP Status Codes

| Kode | Arti | Penyebab Umum |
|------|------|---------------|
| **401** | Unauthorized | User belum login |
| **403** | Forbidden | IP banned, user inactive, role insufficient |
| **404** | Not Found | Media tidak ada, file hilang |
| **500** | Internal Server Error | Database error, PHP error |
| **503** | Service Unavailable | HDD offline, server busy |

### System Error Messages

| Pesan | Arti | Solusi |
|-------|------|--------|
| "Penyimpanan Offline" | HDD external tidak ter-mount | Cek `df -h` dan path di `helpers.php` |
| "Server sedang sibuk" | Queue penuh (max 2) | Tunggu atau bersihkan queue stuck |
| "Batas upload tercapai!" | Rate limit aktif | Tunggu 1 jam atau minta admin |
| "File terlalu besar!" | Melebihi quota | Upload file lebih kecil |
| "Durasi terlalu panjang!" | Melebihi batas durasi | Gunakan video lebih pendek |
| "Security Error" | File mencurigakan | Upload file dengan format benar |

---

## FAQ

### Q: Apakah MEeL bisa diakses dari internet?
**A:** Bisa, tapi disarankan menggunakan Cloudflare Tunnel atau VPN. Jangan expose langsung tanpa HTTPS.

### Q: Bagaimana cara backup data?
**A:** Backup database + folder media:
```bash
mysqldump -u root -p MEeL > backup_meel.sql
tar -czf media_backup.tar.gz /media/[user]/MEeL/
```

### Q: Kenapa video tidak muncul thumbnail?
**A:** Thumbnail digenerate otomatis dari frame ke-5 video. Pastikan FFmpeg terinstall.

### Q: Format musik apa yang didukung?
**A:** MP3, OGG/Opus, M4A/AAC, FLAC, WAV. Semua akan di-transcode ke Opus/OGG.

### Q: Bagaimana cara menambahkan admin baru?
**A:** Register user biasa, lalu ubah role di database:
```sql
UPDATE users SET role = 'admin', is_active = 1 WHERE id = [user_id];
```

### Q: Apakah bisa streaming video 4K?
**A:** Secara teknis bisa, tapi kendala di bandwidth dan storage. Disarankan maksimal 1080p.

### Q: Kenapa session guest tidak valid?
**A:** Guest otomatis dibuat saat pertama kali akses. Jika ada masalah, hapus guest tidak aktif via Admin Panel.

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
