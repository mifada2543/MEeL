<?php

// ════════════════════════════════════════════════════════════════
// helpers/stream_auth.php — Otorisasi Akses Streaming Audio
//
// Prinsip: music/stream.php hanya melayani request dari browser yang
// baru saja membuka halaman MEeL yang MENAMPILKAN media tersebut
// (watch.php, index.php, search, load_more, view_playlist.php).
//
// Halaman-halaman itu memanggil authorize_stream($id) saat render
// (menandai id media di session server). stream.php lalu memeriksa
// is_stream_authorized($id) — jika tidak ada tanda, akses DITOLAK.
//
// Efek:
//   - Akses langsung ke URL stream (ketik address bar, curl, hotlink
//     tanpa konteks halaman) → konsisten ditolak.
//   - Pemutaran normal dari pemutar MEeL tetap berjalan.
//
// Catatan: ini lapisan otorisasi SESSION (bukan anti-download absolut —
// byte audio tetap sampai ke browser saat diputar). Referer check lama
// di stream.php dipertahankan sebagai lapisan tambahan (defense in depth).
// ════════════════════════════════════════════════════════════════

if (!function_exists('authorize_stream')) {
    /**
     * Tandai id media sebagai "diizinkan streaming" oleh sesi saat ini.
     * Dipanggil oleh halaman yang merender/menampilkan media tsb.
     *
     * @param int $id ID media (tabel music)
     */
    function authorize_stream(int $id): void
    {
        if ($id <= 0) return;

        if (!isset($_SESSION['stream_ok']) || !is_array($_SESSION['stream_ok'])) {
            $_SESSION['stream_ok'] = [];
        }

        $_SESSION['stream_ok'][$id] = time();

        // Batasi ukuran marker (FIFO) agar session tidak membengkak —
        // buang entri dengan timestamp tertua saat melebihi kapasitas.
        // Catatan: jangan pakai array_shift() — ia me-reindex kunci numerik
        // (0,1,2,...) sehingga mapping id → timestamp menjadi rusak.
        if (count($_SESSION['stream_ok']) > 100) {
            $oldest = array_search(min($_SESSION['stream_ok']), $_SESSION['stream_ok'], true);
            if ($oldest !== false) {
                unset($_SESSION['stream_ok'][$oldest]);
            }
        }
    }
}

if (!function_exists('is_stream_authorized')) {
    /**
     * Cek apakah id media diizinkan streaming oleh sesi saat ini.
     *
     * TTL default 2 jam: cukup untuk pemutaran normal (playback dimulai
     * dalam hitungan detik setelah halaman dirender) dan mini-player yang
     * melanjutkan di halaman musik berikutnya — setiap render halaman musik
     * me-refresh marker. Jendela unduh jadi jauh lebih sempit daripada
     * session lifetime (12 jam). Bisa disesuaikan via parameter $ttl.
     *
     * @param int $id  ID media
     * @param int $ttl Umur marker dalam detik (default 2 jam)
     */
    function is_stream_authorized(int $id, int $ttl = 7200): bool
    {
        if ($id <= 0) return false;
        if (!is_array($_SESSION['stream_ok'] ?? null)) return false;
        if (empty($_SESSION['stream_ok'][$id])) return false;

        return (time() - (int)$_SESSION['stream_ok'][$id]) <= $ttl;
    }
}
