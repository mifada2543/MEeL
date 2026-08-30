<?php

require_once __DIR__ . '/../auth/RateLimiter.php';

class System
{
    private mysqli $conn;

    public function __construct(mysqli $db_connection)
    {
        $this->conn = $db_connection;
    }

    public function getActiveQueues(): array
    {
        $active_queues = [];

        // Advanced Upload (yt-dlp)
        $res1 = $this->conn->query("SELECT q.id, q.url, q.media_type, q.status, q.created_at, u.username, 'download' as task_type
                                    FROM upload_queue q
                                    JOIN users u ON q.user_id = u.id
                                    WHERE q.status = 'processing'
                                    ORDER BY q.created_at ASC");
        if ($res1) {
            while ($row = $res1->fetch_assoc()) {
                $active_queues[] = $row;
            }
        }

        // Transcoder (ffmpeg)
        $res2 = $this->conn->query("SELECT q.id, q.status, q.created_at, u.username, 'transcode' as task_type, q.user_id
                                    FROM transcode_queue q
                                    JOIN users u ON q.user_id = u.id
                                    WHERE q.status = 'processing'
                                    ORDER BY q.created_at ASC");
        if ($res2) {
            while ($row = $res2->fetch_assoc()) {
                $row['url'] = 'Internal Video Transcode';
                $row['media_type'] = 'video->ogg/mp3';
                $active_queues[] = $row;
            }
        }

        usort($active_queues, function ($a, $b) {
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });

        return $active_queues;
    }

    private static function getFolderSizeSys(string $path): float
    {
        $full_path = realpath($path);
        if (!$full_path || !file_exists($full_path)) {
            return 0.0;
        }

        require_once __DIR__ . '/helpers.php';
        return dir_size($full_path);
    }

    public function getStorageUsage(): array
    {
        $project_root = dirname(__DIR__, 2);
        require_once __DIR__ . '/helpers.php';

        $ssd_free  = @disk_free_space("/") / (1024 ** 3);
        $ssd_total = @disk_total_space("/") / (1024 ** 3);
        $ssd_used  = $ssd_total - $ssd_free;
        $ssd_perc  = ($ssd_total > 0) ? ($ssd_used / $ssd_total) * 100 : 0;

        $video_base = meel_media_base_path('video');
        $music_base = meel_media_base_path('music');
        $books_base = meel_media_base_path('books');
        $drive_base = meel_drive_base_path();

        $hdd_path  = $video_base;
        $hdd_free  = @disk_free_space($hdd_path) / (1024 ** 3);
        $hdd_total = @disk_total_space($hdd_path) / (1024 ** 3);

        $sz_vid   = self::getFolderSizeSys($video_base) / (1024 ** 3);
        $sz_mus   = self::getFolderSizeSys($music_base) / (1024 ** 3);
        $sz_book  = self::getFolderSizeSys($books_base) / (1024 ** 3);
        $sz_d_pub = self::getFolderSizeSys($drive_base . '/public') / (1024 ** 3);
        $sz_d_prv = self::getFolderSizeSys($drive_base . '/private_admins') / (1024 ** 3);

        $sz_drive_total = $sz_d_pub + $sz_d_prv;
        $p_vid   = ($hdd_total > 0) ? ($sz_vid / $hdd_total) * 100 : 0;
        $p_mus   = ($hdd_total > 0) ? ($sz_mus / $hdd_total) * 100 : 0;
        $p_book  = ($hdd_total > 0) ? ($sz_book / $hdd_total) * 100 : 0;
        $p_drive = ($hdd_total > 0) ? ($sz_drive_total / $hdd_total) * 100 : 0;

        return [
            'ssd' => [
                'free'  => $ssd_free,
                'total' => $ssd_total,
                'used'  => $ssd_used,
                'perc'  => $ssd_perc
            ],
            'hdd' => [
                'free'  => $hdd_free,
                'total' => $hdd_total
            ],
            'sizes' => [
                'video' => $sz_vid,
                'music' => $sz_mus,
                'books' => $sz_book,
                'drive_pub' => $sz_d_pub,
                'drive_prv' => $sz_d_prv,
                'drive_total' => $sz_drive_total
            ],
            'percentages' => [
                'video' => $p_vid,
                'music' => $p_mus,
                'books' => $p_book,
                'drive' => $p_drive
            ]
        ];
    }

    // LIMITING

    public function isServerBusy(): bool
    {
        $active = count($this->getActiveQueues());
        return $active >= 2;
    }

    public function checkRateLimit(int $user_id, string $type, string $user_role): array
    {
        if ($user_role === 'admin') return ['allowed' => true];

        $allowed_tables = ['music', 'video', 'drive_files'];
        if (!in_array($type, $allowed_tables)) return ['allowed' => false, 'minutes' => 99];

        $max_upload = 2;
        if ($type === 'drive_files') {
            $max_upload = 10;
        }

        $max_upload = RateLimiter::getRoleLimit($max_upload, $user_role);

        $sql = "SELECT upload_date FROM $type
                WHERE user_id = ? AND upload_date > NOW() - INTERVAL 1 HOUR
                ORDER BY upload_date ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows >= $max_upload) {
            $first = $res->fetch_assoc();
            $next  = strtotime($first['upload_date']) + 3600;
            $rem   = ceil(($next - time()) / 60);
            return ['allowed' => false, 'minutes' => $rem];
        }
        return ['allowed' => true];
    }

    public function getServerStats(): array
    {
        $info = $this->getCachedServerInfo();

        $load = sys_getloadavg();
        $cpu_load_1m  = $load[0] ?? 0;
        $cpu_load_5m  = $load[1] ?? 0;
        $cpu_load_15m = $load[2] ?? 0;

        $cpu_cores = $info['cores'];
        $cpu_perc  = ($cpu_cores > 0) ? round(($cpu_load_1m / $cpu_cores) * 100, 1) : 0;
        $cpu_perc  = min($cpu_perc, 100);

        $meminfo    = self::readProcMeminfo();
        $mem_total  = $meminfo['MemTotal'] ?? 0;
        $mem_avail  = $meminfo['MemAvailable'] ?? 0;
        $mem_used   = $mem_total - $mem_avail;
        $mem_perc   = ($mem_total > 0) ? round(($mem_used / $mem_total) * 100, 1) : 0;

        $swap_total = $meminfo['SwapTotal'] ?? 0;
        $swap_free  = $meminfo['SwapFree'] ?? 0;
        $swap_used  = $swap_total - $swap_free;
        $swap_perc  = ($swap_total > 0) ? round(($swap_used / $swap_total) * 100, 1) : 0;

        $uptime_raw = @file_get_contents('/proc/uptime');
        $uptime_sec = (float) explode(' ', (string) $uptime_raw)[0];
        $days  = floor($uptime_sec / 86400);
        $hours = floor(($uptime_sec % 86400) / 3600);
        $mins  = floor(($uptime_sec % 3600) / 60);

        $net_rx = 0;
        $net_tx = 0;
        $net_lines = @file('/proc/net/dev');
        if ($net_lines) {
            foreach ($net_lines as $line) {
                if (preg_match('/^\s*([a-zA-Z0-9_.-]+):\s+(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                    if ($m[1] !== 'lo') {
                        $net_rx += (int)$m[2];
                        $net_tx += (int)$m[3];
                    }
                }
            }
        }

        $proc_list  = @glob('/proc/[0-9]*');
        $proc_count = is_array($proc_list) ? count($proc_list) : 0;

        return [
            'cpu' => [
                'cores'       => $cpu_cores,
                'load_1m'     => round($cpu_load_1m, 2),
                'load_5m'     => round($cpu_load_5m, 2),
                'load_15m'    => round($cpu_load_15m, 2),
                'usage_perc'  => $cpu_perc,
            ],
            'ram' => [
                'total'  => $mem_total,
                'used'   => $mem_used,
                'avail'  => $mem_avail,
                'usage_perc' => $mem_perc,
            ],
            'swap' => [
                'total'  => $swap_total,
                'used'   => $swap_used,
                'usage_perc' => $swap_perc,
            ],
            'uptime' => [
                'seconds' => (int) $uptime_sec,
                'days'    => $days,
                'hours'   => $hours,
                'mins'    => $mins,
                'text'    => "{$days}d {$hours}h {$mins}m",
            ],
            'network' => [
                'rx' => $net_rx,
                'tx' => $net_tx,
            ],
            'info' => [
                'hostname'    => $info['hostname'],
                'os'          => $info['os'],
                'kernel'      => $info['kernel'],
                'php_version' => $info['php_version'],
                'processes'   => $proc_count,
            ],
        ];
    }

    /**
     * Baca /proc/meminfo sekali dan kembalikan [KEY => bytes] (nilai kB → byte).
     * Native file read — menggantikan 4x exec('grep ... | awk ...') agar
     * polling realtime tidak menjalankan perintah shell berulang.
     *
     * @return array<string, int>
     */
    private static function readProcMeminfo(): array
    {
        $lines = @file('/proc/meminfo');
        if (!$lines) {
            return [];
        }
        $info = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $info[$m[1]] = (int)$m[2] * 1024;
            }
        }
        return $info;
    }

    /**
     * Info identitas server yang jarang berubah — di-cache ke temp/cache
     * (konvensi sama seperti MediaLibrary::getCounts()). TTL 300 detik.
     * Bila cache tidak ada/kadaluarsa/rusak, hitung ulang; bila gagal
     * ditulis, tetap kembalikan nilai fresh (cache hanya optimasi).
     *
     * @return array{hostname: string, os: string, kernel: string, php_version: string, cores: int}
     */
    private function getCachedServerInfo(): array
    {
        $cache_file = defined('MEEL_SERVER_STATS_CACHE')
            ? MEEL_SERVER_STATS_CACHE
            : __DIR__ . '/../../temp/cache/server_stats_info.json';
        $cache_ttl  = 300;

        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
            $cached = json_decode((string) file_get_contents($cache_file), true);
            if (is_array($cached)
                && isset($cached['hostname'], $cached['os'], $cached['kernel'], $cached['php_version'], $cached['cores'])) {
                return $cached;
            }
        }

        $info = [
            'hostname'    => @exec('hostname') ?: gethostname(),
            'os'          => @exec('cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d\" -f2') ?: PHP_OS,
            'kernel'      => @exec('uname -r') ?: PHP_OS,
            'php_version' => phpversion(),
            'cores'       => (int) (@exec('nproc') ?: 1),
        ];

        $cache_dir = dirname($cache_file);
        if (!is_dir($cache_dir)) {
            @mkdir($cache_dir, 0755, true);
        }
        @file_put_contents($cache_file, json_encode($info, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $info;
    }

    public function cleanStuckQueues(): int
    {
        $this->conn->begin_transaction();
        try {
            $this->conn->query("DELETE FROM transcode_queue WHERE status = 'processing'");
            $del_count = $this->conn->affected_rows;

            $this->conn->query("UPDATE upload_queue SET status = 'failed' WHERE status = 'processing'");
            $upd_count = $this->conn->affected_rows;

            $this->conn->commit();
            return $del_count + $upd_count;
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return 0;
        }
    }
    public function forceStopQueue(int $id, string $task_type): bool
    {
        // 1) Kill proses via PID file — standalone, tanpa load Transcoder.php
        //    (avoid heavy require chain that can cause output-before-headers)
        $pid_dir = '/tmp/meel_pids';
        $pid_file = $pid_dir . "/{$task_type}_{$id}.pid";
        if (is_file($pid_file)) {
            $pid = (int)@file_get_contents($pid_file);
            @unlink($pid_file);
            if ($pid > 0) {
                if (function_exists('posix_kill')) {
                    @posix_kill($pid, SIGTERM);
                } else {
                    @shell_exec('kill -TERM ' . $pid . ' 2>/dev/null');
                }
                usleep(300000);
                if (function_exists('posix_kill')) {
                    @posix_kill($pid, SIGKILL);
                } else {
                    @shell_exec('kill -KILL ' . $pid . ' 2>/dev/null');
                }
            }
        }

        // 2) Hapus record dari database
        if ($task_type === 'download') {
            $stmt = $this->conn->prepare("DELETE FROM upload_queue WHERE id = ?");
        } elseif ($task_type === 'transcode') {
            $stmt = $this->conn->prepare("DELETE FROM transcode_queue WHERE id = ?");
        } else {
            return false;
        }

        if ($stmt) {
            $stmt->bind_param("i", $id);
            return $stmt->execute();
        }
        return false;
    }
}
