<?php
// Matikan penampilan error agar output binary audio tidak rusak jika ada notice
error_reporting(0);

session_name('meel');
session_start();

// Lepas session lock agar range request streaming tidak terblokir
// File besar seperti FLAC 34MB+ butuh waktu streaming lama
session_write_close();

// ── Referer Gate (ketat): stream HANYA boleh diminta DARI halaman musik MEeL ──
// Audio element di watch.php / index.php / view_playlist.php mengirim header
// Referer = URL halaman itu sendiri (same-origin, policy strict-origin-when-
// cross-origin mengirim URL penuh untuk same-origin). Membuka URL stream
// langsung (ketik di tab baru, curl, hotlink dari situs lain) → Referer
// kosong atau bukan halaman musik MEeL → DITOLAK.
// Catatan: Referer bisa di-spoof, jadi ini lapisan keamanan tambahan di atas
// cek marker session (is_stream_authorized) di bawah.
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$currentHost = $_SERVER['HTTP_HOST'] ?? '';
$refererOk = false;

if ($referer !== '' && $currentHost !== '') {
    $refParts = parse_url($referer);
    if ($refParts && isset($refParts['host'])) {
        // Normalisasi host: HTTP_HOST bisa menyertakan port (mis. localhost:8080)
        // sedangkan parse_url() host tidak menyertakan port — bandingkan tanpa port.
        $currentHostNorm = strtolower(parse_url('http://' . $currentHost, PHP_URL_HOST) ?: $currentHost);
        if (strtolower($refParts['host']) === $currentHostNorm) {
            // Path harus halaman musik yang sah (bukan file acak di host yang sama)
            $refPath      = $refParts['path'] ?? '';
            $refPage      = basename($refPath);
            $allowedPages = ['watch.php', 'index.php', 'view_playlist.php'];
            if (strpos($refPath, '/music/') !== false && in_array($refPage, $allowedPages, true)) {
                $refererOk = true;
            }
        }
    }
}
if (!$refererOk) {
    header("Location: ../err/denied.php");
    exit;
}

// ── Fail-fast: otorisasi session SEBELUM include config.php / koneksi DB ──
// Helper dimuat lebih awal di sini — isinya hanya definisi fungsi, aman tanpa
// config. Ini mencegah request yang tidak sah (hotlink/curl) membuka koneksi
// DB dan menjalankan activity_logger sebelum akhirnya ditolak.
require_once __DIR__ . '/../modules/core/helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("HTTP/1.1 400 Bad Request");
    exit("ID Media tidak valid.");
}

// Otorisasi session: hanya browser yang baru saja membuka halaman musik
// MEeL (watch/index/search/playlist) yang boleh streaming id ini.
// Akses langsung ke URL stream (ketik address bar, curl, hotlink tanpa
// konteks halaman) → ditolak secara konsisten. Marker di-set oleh
// authorize_stream() saat halaman merender media tsb (lihat helpers/stream_auth.php).
if (!is_stream_authorized($id)) {
    header("Location: ../err/denied.php");
    exit;
}

include '../auth/config.php';
require_once '../modules/core/helpers.php';
include '../modules/media/MediaViewer.php';

// 2. Ambil data nama berkas asli dari database lewat MediaViewer
$viewer = new MediaViewer($conn, $_SESSION['user_id'], 'music', $id);
$v = $viewer->getMediaData();

if (!$v || empty($v['filename'])) {
    header("HTTP/1.1 404 Not Found");
    exit("Data audio tidak ditemukan.");
}

// Gunakan path lokal via symlink (upload/file/ → HDD) agar cocok dengan XSendFilePath
// di Apache. Path HDD mentah (MEEL_HDD_MUSIC_UPLOAD) TIDAK cocok dengan XSendFilePath
// dan menyebabkan mod_xsendfile return 404.
$filePath = __DIR__ . '/upload/file/' . basename($v['filename']);

if (!file_exists($filePath)) {
    // Fallback: coba HDD path langsung jika symlink tidak tersedia
    $altPath = defined('MEEL_HDD_MUSIC_UPLOAD')
        ? rtrim(MEEL_HDD_MUSIC_UPLOAD, '/') . '/file/' . basename($v['filename'])
        : null;
    if ($altPath && file_exists($altPath)) {
        $filePath = $altPath;
    } else {
        header("HTTP/1.1 404 Not Found");
        exit("File fisik tidak tersedia di server.");
    }
}

