<?php
/**
 * GarbageCollector — Otomatis membersihkan file/folder sampah dari direktori temp.
 *
 * File yang sudah tidak disentuh >5 menit (stale) akan dihapus.
 * Berjalan di setiap request halaman upload/transcode agar real-time.
 *
 * Target direktori:
 * - /dev/shm/meel/temp/     (Transcoder RAM disk — upload/download)
 * - /dev/shm/meel/upload/   (Uploader RAM disk)
 * - /dev/shm/meel/transcode/ (Transcoder RAM disk — transcode audio)
 * - {project}/temp/          (fallback disk)
 */

class GarbageCollector
{
    // File/folder lebih dari STALE_SECONDS detik sejak mtime terakhir akan dihapus
    private const STALE_SECONDS = 300; // 5 menit

    // Berapa jam tanpa aktivitas sebelum guest dianggap stale
    private const GUEST_STALE_HOURS = 2;

    // Minimal interval antar auto-cleanup guest (dalam detik)
    private const GUEST_CLEANUP_INTERVAL = 3600; // 1 jam

    // Room catur multiplayer tanpa lawan (black_joined = 0) lebih dari N jam
    // sejak dibuat dianggap lobby basi → dihapus
    private const ROOM_LOBBY_STALE_HOURS = 24;

    // Game catur yang sudah dimulai (black_joined = 1) tanpa aktivitas
    // (langkah terakhir) lebih dari N jam dianggap ditinggalkan → dihapus
    private const ROOM_GAME_STALE_HOURS = 168; // 7 hari

    // Minimal interval antar auto-cleanup chess room (dalam detik)
    private const CHESS_CLEANUP_INTERVAL = 3600; // 1 jam

    // Static flag agar GC hanya 1x per request (dipanggil dari banyak titik)
    private static bool $hasRun = false;

    /**
     * Bersihkan guest stale dari database secara otomatis.
     *
     * Alur:
     * 1. Mark guest dengan last_activity > N jam sebagai is_active = 0
     * 2. Hapus semua guest dengan is_active = 0
     * 3. Reset AUTO_INCREMENT users ke MAX(id) + 1
     *
     * Throttle: hanya berjalan SEKALI per interval (default 1 jam),
     * dilacak via file temp/gc_guest_last_run.txt
     *
     * @param \mysqli $conn Koneksi database aktif
     * @return int Jumlah guest yang dibersihkan
     */
    public static function cleanGuests(\mysqli $conn): int
    {
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_guest_last_run.txt';

        // Throttle: cek apakah sudah jalan dalam < interval
        if (file_exists($throttleFile)) {
            $lastRun = (int) @file_get_contents($throttleFile);
            if ($lastRun > 0 && (time() - $lastRun) < self::GUEST_CLEANUP_INTERVAL) {
                return 0; // Masih dalam cooldown
            }
        }

        $totalCleaned = 0;

        // Step 1: Mark guest stale sebagai is_active = 0
        $stmt = $conn->prepare(
            "UPDATE users SET is_active = 0 WHERE role = 'guest' AND is_active = 1 AND last_activity < DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        if ($stmt) {
            $hours = self::GUEST_STALE_HOURS;
            $stmt->bind_param("i", $hours);
            $stmt->execute();
            $marked = $stmt->affected_rows;
            $stmt->close();

            if ($marked > 0) {
                $totalCleaned += $marked;
            }
        }

        // Step 2: Hapus semua guest yang sudah is_active = 0
        $stmt = $conn->prepare("DELETE FROM users WHERE role = 'guest' AND is_active = 0");
        if ($stmt) {
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();

            if ($deleted > 0) {
                $totalCleaned += $deleted;
            }
        }

        // Step 3: Reset AUTO_INCREMENT ke MAX(id) + 1
        if ($totalCleaned > 0) {
            $result = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS new_ai FROM users");
            if ($result) {
                $row = $result->fetch_assoc();
                $newAi = (int) $row['new_ai'];
                $conn->query("ALTER TABLE users AUTO_INCREMENT = " . (int)$newAi);
            }
        }

        // Simpan timestamp throttle
        @file_put_contents($throttleFile, time());

        return $totalCleaned;
    }

