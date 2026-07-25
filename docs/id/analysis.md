# Analisis Sistem — MEeL-HUB

> Dokumen ini berisi analisis teknis dan arsitektur sistem MEeL-HUB.
> Untuk analisis development, lihat `docs/id/development.md`.

## Ringkasan Arsitektur

MEeL-HUB adalah platform media hub pribadi yang memungkinkan streaming video,
musik, dan manajemen e-library. Sistem dibangun dengan PHP native (tanpa
framework) dan MySQL/MariaDB sebagai database.

## Komponen Utama

1. **Media Library** — Manajemen koleksi video, musik, dan buku
2. **Uploader** — Penanganan upload file dengan validasi keamanan dan transcode
3. **Transcoder** — Konversi media ke format streaming (HLS untuk video, Opus untuk audio)
4. **Drive** — Cloud storage pribadi dengan scope publik/privat
5. **Auth** — Sistem autentikasi dan otorisasi berbasis session
6. **Arcade** — Game mini (catur, snake, dino) terintegrasi

## Alur Data

1. User mengupload file → Uploader → Validasi (magic bytes, ekstensi, ukuran)
2. File masuk ke antrian transcode → Transcoder → HLS/Opus
3. File disimpan di HDD storage (MEEL_HDD_BASE)
4. Metadata disimpan di database MySQL
5. Streaming via endpoint khusus (stream.php, watch.php)

## Keamanan

Lihat `docs/id/security.md` untuk detail keamanan.

## Performa

- Cache query role di session untuk mengurangi query berulang
- Garbage collector untuk membersihkan file temporary
- Rate limiter berbasis file untuk proteksi endpoint
- X-Sendfile untuk streaming file besar tanpa beban PHP

## Catatan Teknis

- PHP 8.2+ dengan MySQL 8.0+
- FFmpeg untuk transcode media
- yt-dlp untuk upload dari URL eksternal
- RAM disk (/dev/shm) untuk staging transcode
