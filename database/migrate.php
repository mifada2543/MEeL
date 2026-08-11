<?php
// ─── Keamanan: hanya dari CLI ───
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied. Jalankan dari terminal: php database/migrate.php');
}

// ─── Bootstrap ───
require_once __DIR__ . '/../auth/config.php';

// Validasi koneksi database
if (!isset($conn) || !$conn instanceof \mysqli || $conn->connect_error) {
    echo "[MEeL] ❌ Gagal terhubung ke database. Periksa auth/config.php.\n";
    exit(1);
}

// ─── Migration Registry ───
$migrations = [
    1 => [
        'description' => 'Tambah FULLTEXT index untuk pencarian',
        'sql' => [
            function ($conn) {
                $conn->query("ALTER TABLE video ADD FULLTEXT INDEX ft_video_search (title, search_metadata)");
            },
            function ($conn) {
                $conn->query("ALTER TABLE music ADD FULLTEXT INDEX ft_music_search (title, artist, search_metadata)");
            },
            function ($conn) {
                $conn->query("ALTER TABLE books ADD FULLTEXT INDEX ft_books_search (title, author)");
            },
        ],
    ],
    2 => [
        'description' => 'Tambah index pada kolom upload_date',
        'sql' => [
            function ($conn) {
                $conn->query("ALTER TABLE video ADD INDEX idx_video_upload_date (upload_date)");
            },
            function ($conn) {
                $conn->query("ALTER TABLE music ADD INDEX idx_music_upload_date (upload_date)");
            },
            function ($conn) {
                $conn->query("ALTER TABLE books ADD INDEX idx_books_upload_date (upload_date)");
            },
            function ($conn) {
                $conn->query("ALTER TABLE drive_files ADD INDEX idx_drive_upload_date (upload_date)");
            },
        ],
    ],
    3 => [
        'description' => 'Catatan: db_version dibuat otomatis oleh runner',
        'sql' => [], // runner sudah buat db_version otomatis
    ],
    4 => [
        'description' => 'Tambah FK constraint untuk tabel tanpa referensi',
        'sql' => [
            function ($conn) {

                $conn->query("DELETE FROM upload_queue WHERE user_id NOT IN (SELECT id FROM users)");
                $result = $conn->query("ALTER TABLE upload_queue ADD CONSTRAINT fk_upload_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                }
            },
            function ($conn) {
                $conn->query("DELETE FROM transcode_queue WHERE user_id NOT IN (SELECT id FROM users)");
                $result = $conn->query("ALTER TABLE transcode_queue ADD CONSTRAINT fk_transcode_queue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                }
            },
            function ($conn) {
                $conn->query("DELETE FROM drive_files WHERE user_id NOT IN (SELECT id FROM users)");
                $result = $conn->query("ALTER TABLE drive_files ADD CONSTRAINT fk_drive_files_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                }
            },
        ],
    ],
    5 => [
        'description' => 'Ubah kolom title dari varchar(255) ke TEXT — cegah silent truncation title panjang',
        'sql' => [
            function ($conn) {
                $result = $conn->query("ALTER TABLE video MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (video.title): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE music MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (music.title): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE books MODIFY COLUMN title TEXT NOT NULL");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (books.title): {$err}\n";
                    }
                }
            },
        ],
    ],
    6 => [
        'description' => 'Buat tabel activity_log untuk audit trail — cegah crash saat prepare() gagal',
        'sql' => [
            function ($conn) {
                $conn->query("CREATE TABLE IF NOT EXISTS activity_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT DEFAULT NULL,
                    action VARCHAR(50) NOT NULL,
                    media_type VARCHAR(20) DEFAULT NULL,
                    media_id INT DEFAULT NULL,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            },
        ],
    ],
    7 => [
        'description' => 'Tambah UNIQUE KEY pada users.username — cegah bloat guest, optimasi ON DUPLICATE KEY',
        'sql' => [
            function ($conn) {

                $conn->query("DELETE g1 FROM users g1
                    INNER JOIN users g2
                    WHERE g1.id < g2.id
                    AND g1.role = 'guest'
                    AND g2.role = 'guest'
                    AND g1.username = g2.username");
            },
            function ($conn) {
                // Step 2: Reset AUTO_INCREMENT agar tidak ada gap besar
                $result = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS new_ai FROM users");
                if ($result) {
                    $row = $result->fetch_assoc();
                    $new_ai = (int)$row['new_ai'];
                    $conn->query("ALTER TABLE users AUTO_INCREMENT = " . (int)$new_ai);
                }
            },
            function ($conn) {
                // Step 3: Tambah UNIQUE KEY pada kolom username
                $result = $conn->query("ALTER TABLE users ADD UNIQUE INDEX idx_username_unique (username)");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                }
            },
        ],
    ],
    8 => [
        'description' => 'Sinkronisasi kolom & default values dengan actual DB — role→varchar(20), hapus duplicate UNIQUE KEY, defaults konsisten',
        'sql' => [
            function ($conn) {

                $result = $conn->query("ALTER TABLE users MODIFY COLUMN role varchar(20) DEFAULT 'user'");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (users.role): {$err}\n";
                    }
                }
            },
            function ($conn) {

                $check = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'username'");
                if ($check && $check->num_rows > 0) {
                    $conn->query("ALTER TABLE users DROP INDEX username");
                }
            },
            function ($conn) {
                // Step 3: Sync default values users table
                $conn->query("ALTER TABLE users ALTER COLUMN is_active SET DEFAULT 0");
            },
            function ($conn) {
                $conn->query("ALTER TABLE users ALTER COLUMN ip_address SET DEFAULT 'Unknown'");
            },
            function ($conn) {
                $conn->query("ALTER TABLE users ALTER COLUMN last_page SET DEFAULT 'Index'");
            },
            function ($conn) {
                // Step 4: Sync default value activity_log table
                $conn->query("ALTER TABLE activity_log ALTER COLUMN ip_address SET DEFAULT 'Unknown'");
            },
        ],
    ],
    9 => [
        'description' => 'Tambah kolom MFA (multi-factor authentication) ke tabel users',
        'sql' => [
            function ($conn) {
                $result = $conn->query("ALTER TABLE users ADD COLUMN mfa_secret VARCHAR(64) DEFAULT NULL AFTER last_session_id");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'Duplicate column')) {
                        echo "[MEeL] ⚠ Warning (mfa_secret): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE users ADD COLUMN mfa_backup_codes TEXT DEFAULT NULL AFTER mfa_secret");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'Duplicate column')) {
                        echo "[MEeL] ⚠ Warning (mfa_backup_codes): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE users ADD COLUMN mfa_enabled TINYINT(1) DEFAULT 0 AFTER mfa_backup_codes");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'Duplicate column')) {
                        echo "[MEeL] ⚠ Warning (mfa_enabled): {$err}\n";
                    }
                }
            },
        ],
    ],
    10 => [
        'description' => 'Tambah index komposit (video_id, created_at) & (music_id, created_at) pada tabel comments',
        'sql' => [
            function ($conn) {
                $conn->query("ALTER TABLE comments ADD INDEX idx_comments_video_created (video_id, created_at)");
            },
            function ($conn) {
                $conn->query("ALTER TABLE comments ADD INDEX idx_comments_music_created (music_id, created_at)");
            },
        ],
    ],
    11 => [
        'description' => 'Perbaiki unique key tabel interactions — pisah jadi (user_id, video_id) & (user_id, music_id) karena NULL di unique key gabungan tidak mencegah duplikat',
        'sql' => [
            function ($conn) {
                // Sisakan baris dengan id terbesar.
                $conn->query("DELETE i1 FROM interactions i1
                    INNER JOIN interactions i2
                    WHERE i1.id < i2.id
                    AND i1.user_id = i2.user_id
                    AND i1.video_id = i2.video_id
                    AND i1.video_id IS NOT NULL
                    AND i2.video_id IS NOT NULL");
            },
            function ($conn) {

                $conn->query("DELETE i1 FROM interactions i1
                    INNER JOIN interactions i2
                    WHERE i1.id < i2.id
                    AND i1.user_id = i2.user_id
                    AND i1.music_id = i2.music_id
                    AND i1.music_id IS NOT NULL
                    AND i2.music_id IS NOT NULL");
            },
            function ($conn) {
                // Step 3: Drop unique key lama (jika masih ada)
                $result = $conn->query("ALTER TABLE interactions DROP INDEX unique_interaction");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'drop index') && !str_contains($err, "can't DROP") && !str_contains($err, 'check that column/key exists')) {
                        echo "[MEeL] ⚠ Warning (drop unique_interaction): {$err}\n";
                    }
                }
            },
            function ($conn) {

                $result = $conn->query("ALTER TABLE interactions ADD UNIQUE INDEX unique_interaction_video (user_id, video_id)");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning (unique_interaction_video): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE interactions ADD UNIQUE INDEX unique_interaction_music (user_id, music_id)");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists') && !str_contains($err, 'already added')) {
                        echo "[MEeL] ⚠ Warning (unique_interaction_music): {$err}\n";
                    }
                }
            },
        ],
    ],
    12 => [
        'description' => 'Ikat identitas user ke room catur (white_user_id, black_user_id) — sebelumnya server tidak pernah memverifikasi siapa pemain putih/hitam, sehingga siapa pun yang tahu room_code bisa mengirim/melihat langkah game orang lain.',
        'sql' => [
            function ($conn) {
                $result = $conn->query("ALTER TABLE rooms ADD COLUMN white_user_id INT NULL AFTER room_code");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate column')) {
                        echo "[MEeL] ⚠ Warning (white_user_id): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE rooms ADD COLUMN black_user_id INT NULL AFTER white_user_id");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate column')) {
                        echo "[MEeL] ⚠ Warning (black_user_id): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE rooms ADD INDEX idx_rooms_white_user (white_user_id)");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (idx_rooms_white_user): {$err}\n";
                    }
                }
            },
            function ($conn) {
                $result = $conn->query("ALTER TABLE rooms ADD INDEX idx_rooms_black_user (black_user_id)");
                if (!$result) {
                    $err = $conn->error;
                    if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning (idx_rooms_black_user): {$err}\n";
                    }
                }
            },
        ],
    ],
];
// Catatan Sinkronisasi
$conn->query("CREATE TABLE IF NOT EXISTS db_version (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version INT NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Ambil versi terakhir yang sudah dijalankan
$result = $conn->query("SELECT MAX(version) AS current_version FROM db_version");
$row = $result->fetch_assoc();
$current_version = (int)($row['current_version'] ?? 0);

$new_migrations = 0;

foreach ($migrations as $version => $migration) {
    if ($version > $current_version) {
        echo "[MEeL] Menjalankan migrasi v{$version}: {$migration['description']}...\n";

        foreach ($migration['sql'] as $migration_step) {
            if (is_callable($migration_step)) {

                try {
                    $migration_step($conn);
                    $err = $conn->error;
                    if ($err && !str_contains($err, 'Duplicate key name') && !str_contains($err, 'already exists')) {
                        echo "[MEeL] ⚠ Warning: {$err}\n";
                    }
                } catch (\Throwable $e) {
                    echo "[MEeL] ⚠ Warning: " . $e->getMessage() . "\n";
                }
            } else {
                // Raw SQL string
                $sql = $migration_step;
                try {
                    if ($conn->query($sql) === false) {
                        $err = $conn->error;
                        if ($err && !str_contains($err, 'Duplicate key name') && !str_contains($err, 'already exists')) {
                            echo "[MEeL] ⚠ Warning: {$err}\n";
                        }
                    }
                } catch (\Throwable $e) {
                    echo "[MEeL] ⚠ Warning: " . $e->getMessage() . "\n";
                }
            }
        }

        // Catat migrasi sukses
        $stmt = $conn->prepare("INSERT INTO db_version (version) VALUES (?)");
        $stmt->bind_param("i", $version);
        $stmt->execute();
        $stmt->close();

        $new_migrations++;
        echo "[MEeL] ✓ Migrasi v{$version} selesai.\n";
    }
}

if ($new_migrations === 0) {
    echo "[MEeL] Database sudah up-to-date (versi {$current_version}). Tidak ada migrasi baru.\n";
} else {
    echo "[MEeL] ✓ {$new_migrations} migrasi berhasil dijalankan. Versi sekarang: " . ($current_version + $new_migrations) . "\n";
}

$conn->close();