    /**
     * Bersihkan room catur multiplayer yang terbengkalai dari database.
     *
     * Dua kategori room yang dihapus BESERTA riwayat langkahnya (tabel moves):
     *   1. Lobby basi — lawan tidak pernah join (black_joined = 0) dan room
     *      sudah lebih dari ROOM_LOBBY_STALE_HOURS (24 jam) sejak dibuat.
     *   2. Game ditinggalkan di tengah — sudah dimulai (black_joined = 1),
     *      TANPA event terminal (resign / draw_accept / disconnect / game_over)
     *      dan tanpa aktivitas (langkah terakhir / created_at) selama
     *      ROOM_GAME_STALE_HOURS (7 hari). Game yang SUDAH SELESAI
     *      (checkmate/stalemate via event game_over, resign, seri, disconnect)
     *      TIDAK pernah dihapus — riwayatnya dipertahankan.
     *
     * Throttle: hanya berjalan SEKALI per interval (default 1 jam),
     * dilacak via file temp/gc_chess_last_run.txt
     *
     * @param \mysqli $conn Koneksi database aktif
     * @return int Jumlah room yang dibersihkan
     */
    public static function cleanChessRooms(\mysqli $conn): int
    {
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_chess_last_run.txt';

        // Throttle: cek apakah sudah jalan dalam < interval
        if (file_exists($throttleFile)) {
            $lastRun = (int) @file_get_contents($throttleFile);
            if ($lastRun > 0 && (time() - $lastRun) < self::CHESS_CLEANUP_INTERVAL) {
                return 0; // Masih dalam cooldown
            }
        }

        $totalCleaned = 0;

        // ── Step 1: Lobby basi (lawan tak pernah join) ────────────────────
        $lobbyHours = self::ROOM_LOBBY_STALE_HOURS;
        $stmt = $conn->prepare(
            "DELETE FROM moves WHERE room_code IN (
                SELECT room_code FROM rooms
                WHERE black_joined = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
            )"
        );
        if ($stmt) {
            $stmt->bind_param("i", $lobbyHours);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare(
            "DELETE FROM rooms
             WHERE black_joined = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        if ($stmt) {
            $stmt->bind_param("i", $lobbyHours);
            $stmt->execute();
            $totalCleaned += $stmt->affected_rows;
            $stmt->close();
        }

        // ── Step 2: Game ditinggalkan DI TENGAH (belum selesai) ───────────
        // Hanya room yang TIDAK memiliki event terminal (resign / draw_accept /
        // disconnect / game_over) yang dibersihkan — game selesai dipertahankan.
        // Aktivitas = langkah terakhir (MAX moves.created_at); jika belum ada
        // langkah sama sekali, fallback ke created_at room.
        // Subquery dibungkus derived table agar MySQL tidak menolak DELETE
        // yang membaca tabel yang sama dengan yang dimodifikasi.
        $gameHours = self::ROOM_GAME_STALE_HOURS;
        $staleRooms = "SELECT room_code FROM (
                SELECT r.room_code
                FROM rooms r
                LEFT JOIN (
                    SELECT room_code, MAX(created_at) AS last_move_at
                    FROM moves GROUP BY room_code
                ) m ON m.room_code = r.room_code
                WHERE r.black_joined = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM moves t
                      WHERE t.room_code = r.room_code
                        AND JSON_UNQUOTE(JSON_EXTRACT(t.move_data, '$.type'))
                            IN ('resign','draw_accept','disconnect','game_over')
                  )
                  AND COALESCE(m.last_move_at, r.created_at) < DATE_SUB(NOW(), INTERVAL ? HOUR)
            ) AS stale_rooms";
        $stmt = $conn->prepare("DELETE FROM moves WHERE room_code IN ($staleRooms)");
        if ($stmt) {
            $stmt->bind_param("i", $gameHours);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM rooms WHERE room_code IN ($staleRooms)");
        if ($stmt) {
            $stmt->bind_param("i", $gameHours);
            $stmt->execute();
            $totalCleaned += $stmt->affected_rows;
            $stmt->close();
        }

        // Simpan timestamp throttle
        @file_put_contents($throttleFile, time());

        return $totalCleaned;
    }

    /**
     * Jalankan garbage collection pada semua direktori temp.
     * Panggil method ini di awal setiap halaman yang memproses upload/transcode.
     * Hanya berjalan 1 kali per request (static flag).
     */
    public static function run(): void
    {
        if (self::$hasRun) return;
        self::$hasRun = true;

        $directories = self::getTargetDirectories();
        if (empty($directories)) return;

        // Batasi eksekusi maksimal 3 detik agar tidak menggangu response user
        $timeout = microtime(true) + 3;

        foreach ($directories as $dir) {
            if (microtime(true) >= $timeout) break;
            self::cleanDirectory($dir);
        }

        // Cleanup expired rate limit files
        if (class_exists('RateLimiter')) {
            RateLimiter::cleanup();
        }
    }

    /**
     * Kumpulkan semua direktori temp yang ada saat runtime.
     */
    private static function getTargetDirectories(): array
    {
        $dirs = [];

        // 1. Project temp/ fallback
        $project_temp = dirname(__DIR__, 2) . '/temp';
        if (is_dir($project_temp)) {
            $dirs[] = $project_temp;
        }

        // 2. RAM disk Transcoder — upload/download
        if (is_dir('/dev/shm/meel/temp')) {
            $dirs[] = '/dev/shm/meel/temp';
        }

        // 3. RAM disk Uploader
        if (is_dir('/dev/shm/meel/upload')) {
            $dirs[] = '/dev/shm/meel/upload';
        }

        // 4. RAM disk Transcode (khusus ekstrak audio dari video)
        if (is_dir('/dev/shm/meel/transcode')) {
            $dirs[] = '/dev/shm/meel/transcode';
        }

        return $dirs;
    }

    /**
     * Hapus semua file/folder stale di dalam direktori (non-rekursif level-1).
     */
    private static function cleanDirectory(string $dir): void
    {
        $cutoff = time() - self::STALE_SECONDS;

        $items = glob(rtrim($dir, '/') . '/*');
        if (empty($items)) return;

        foreach ($items as $item) {
            $basename = basename($item);

            // ── Skip yt-dlp persistent cache ────────────────────────────────
            if ($basename === 'ytdlp-cache') continue;

            // ── Skip file yang masih baru (mtime dalam 5 menit) ──────────────
            $mtime = @filemtime($item);
            if ($mtime === false || $mtime > $cutoff) continue;

            // ── Hapus file/folder stale ──────────────────────────────────────
            if (is_dir($item)) {
                self::removeDirectory($item);
            } else {
                @unlink($item);
            }
        }
    }

    /**
     * Hapus direktori beserta seluruh isinya secara rekursif.
     */
    private static function removeDirectory(string $dir): void
    {
        $items = glob(rtrim($dir, '/') . '/*');
        if ($items) {
            foreach ($items as $item) {
                is_dir($item) ? self::removeDirectory($item) : @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
