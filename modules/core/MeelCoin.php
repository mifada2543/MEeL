<?php

class MeelCoin
{
    private static ?array $settingsCache = null;

    private static function loadSettings(\mysqli $conn): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $defaults = [
            'meelcoin_enabled'       => '1',
            'meelcoin_upload_cost'   => '5',
            'meelcoin_advanced_cost' => '10',
            'meelcoin_user_max'      => '25',
            'meelcoin_user_refill'   => '15',
            'meelcoin_member_max'    => '50',
            'meelcoin_member_refill' => '25',
            'meelcoin_refill_hours'  => '5',
        ];

        $result = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'meelcoin_%'");
        $settings = $defaults;
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        self::$settingsCache = $settings;
        return $settings;
    }

    public static function clearCache(): void
    {
        self::$settingsCache = null;
    }

    public static function isEnabled(\mysqli $conn): bool
    {
        $s = self::loadSettings($conn);
        return $s['meelcoin_enabled'] === '1';
    }

    public static function getCost(\mysqli $conn, string $type): int
    {
        $s = self::loadSettings($conn);
        return (int)($type === 'advanced' ? $s['meelcoin_advanced_cost'] : $s['meelcoin_upload_cost']);
    }

    public static function getMax(\mysqli $conn, string $role): int
    {
        $s = self::loadSettings($conn);
        if ($role === 'member') return (int)$s['meelcoin_member_max'];
        return (int)$s['meelcoin_user_max'];
    }

    public static function getRefillAmount(\mysqli $conn, string $role): int
    {
        $s = self::loadSettings($conn);
        if ($role === 'member') return (int)$s['meelcoin_member_refill'];
        return (int)$s['meelcoin_user_refill'];
    }

    public static function getRefillHours(\mysqli $conn): int
    {
        $s = self::loadSettings($conn);
        return (int)$s['meelcoin_refill_hours'];
    }

    public static function getBalance(\mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("SELECT meelcoin FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['meelcoin'] : 0;
    }

    public static function canAfford(\mysqli $conn, int $userId, int $amount): bool
    {
        return self::getBalance($conn, $userId) >= $amount;
    }

    public static function spend(\mysqli $conn, int $userId, int $amount, string $reason): array
    {
        $balance = self::getBalance($conn, $userId);
        if ($balance < $amount) {
            return [false, 'MEeLCoin tidak cukup. Dibutuhkan: ' . $amount . ', tersedia: ' . $balance];
        }

        $newBalance = $balance - $amount;

        $stmt = $conn->prepare("UPDATE users SET meelcoin = ? WHERE id = ?");
        $stmt->bind_param("ii", $newBalance, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [false, 'Gagal memperbarui saldo MEeLCoin.'];
        }
        $stmt->close();

        self::log($conn, $userId, -$amount, $newBalance, $reason);
        return [true, ''];
    }

    public static function refund(\mysqli $conn, int $userId, int $amount, string $reason): bool
    {
        $balance = self::getBalance($conn, $userId);
        $newBalance = $balance + $amount;

        $stmt = $conn->prepare("UPDATE users SET meelcoin = ? WHERE id = ?");
        $stmt->bind_param("ii", $newBalance, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            self::log($conn, $userId, $amount, $newBalance, $reason);
        }
        return $ok;
    }

    public static function refill(\mysqli $conn, int $userId, string $role): bool
    {
        if ($role === 'admin') return true;

        $refillHours = self::getRefillHours($conn);
        $maxCoins    = self::getMax($conn, $role);
        $refillAmt   = self::getRefillAmount($conn, $role);

        $stmt = $conn->prepare("SELECT meelcoin, meelcoin_last_refill FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return false;

        $current      = (int)$row['meelcoin'];
        $lastRefill   = $row['meelcoin_last_refill'];
        $now          = time();

        if ($current >= $maxCoins) return false;

        $shouldRefill = false;
        if ($lastRefill === null) {
            $shouldRefill = true;
        } else {
            $elapsed = $now - strtotime($lastRefill);
            if ($elapsed >= $refillHours * 3600) {
                $shouldRefill = true;
            }
        }

        if (!$shouldRefill) return false;

        $newBalance = min($maxCoins, $current + $refillAmt);
        $added = $newBalance - $current;

        if ($added <= 0) return false;

        $stmt = $conn->prepare("UPDATE users SET meelcoin = ?, meelcoin_last_refill = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $newBalance, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            self::log($conn, $userId, $added, $newBalance, 'refill');
        }
        return $ok;
    }

    public static function getRefillCountdown(\mysqli $conn, int $userId, string $role): int
    {
        if ($role === 'admin') return 0;

        $refillHours = self::getRefillHours($conn);

        $stmt = $conn->prepare("SELECT meelcoin, meelcoin_last_refill FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return 0;

        $current    = (int)$row['meelcoin'];
        $maxCoins   = self::getMax($conn, $role);
        $lastRefill = $row['meelcoin_last_refill'];

        if ($current >= $maxCoins) return 0;

        if ($lastRefill === null) return 0;

        $elapsed   = time() - strtotime($lastRefill);
        $remaining = ($refillHours * 3600) - $elapsed;
        return max(0, $remaining);
    }

    public static function initialize(\mysqli $conn, int $userId, string $role): void
    {
        if ($role === 'admin') return;

        $maxCoins = self::getMax($conn, $role);

        $stmt = $conn->prepare("UPDATE users SET meelcoin = ?, meelcoin_last_refill = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $maxCoins, $userId);
        $stmt->execute();
        $stmt->close();

        self::log($conn, $userId, $maxCoins, $maxCoins, 'init');
    }

    public static function log(\mysqli $conn, int $userId, int $amount, int $balanceAfter, string $reason): void
    {
        $stmt = $conn->prepare("INSERT INTO meelcoin_log (user_id, amount, balance_after, reason) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $userId, $amount, $balanceAfter, $reason);
        $stmt->execute();
        $stmt->close();
    }
}
