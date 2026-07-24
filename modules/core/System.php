<?php
// File: auth/System.php

require_once __DIR__ . '/RateLimiter.php';

class System
{
    private $conn;

    public function __construct($db_connection)
    {
        $this->conn = $db_connection;
    }

    // ─── MONITORING ──────────────────────────────────────────────────────────

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

    public function getTodayUploadStats(): array
    {
        $stats = ['video' => 0, 'music' => 0, 'drive' => 0, 'total' => 0];

        $q_vid = $this->conn->query("SELECT COUNT(*) FROM video WHERE DATE(upload_date) = CURDATE()");
        $stats['video'] = $q_vid ? (int)$q_vid->fetch_row()[0] : 0;

        $q_mus = $this->conn->query("SELECT COUNT(*) FROM music WHERE DATE(upload_date) = CURDATE()");
        $stats['music'] = $q_mus ? (int)$q_mus->fetch_row()[0] : 0;

        $q_drv = $this->conn->query("SELECT COUNT(*) FROM drive_files WHERE DATE(upload_date) = CURDATE()");
        $stats['drive'] = $q_drv ? (int)$q_drv->fetch_row()[0] : 0;

        $stats['total'] = $stats['video'] + $stats['music'] + $stats['drive'];

        return $stats;
    }

    private static function getFolderSizeSys(string $path): float
    {
        $full_path = realpath(__DIR__ . '/../' . $path);
        if ($full_path && file_exists($full_path)) {
            $output = shell_exec("du -sb " . escapeshellarg($full_path) . " 2>&1");
            if ($output && !str_contains($output, 'Permission denied')) {
                return (float) explode("\t", $output)[0];
            }
        }
        return 0;
    }

    public function getStorageUsage(): array
    {

        $ssd_free  = @disk_free_space("/") / (1024 ** 3);
        $ssd_total = @disk_total_space("/") / (1024 ** 3);
        $ssd_used  = $ssd_total - $ssd_free;
        $ssd_perc  = ($ssd_total > 0) ? ($ssd_used / $ssd_total) * 100 : 0;

        $hdd_path  = __DIR__ . '/../video/upload';
        $hdd_free  = @disk_free_space($hdd_path) / (1024 ** 3);
        $hdd_total = @disk_total_space($hdd_path) / (1024 ** 3);

        $sz_vid   = self::getFolderSizeSys('video/upload') / (1024 ** 3);
        $sz_mus   = self::getFolderSizeSys('music/upload') / (1024 ** 3);
        $sz_book  = self::getFolderSizeSys('books/upload') / (1024 ** 3);
        $sz_d_pub = self::getFolderSizeSys('data_drive/public') / (1024 ** 3);
        $sz_d_prv = self::getFolderSizeSys('data_drive/private_admins') / (1024 ** 3);

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

    // ─── LIMITING ────────────────────────────────────────────────────────────

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

        // Gunakan method pusat dari RateLimiter untuk role-based adjustment
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

    // ─── MANAGEMENT ──────────────────────────────────────────────────────────

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
        // Tentukan tabel berdasarkan jenis task
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
