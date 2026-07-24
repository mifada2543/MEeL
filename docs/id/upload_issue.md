# 📥 Troubleshooting Advanced Upload & yt-dlp

Dokumentasi penanganan masalah khusus untuk fitur **Advanced Upload (yt-dlp)** dan antrean latar belakang (Background Tasks) di MEeL-HUB.

---

## 📋 Daftar Isi
- [Gejala & Masalah Umum](#gejala--masalah-umum)
- [Masalah 1: Antrean (Queue) Stuck / Server Sibuk](#masalah-1-antrean-queue-stuck--server-sibuk)
- [Masalah 2: Gagal Ambil / Parsing Metadata](#masalah-2-gagal-ambil--parsing-metadata)
- [Masalah 3: Masalah Cookies (Sign-in / Bot Detection)](#masalah-3-masalah-cookies-sign-in--bot-detection)
- [Masalah 4: Kegagalan Transcoding Pasca-Download](#masalah-4-kegagalan-transcoding-pasca-download)

---

## Gejala & Masalah Umum

Fitur Advanced Upload menggunakan pustaka CLI `yt-dlp` yang dikombinasikan dengan pembungkus PHP asinkron untuk mengunduh media dari URL eksternal (seperti YouTube, NicoNico, TikTok) lalu mengonversinya secara otomatis. Masalah biasanya ditandai dengan status antrean yang terus menerus `"processing"` atau muncul notifikasi **"Server sedang sibuk"** meskipun tidak ada proses aktif.

---

## Masalah 1: Antrean (Queue) Stuck / Server Sibuk

Sistem membatasi maksimal **2 proses unduhan/transcoding simultan** demi menjaga stabilitas kinerja CPU server. Jika proses terputus secara tidak wajar (misalnya koneksi putus atau server mati mendadak), status antrean di database tetap menggantung sebagai `"processing"`.

### Solusi A — Melalui Admin Panel (Direkomendasikan)
1. Masuk sebagai akun **Admin**.
2. Buka **Dashboard Admin** (`admin/index.php`).
3. Scroll ke bagian **Active Background Tasks**.
4. Klik tombol **Clean Stuck Queues** (akan membersihkan tugas yang menggantung lebih dari 30 menit).
5. Atau klik ikon silang (**Force Stop** `x`) pada tugas spesifik yang mengalami macet.

### Solusi B — Melalui Database (Manual SQL)
Jalankan perintah SQL berikut melalui phpMyAdmin atau MySQL Client untuk mereset antrean:
```sql
-- Menghapus antrean yang menggantung
DELETE FROM `upload_queue` 
WHERE `status` = 'processing';
```

### Solusi C — Menghentikan Proses di OS (Linux)
Jika proses FFmpeg atau yt-dlp masih berjalan di latar belakang dan memakan CPU:
```bash
# Cek apakah ada proses aktif
ps aux | grep -E "yt-dlp|ffmpeg"

# Hentikan paksa seluruh proses pengunduhan/transcoding
sudo killall -9 yt-dlp
sudo killall -9 ffmpeg
```

---

## Masalah 2: Gagal Ambil / Parsing Metadata

Saat memasukkan URL, sistem memanggil `yt-dlp --print-json` untuk mengekstrak informasi media sebelum mengunduhnya. Jika proses ini gagal, debug overlay akan muncul di layar.

### Langkah Pemeriksaan:
1. **Verifikasi Binary Path**:
   Pastikan executable `yt-dlp`, `ffmpeg`, dan `node` dapat ditemukan di server:
   ```bash
   which yt-dlp   # Harapan: /usr/local/bin/yt-dlp
   which ffmpeg   # Harapan: /usr/bin/ffmpeg
   which node     # Harapan: /usr/bin/node
   ```
2. **Sesuaikan Path di PHP**:
   Buka berkas [Transcoder.php](file:///opt/lampp/htdocs/MEeL/modules/Transcoder.php) dan verifikasi pencarian path binary pada metode `resolveBinary`.
   
   > ⚠️ Konstanta path `HDD_BASE`/`HDD_VIDEO_DIR`/`HDD_THUMB_DIR` telah **dipindahkan** ke `auth/config.php` sebagai `MEEL_HDD_*`. Konfigurasi path storage tidak perlu diubah di Transcoder.php lagi.
3. **Uji URL Langsung di Terminal**:
   Jalankan perintah berikut untuk melihat pesan error asli dari `yt-dlp`:
   ```bash
   yt-dlp --no-warnings --print-json "https://www.youtube.com/watch?v=VIDEO_ID"
   ```

---

## Masalah 3: Masalah Cookies (Sign-in / Bot Detection)

Platform video seperti YouTube sering kali memblokir akses otomatis dari server (Bot/Cloud Provider) dengan tantangan Captcha atau batasan login.

> [!IMPORTANT]
> Sistem membutuhkan kuki browser terkini yang disimpan dalam berkas `cookies.txt` di root proyek MEeL agar bisa mengunduh dengan sukses.

### Cara Memperbarui Cookies:
1. Buka browser Anda dan pasang ekstensi **Get cookies.txt LOCALLY** (Chrome/Firefox).
2. Buka dan masuk (Sign-in) ke akun YouTube Anda.
3. Klik ikon ekstensi dan pilih **Export / Download** cookies untuk domain youtube.com.
4. Simpan berkas hasil download tersebut dengan nama `cookies.txt` lalu pindahkan ke direktori utama proyek MEeL:
   ```bash
   cp /path/ke/download/cookies.txt /opt/lampp/htdocs/MEeL/cookies.txt
   sudo chown www-data:www-data /opt/lampp/htdocs/MEeL/cookies.txt
   sudo chmod 664 /opt/lampp/htdocs/MEeL/cookies.txt
   ```

---

## Masalah 4: Kegagalan Transcoding Pasca-Download

Terkadang file berhasil diunduh secara penuh, namun gagal saat dikonversi ke HLS (untuk video) atau Opus (untuk musik).

### Cara Mengatasi:
1. **Periksa Sisa Ruang Penyimpanan**:
   Transcoding membutuhkan sisa ruang minimal 2x dari ukuran video asli.
   ```bash
   df -h
   ```
2. **Periksa Izin Tulis Folder Staging (temp)**:
   Pastikan folder `temp/` dapat ditulis oleh web server:
   ```bash
   sudo chmod -R 777 /opt/lampp/htdocs/MEeL/temp/
   ```
3. **Kurangi Beban CPU (CPU Throttling)**:
   Jika server Anda mengalami crash atau restart akibat suhu tinggi saat transcoding, kurangi jumlah thread CPU yang dialokasikan untuk FFmpeg di berkas [Transcoder.php](file:///opt/lampp/htdocs/MEeL/modules/Transcoder.php):
   ```php
   // Ganti nilai dari 8 menjadi 4 atau 2 sesuai core CPU Anda
   private const FFMPEG_THREADS = 4;
   ```

   Cek jumlah core CPU: `nproc`

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>