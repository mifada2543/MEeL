<?php

/**
 * ArchiveGuard — Validasi & ekstraksi aman arsip ZIP/CBZ (manga/books).
 *
 * Menggantikan pemanggilan langsung ZipArchive::extractTo() ke direktori
 * final. Pipeline:
 *
 *   buka arsip
 *     → inspeksi tiap entry (path traversal, null byte, count, ukuran,
 *       rasio kompresi, kedalaman, symlink, ekstensi)
 *     → ekstrak ke staging directory (server-generated)
 *     → validasi hasil ekstraksi
 *     → pindahkan ke destinasi final (rename per file, tidak menimpa
 *       file acak di luar direktori)
 *
 * Semua limit dapat dikonfigurasi via konstanta (lihat defaults di bawah).
 */

if (!defined('MAX_ARCHIVE_ENTRIES')) {
    define('MAX_ARCHIVE_ENTRIES', 5000);
}
if (!defined('MAX_ARCHIVE_UNCOMPRESSED_BYTES')) {
    define('MAX_ARCHIVE_UNCOMPRESSED_BYTES', 2 * 1024 * 1024 * 1024); // 2 GiB
}
if (!defined('MAX_ARCHIVE_ENTRY_BYTES')) {
    define('MAX_ARCHIVE_ENTRY_BYTES', 200 * 1024 * 1024); // 200 MiB per file
}
if (!defined('MAX_ARCHIVE_COMPRESSION_RATIO')) {
    define('MAX_ARCHIVE_COMPRESSION_RATIO', 300);
}
if (!defined('MAX_ARCHIVE_PATH_DEPTH')) {
    define('MAX_ARCHIVE_PATH_DEPTH', 16);
}

class ArchiveGuard
{
    /**
     * Ekstensi yang diizinkan untuk arsip manga/CBZ (halaman gambar).
     */
    private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif'];

    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Validasi + ekstraksi aman ke $destDir.
     *
     * @param string $archivePath Path arsip ZIP/CBZ (sudah di server, dari upload).
     * @param string $destDir     Direktori final (akan dibuat bila perlu).
     *
     * @return array{ok: bool, error?: string, entries?: int}
     */
    public function extractSafe(string $archivePath, string $destDir): array
    {
        if (!is_file($archivePath) || !is_readable($archivePath)) {
            return ['ok' => false, 'error' => 'File arsip tidak ditemukan atau tidak dapat dibaca.'];
        }
        if (filesize($archivePath) <= 0) {
            return ['ok' => false, 'error' => 'File arsip kosong.'];
        }

        $zip = new ZipArchive();
        $open = @$zip->open($archivePath);
        if ($open !== true) {
            return ['ok' => false, 'error' => 'Gagal membuka arsip ZIP. File mungkin korup atau bukan ZIP.'];
        }

        try {
            $validation = $this->validateEntries($zip);
            if (!$validation['ok']) {
                return $validation;
            }

            // Staging directory server-generated — nama acak, di luar kontrol user.
            $staging = $this->basePath . '/.staging_' . bin2hex(random_bytes(8));
            if (!@mkdir($staging, 0755, true) && !is_dir($staging)) {
                return ['ok' => false, 'error' => 'Gagal membuat direktori staging.'];
            }

            try {
                $extractResult = $this->extractToStaging($zip, $staging);
                if (!$extractResult['ok']) {
                    return $extractResult;
                }

                $moveResult = $this->moveStagingToDest($staging, $destDir);
                if (!$moveResult['ok']) {
                    return $moveResult;
                }

                return ['ok' => true, 'entries' => $validation['entries']];
            } finally {
                $this->removeDirRecursive($staging);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Periksa seluruh entry tanpa mengekstrak apa pun.
     *
     * @return array{ok: bool, error?: string, entries?: int}
     */
    private function validateEntries(ZipArchive $zip): array
    {
        $count = $zip->numFiles;
        if ($count === false || $count <= 0) {
            return ['ok' => false, 'error' => 'Arsip tidak berisi file apapun.'];
        }
        if ($count > MAX_ARCHIVE_ENTRIES) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Arsip terlalu banyak entri (%d; maks %d).',
                    $count,
                    MAX_ARCHIVE_ENTRIES
                ),
            ];
        }

        $totalUncompressed = 0;

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return ['ok' => false, 'error' => "Entry #{$i} tidak dapat dibaca."];
            }

            $name = (string) ($stat['name'] ?? '');

            // 1. Path traversal / absolute path / null byte
            $pathCheck = $this->validateEntryName($name);
            if (!$pathCheck['ok']) {
                return $pathCheck;
            }

            // 2. Ukuran per-file & total (uncompressed)
            $entrySize = (int) ($stat['size'] ?? 0);
            if ($entrySize < 0) {
                return ['ok' => false, 'error' => "Entry '{$name}' memiliki ukuran tidak valid."];
            }
            if ($entrySize > MAX_ARCHIVE_ENTRY_BYTES) {
                return [
                    'ok'    => false,
                    'error' => sprintf(
                        "Entry '%s' terlalu besar (%d bytes; maks %d).",
                        $this->shortName($name),
                        $entrySize,
                        MAX_ARCHIVE_ENTRY_BYTES
                    ),
                ];
            }

