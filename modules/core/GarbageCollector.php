<?php
class GarbageCollector
{

    private const STALE_SECONDS = 300; // 5 menit

    // Berapa jam tanpa aktivitas sebelum guest dianggap stale
    private const GUEST_STALE_HOURS = 2;

    // Minimal interval antar auto-cleanup guest (dalam detik)
    private const GUEST_CLEANUP_INTERVAL = 3600; // 1 jam

    // sejak dibuat dianggap lobby basi → dihapus
    private const ROOM_LOBBY_STALE_HOURS = 24;

    private const ROOM_GAME_STALE_HOURS = 168; // 7 hari

    // Minimal interval antar auto-cleanup chess room (dalam detik)
    private const CHESS_CLEANUP_INTERVAL = 3600; // 1 jam

    private static bool $hasRun = false;

    /* @param \mysqli $conn Koneksi database aktif; @return int Jumlah guest yang dibersihkan */
    public static function cleanGuests(\mysqli $conn): int
    {
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_guest_last_run.txt';

        // Throttle: cek apakah sudah jalan dalam < interval
        if (is_readable($throttleFile)) {
            $lastRun = (int) file_get_contents($throttleFile);
            if ($lastRun > 0 && (time() - $lastRun) < self::GUEST_CLEANUP_INTERVAL) {
                return 0; // Masih dalam cooldown
            }
        }

        $totalCleaned = 0;

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

        $deleted = purge_guest_users($conn) ?? 0;
        if ($deleted > 0) {
            $totalCleaned += $deleted;
        }

        if ($totalCleaned > 0) {
            $result = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS new_ai FROM users");
            if ($result) {
                $row = $result->fetch_assoc();
                $newAi = (int) $row['new_ai'];
                $conn->query("ALTER TABLE users AUTO_INCREMENT = " . (int)$newAi);
            }
        }

        self::writeThrottleFile($throttleFile);

        return $totalCleaned;
    }

    /* @param \mysqli $conn Koneksi database aktif; @return int Jumlah room yang dibersihkan */
    public static function cleanChessRooms(\mysqli $conn): int
    {
        $throttleFile = dirname(__DIR__, 2) . '/temp/gc_chess_last_run.txt';

        // Throttle: cek apakah sudah jalan dalam < interval
        if (is_readable($throttleFile)) {
            $lastRun = (int) file_get_contents($throttleFile);
            if ($lastRun > 0 && (time() - $lastRun) < self::CHESS_CLEANUP_INTERVAL) {
                return 0; // Masih dalam cooldown
            }
        }

        $totalCleaned = 0;

        // ─── Step 1: Lobby basi (lawan tak pernah join) ───
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

        // ─── Step 2: Game ditinggalkan DI TENGAH (belum selesai) ───
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

        self::writeThrottleFile($throttleFile);

        return $totalCleaned;
    }

    /* @param string $throttleFile Path file throttle */
    private static function writeThrottleFile(string $throttleFile): void
    {
        $dir = dirname($throttleFile);
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("[MEeL] GarbageCollector: throttle file tidak bisa ditulis: {$throttleFile}");
            return;
        }

        // throttle tetap berfungsi di kedua konteks tanpa warning PHP.
        if (is_file($throttleFile) && !is_writable($throttleFile)) {
            if (!@unlink($throttleFile)) {
                error_log("[MEeL] GarbageCollector: throttle file tidak writable & gagal dihapus: {$throttleFile}");
                return;
            }
        }

        if (@file_put_contents($throttleFile, time()) === false) {
            error_log("[MEeL] GarbageCollector: gagal menulis throttle file: {$throttleFile}");
        }
    }

    public static function run(): void
    {
        if (self::$hasRun) return;
        self::$hasRun = true;

        $directories = self::getTargetDirectories();
        if (empty($directories)) return;

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

    /* Kumpulkan semua direktori temp yang ada saat runtime. */
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

    /* Hapus semua file/folder stale di dalam direktori (non-rekursif level-1). */
    private static function cleanDirectory(string $dir): void
    {
        $cutoff = time() - self::STALE_SECONDS;

        $items = glob(rtrim($dir, '/') . '/*');
        if (empty($items)) return;

        foreach ($items as $item) {
            $basename = basename($item);

            // ─── Skip yt-dlp persistent cache ───
            if ($basename === 'ytdlp-cache') continue;

            // ─── Skip file yang masih baru (mtime dalam 5 menit) ───
            if (!file_exists($item)) continue; // lenyap antara glob & stat
            $mtime = filemtime($item);
            if ($mtime === false || $mtime > $cutoff) continue;

            if (is_dir($item)) {
                self::removeDirectory($item);
            } else {
                self::removeFile($item);
            }
        }
    }

    /* Hapus direktori beserta seluruh isinya secara rekursif. */
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // lain) tercatat sekali, bukan menjadi warning di setiap file.
        $parent = dirname($dir);
        if (!is_dir($parent) || !is_writable($parent)) {
            error_log("[MEeL] GarbageCollector: direktori tidak writable, dilewati: {$dir}");
            return;
        }

        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $item) {
            if (is_dir($item)) {
                self::removeDirectory($item);
            } else {
                self::removeFile($item);
            }
        }

        $remaining = glob(rtrim($dir, '/') . '/*') ?: [];
        if (empty($remaining) && !rmdir($dir)) {
            error_log("[MEeL] GarbageCollector: Gagal menghapus direktori: {$dir}");
        }
    }

    /* @param string $path Path file */
    private static function removeFile(string $path): void
    {
        if (!is_file($path) && !is_link($path)) {
            return;
        }

        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) {
            error_log("[MEeL] GarbageCollector: direktori tidak writable, file dilewati: {$path}");
            return;
        }

        if (!unlink($path)) {
            error_log("[MEeL] GarbageCollector: Gagal menghapus file: {$path}");
        }
    }
}
