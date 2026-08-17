<?php

require_once __DIR__ . '/../auth/RateLimiter.php';

class System
{
    private mysqli $conn;

    public function __construct(mysqli $db_connection)
    {
        $this->conn = $db_connection;
    }

    // ─── MONITORING ───

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

        // Sort by created_at
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

        // Semua base path storage di-resolve terpusat (MEEL_HDD_*_UPLOAD /
        // MEEL_HDD_DRIVE, fallback folder lokal) supaya konsisten dengan
        // meel_media_base_path() / meel_drive_base_path() — bukan folder
        // webroot yang kosong.
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

    // ─── LIMITING ───

    public function isServerBusy(): bool
    {
        // Jika total proses transcode + download >= 2, anggap sibuk
        $active = count($this->getActiveQueues());
        return $active >= 2;
    }

    public function checkRateLimit(int $user_id, string $type, string $user_role): array
    {
        // Admin tanpa batas
        if ($user_role === 'admin') return ['allowed' => true];

        // Validasi tabel
        $allowed_tables = ['music', 'video', 'drive_files'];
        if (!in_array($type, $allowed_tables)) return ['allowed' => false, 'minutes' => 99];

        $max_upload = 2; // Default 2 upload per jam
        if ($type === 'drive_files') {
            $max_upload = 10; // Drive biasanya lebih banyak file kecil
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

    // ─── SERVER STATS ───

    public function getServerStats(): array
    {
        // CPU Load Average (1, 5, 15 menit)
        $load = sys_getloadavg();
        $cpu_load_1m  = $load[0] ?? 0;
        $cpu_load_5m  = $load[1] ?? 0;
        $cpu_load_15m = $load[2] ?? 0;

        // CPU Cores
        $cpu_cores = (int) @exec('nproc') ?: 1;
        $cpu_perc  = ($cpu_cores > 0) ? round(($cpu_load_1m / $cpu_cores) * 100, 1) : 0;
        $cpu_perc  = min($cpu_perc, 100);

        // RAM Usage
        $mem_total = (float) @exec('grep MemTotal /proc/meminfo | awk \'{print $2}\'') * 1024;
        $mem_avail = (float) @exec('grep MemAvailable /proc/meminfo | awk \'{print $2}\'') * 1024;
        $mem_used  = $mem_total - $mem_avail;
        $mem_perc  = ($mem_total > 0) ? round(($mem_used / $mem_total) * 100, 1) : 0;

        // Swap Usage
        $swap_total = (float) @exec('grep SwapTotal /proc/meminfo | awk \'{print $2}\'') * 1024;
        $swap_free  = (float) @exec('grep SwapFree /proc/meminfo | awk \'{print $2}\'') * 1024;
        $swap_used  = $swap_total - $swap_free;
        $swap_perc  = ($swap_total > 0) ? round(($swap_used / $swap_total) * 100, 1) : 0;

        // Uptime
        $uptime_raw = @exec('cat /proc/uptime');
        $uptime_sec = (float) explode(' ', $uptime_raw)[0];
        $days  = floor($uptime_sec / 86400);
        $hours = floor(($uptime_sec % 86400) / 3600);
        $mins  = floor(($uptime_sec % 3600) / 60);

        // Network (total bytes in/out)
        $net_rx = 0;
        $net_tx = 0;
        $net_lines = @file('/proc/net/dev');
        if ($net_lines) {
            foreach ($net_lines as $line) {
                if (preg_match('/^\s*(eth|ens|enp|wlan|lo):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                    if ($m[1] !== 'lo') {
                        $net_rx += (int)$m[2];
                        $net_tx += (int)$m[3];
                    }
                }
            }
        }

        // Top Processes
        $top_procs = [];
        $ps_output = @exec('ps aux --sort=-%cpu | head -6 | tail -5');
        // Fallback: baca langsung dari file
        $ps_lines = @file('/proc/stat');

        // Server Info
        $hostname  = @exec('hostname');
        $os_info   = @exec('cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d\" -f2') ?: PHP_OS;
        $kernel    = @exec('uname -r');
        $php_ver   = phpversion();

        // Process count
        $proc_count = (int) @exec('ls /proc | grep -c \'^[0-9]\'');

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
                'hostname'    => $hostname,
                'os'          => $os_info,
                'kernel'      => $kernel,
                'php_version' => $php_ver,
                'processes'   => $proc_count,
            ],
        ];
    }

    // ─── MANAGEMENT ───

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
        if ($task_type === 'download') {
            $stmt = $this->conn->prepare("DELETE FROM upload_queue WHERE id = ?");
        } elseif ($task_type === 'transcode') {
            $stmt = $this->conn->prepare("DELETE FROM transcode_queue WHERE id = ?");
        } else {
            return false;
        }

        // Eksekusi penghapusan spesifik berdasarkan ID
        if ($stmt) {
            $stmt->bind_param("i", $id);
            return $stmt->execute();
        }
        return false;
    }
}