            $totalUncompressed += $entrySize;
            if ($totalUncompressed > MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                return [
                    'ok'    => false,
                    'error' => sprintf(
                        'Total ukuran setelah diekstrak melebihi batas (%d bytes; maks %d).',
                        $totalUncompressed,
                        MAX_ARCHIVE_UNCOMPRESSED_BYTES
                    ),
                ];
            }

            // 3. Rasio kompresi (zip bomb)
            $compSize = (int) ($stat['comp_size'] ?? 0);
            if ($compSize > 0 && $entrySize > 0) {
                $ratio = $entrySize / $compSize;
                if ($ratio > MAX_ARCHIVE_COMPRESSION_RATIO) {
                    return [
                        'ok'    => false,
                        'error' => sprintf(
                            "Entry '%s' memiliki rasio kompresi mencurigakan (%.1fx; maks %d).",
                            $this->shortName($name),
                            $ratio,
                            MAX_ARCHIVE_COMPRESSION_RATIO
                        ),
                    ];
                }
            }

            // 4. Ekstensi diizinkan (hanya untuk file non-direktori)
            if (substr($name, -1) !== '/') {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
                    return [
                        'ok'    => false,
                        'error' => sprintf(
                            "Entry '%s' bertipe %s — hanya file gambar yang diizinkan dalam arsip manga/CBZ.",
                            $this->shortName($name),
                            $ext !== '' ? ".{$ext}" : '(tanpa ekstensi)'
                        ),
                    ];
                }
            }
        }

        return ['ok' => true, 'entries' => $count];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function validateEntryName(string $name): array
    {
        if ($name === '') {
            return ['ok' => false, 'error' => 'Arsip berisi entry dengan nama kosong.'];
        }
        if (str_contains($name, "\0")) {
            return ['ok' => false, 'error' => 'Arsip berisi null byte pada nama entry.'];
        }

        // Normalisasi backslash (Windows) ke slash untuk pemeriksaan traversal.
        $norm = str_replace('\\', '/', $name);
        if (str_starts_with($norm, '/')) {
            return ['ok' => false, 'error' => 'Arsip berisi absolute path.'];
        }

        $depth = 0;
        foreach (explode('/', $norm) as $segment) {
            if ($segment === '..') {
                return ['ok' => false, 'error' => 'Arsip berisi path traversal (..).'];
            }
            if ($segment !== '' && $segment !== '.') {
                $depth++;
            }
        }
        if ($depth > MAX_ARCHIVE_PATH_DEPTH) {
            return [
                'ok'    => false,
                'error' => sprintf(
                    'Entry terlalu dalam (%d level; maks %d).',
                    $depth,
                    MAX_ARCHIVE_PATH_DEPTH
                ),
            ];
        }

        return ['ok' => true];
    }

    /**
     * Ekstrak entry satu per satu ke staging — tidak memakai extractTo().
     *
     * @return array{ok: bool, error?: string}
     */
    private function extractToStaging(ZipArchive $zip, string $staging): array
    {
        $count = $zip->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $name = (string) ($zip->statIndex($i)['name'] ?? '');
            $norm = str_replace('\\', '/', $name);

            $target = $staging . '/' . $norm;
            if (substr($norm, -1) === '/') {
                // Direktori
                if (!@mkdir($target, 0755, true) && !is_dir($target)) {
                    return ['ok' => false, 'error' => "Gagal membuat direktori '{$this->shortName($name)}'."];
                }
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return ['ok' => false, 'error' => "Gagal membuat direktori '{$this->shortName($name)}'."];
                }
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                return ['ok' => false, 'error' => "Gagal membaca entry '{$this->shortName($name)}'."];
            }
            // Batasi memori: jangan tulis lebih dari batas per-file
            if (strlen($content) > MAX_ARCHIVE_ENTRY_BYTES) {
                return ['ok' => false, 'error' => "Entry '{$this->shortName($name)}' melebihi batas ukuran."];
            }
            if (file_put_contents($target, $content, LOCK_EX) === false) {
                return ['ok' => false, 'error' => "Gagal menulis entry '{$this->shortName($name)}'."];
            }
        }

        return ['ok' => true];
    }

    /**
     * Pindahkan file staging ke destinasi final. File yang sudah ada TIDAK
     * ditimpa (menghindari tabrakan dengan chapter lama & symlink swap).
     *
     * @return array{ok: bool, error?: string}
     */
    private function moveStagingToDest(string $staging, string $destDir): array
    {
        if (!is_dir($destDir)) {
            if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                return ['ok' => false, 'error' => 'Gagal membuat direktori tujuan.'];
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $rel = substr($item->getPathname(), strlen($staging) + 1);
            $dest = $destDir . '/' . $rel;

            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    if (!@mkdir($dest, 0755, true) && !is_dir($dest)) {
                        return ['ok' => false, 'error' => "Gagal membuat direktori '{$rel}'."];
                    }
                }
                continue;
            }

            if ($item->isLink()) {
                // Symlink tidak pernah dipindahkan — hindari symlink swap.
                continue;
            }

            if (file_exists($dest)) {
                continue; // jangan timpa file existing
            }

            if (!@rename($item->getPathname(), $dest)) {
                if (!@copy($item->getPathname(), $dest)) {
                    return ['ok' => false, 'error' => "Gagal memindahkan '{$rel}' ke tujuan."];
                }
                @unlink($item->getPathname());
            }
        }

        return ['ok' => true];
    }

    private function shortName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        return strlen($name) > 80 ? '…' . substr($name, -77) : $name;
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}