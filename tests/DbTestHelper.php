<?php


class DbTestHelper
{
    private const TEST_DB_NAME = 'MEeL-test';

    private static ?string $testDbName = null;

    private ?mysqli $conn = null;
    private bool $inTransaction = false;

    

    const ADMIN_USER_ID = 1;     
    const ADMIN2_USER_ID = 9;    
    const MEMBER_USER_ID = 10;   
    const REGULAR_USER_ID = 39;  

    const MUSIC_ID_1 = 49;
    const MUSIC_ID_2 = 50;
    const MUSIC_ID_3 = 51;

    const VIDEO_ID_1 = 4;
    const VIDEO_ID_2 = 5;
    const VIDEO_ID_3 = 6;

    
    private static function serverCreds(): array
    {
        return [
            'host' => getenv('MEEL_TEST_DB_HOST') ?: 'localhost',
            'user' => getenv('MEEL_TEST_DB_USER') ?: 'root',
            'pass' => getenv('MEEL_TEST_DB_PASS') ?: '',
        ];
    }

    

    private static function ensureTestDatabase(): void
    {
        if (self::$testDbName !== null) {
            return;
        }

        $creds = self::serverCreds();

        
        $admin = null;
        for ($i = 0; $i < 10; $i++) {
            $admin = @new mysqli($creds['host'], $creds['user'], $creds['pass']);
            if (!$admin->connect_error) {
                break;
            }
            usleep(500000);
        }
        if (!$admin || $admin->connect_error) {
            throw new RuntimeException(
                'Test DB: koneksi MySQL gagal: ' . ($admin ? $admin->connect_error : 'tidak dapat terhubung')
            );
        }
        $admin->set_charset('utf8mb4');

        $name = self::TEST_DB_NAME;
        $schemaPath = dirname(__DIR__) . '/database/schema.sql';
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Test DB: database/schema.sql tidak ditemukan.');
        }

        
        
        $admin->query('DROP DATABASE IF EXISTS `' . $name . '`');
        if ($admin->error) {
            throw new RuntimeException('Test DB: gagal drop database lama: ' . $admin->error);
        }

        
        $schema = str_replace('`MEeL`', '`' . $name . '`', (string) file_get_contents($schemaPath));

        if (!$admin->multi_query($schema)) {
            throw new RuntimeException('Test DB: gagal import schema: ' . $admin->error);
        }
        while ($admin->more_results()) {
            $admin->next_result();
            if ($admin->error) {
                throw new RuntimeException('Test DB: gagal import schema: ' . $admin->error);
            }
        }
        $admin->close();

        self::$testDbName = $name;

        
        register_shutdown_function(static function () use ($name, $creds): void {
            $c = @new mysqli($creds['host'], $creds['user'], $creds['pass']);
            if (!$c->connect_error) {
                $c->query('DROP DATABASE IF EXISTS `' . $name . '`');
                $c->close();
            }
        });
    }

    
    public function getConnection(): mysqli
    {
        if ($this->conn === null) {
            self::ensureTestDatabase();
            $creds = self::serverCreds();
            $this->conn = new mysqli($creds['host'], $creds['user'], $creds['pass'], self::$testDbName);
            if ($this->conn->connect_error) {
                throw new RuntimeException(
                    'DB Connection failed: ' . $this->conn->connect_error
                );
            }
            $this->conn->set_charset('utf8mb4');
            
            $this->conn->begin_transaction();
            $this->inTransaction = true;
            
            $this->seedFixtureData();
        }
        return $this->conn;
    }

    public function rollback(): void
    {
        if ($this->conn !== null && $this->inTransaction) {
            $this->conn->rollback();
            $this->inTransaction = false;
        }
    }

    

    private function seedFixtureData(): void
    {
        
        
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        }

        
        
        $users = [
            [self::ADMIN_USER_ID,   'Admin',   'admin'],
            [self::ADMIN2_USER_ID,  'Admin2',  'admin'],
            [self::MEMBER_USER_ID,  'Member1', 'member'],
            [self::REGULAR_USER_ID, 'User1',   'user'],
        ];
        $stmt = $this->conn->prepare(
            'INSERT IGNORE INTO users (id, username, role, password, is_active) VALUES (?, ?, ?, ?, 1)'
        );
        foreach ($users as $u) {
            $id   = $u[0];
            $name = $u[1];
            $role = $u[2];
            $stmt->bind_param('isss', $id, $name, $role, $hash);
            $stmt->execute();
        }
        $stmt->close();

        $artist   = 'Test Fixture';
        $uploader = self::ADMIN2_USER_ID;
        $stmt = $this->conn->prepare(
            'INSERT IGNORE INTO music (id, title, artist, filename, user_id) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ([self::MUSIC_ID_1, self::MUSIC_ID_2, self::MUSIC_ID_3] as $id) {
            $title    = 'Fixture Track ' . $id;
            $filename = 'fixture_' . $id . '.mp3';
            $stmt->bind_param('isssi', $id, $title, $artist, $filename, $uploader);
            $stmt->execute();
        }
        $stmt->close();

        $stmt = $this->conn->prepare(
            'INSERT IGNORE INTO video (id, title, filename, user_id) VALUES (?, ?, ?, ?)'
        );
        foreach ([self::VIDEO_ID_1, self::VIDEO_ID_2, self::VIDEO_ID_3] as $id) {
            $title    = 'Fixture Video ' . $id;
            $filename = 'fixture_' . $id . '.mp4';
            $stmt->bind_param('issi', $id, $title, $filename, $uploader);
            $stmt->execute();
        }
        $stmt->close();
    }

    
    public function commit(): void
    {
        if ($this->conn !== null && $this->inTransaction) {
            $this->conn->commit();
            $this->inTransaction = false;
        }
    }

    
    public function close(): void
    {
        if ($this->conn !== null) {
            $this->rollback(); 
            $this->conn->close();
            $this->conn = null;
        }
    }

    
    public function getMusicLikesCount(int $musicId): array
    {
        $stmt = $this->conn->prepare("SELECT likes, dislikes FROM music WHERE id = ?");
        $stmt->bind_param("i", $musicId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return [
            'likes' => (int)($result['likes'] ?? 0),
            'dislikes' => (int)($result['dislikes'] ?? 0)
        ];
    }

    
    public function getVideoLikesCount(int $videoId): array
    {
        $stmt = $this->conn->prepare("SELECT likes, dislikes FROM video WHERE id = ?");
        $stmt->bind_param("i", $videoId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return [
            'likes' => (int)($result['likes'] ?? 0),
            'dislikes' => (int)($result['dislikes'] ?? 0)
        ];
    }

    
    public function interactionExists(int $userId, string $col, int $mediaId): ?string
    {
        $stmt = $this->conn->prepare(
            "SELECT `TYPE` FROM interactions WHERE user_id = ? AND $col = ?"
        );
        $stmt->bind_param("ii", $userId, $mediaId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result['TYPE'] ?? null;
    }

    
    public function createTestComment(int $userId, ?int $musicId, ?int $videoId, string $text): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO comments (user_id, music_id, video_id, comment) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("iiss", $userId, $musicId, $videoId, $text);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    
    public function getCommentOwner(int $commentId): ?int
    {
        $stmt = $this->conn->prepare("SELECT user_id FROM comments WHERE id = ?");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ? (int)$result['user_id'] : null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