// 3. Tentukan MIME Type yang sesuai secara dinamis
$ext      = strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION));
$mimeType = get_audio_mime_type($ext);

// 4. Debug logging untuk FLAC (aktifkan dengan define('MEEL_STREAM_DEBUG', true) di config.php)
if (defined('MEEL_STREAM_DEBUG') && MEEL_STREAM_DEBUG) {
    error_log("[MEeL-Stream] id=$id ext=$ext size=" . (filesize($filePath) ?? 0) . " ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
}

// 4b. Cegah timeout PHP untuk file besar (FLAC 34MB+ butuh waktu streaming lama)
set_time_limit(0);

// 5. Hentikan script segera saat browser disconnect (misal user pindah lagu)
// Ditaruh setelah semua include agar tidak di-override oleh file lain.
ignore_user_abort(false);

// Matikan output buffering — krusial untuk file besar seperti FLAC
// Jika output_buffering aktif, seluruh file ditahan di RAM server sebelum
// dikirim ke browser, menyebabkan browser stuck "loading" tanpa henti.
while (@ob_get_level()) {
    @ob_end_clean();
}
@ob_implicit_flush(true);

$size = @filesize($filePath);
$length = $size;
$start = 0;
$end = $size - 1;

header("Content-Type: " . $mimeType);
header("Accept-Ranges: bytes");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// 5b. X-Sendfile — Apache langsung kirim file tanpa baca PHP chunk-by-chunk
// Jauh lebih efisien untuk file besar (FLAC 34MB+) karena tidak pakai RAM PHP.
// Cara aktivasi:
//   1. Install mod_xsendfile (https://github.com/nmaier/mod_xsendfile)
//   2. Aktifkan di httpd.conf:
//        XSendFile on
//        XSendFilePath "/opt/lampp/htdocs/MEeL/music/upload/file"
//   3. Restart Apache
//   4. Tambahkan define berikut di auth/config.php:
//        define('MEEL_USE_XSENDFILE', true);
if (defined('MEEL_USE_XSENDFILE') && MEEL_USE_XSENDFILE === true) {
    header("X-Sendfile: " . $filePath);
    header("Content-Length: " . $size);
    exit;
}

if (isset($_SERVER['HTTP_RANGE'])) {
    $c_start = $start;
    $c_end = $end;

    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
    if (strpos($range, ',') !== false) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes $start-$end/$size");
        exit;
    }
    if ($range == '-') {
        $c_start = $size - substr($range, 1);
    } else {
        $range = explode('-', $range);
        $c_start = $range[0];
        $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size - 1;
    }
    $c_end = ($c_end > $end) ? $end : $c_end;
    if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes $start-$end/$size");
        exit;
    }
    $start = $c_start;
    $end = $c_end;
    $length = $end - $start + 1;
    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$size");
}

header("Content-Length: " . $length);

// 6. Salurkan data berkas dalam bentuk chunks (hemat RAM server)
// Chunk size = 512KB untuk FLAC (lebih besar dari 256KB default)
// File besar seperti FLAC 34MB+ butuh chunk lebih besar agar jumlah iterasi
// lebih sedikit. 512KB = ~4 detik audio @1000kbps vs 256KB = ~2 detik.
$flacChunkSize = ($ext === 'flac') ? 524288 : 262144; // 512KB untuk FLAC, 256KB untuk lainnya
define('STREAM_CHUNK_SIZE', $flacChunkSize);

$fp = @fopen($filePath, 'rb');
if (!$fp) {
    header("HTTP/1.1 500 Internal Server Error");
    exit("Tidak bisa membaca file.");
}
@fseek($fp, $start);
while (!@feof($fp) && ($p = @ftell($fp)) <= $end && $p !== false) {
    // Jika user pindah lagu, browser putus koneksi — hentikan loop biar
    // PHP bisa handle request baru tanpa bersaing resource dengan proses lama.
    if (connection_aborted()) break;

    $remaining = $end - $p + 1;
    if ($remaining <= 0) break;

    $chunkSize = ($remaining > STREAM_CHUNK_SIZE) ? STREAM_CHUNK_SIZE : $remaining;
    $buf = @fread($fp, $chunkSize);
    if ($buf === false || $buf === '') break;
    echo $buf;
    @ob_flush();
    @flush();
}
@fclose($fp);
exit;
