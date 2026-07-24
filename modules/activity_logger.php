<?php

// 1. Fungsi Penangkap IP Asli (Anti-Cloudflare Masking)
function get_real_ip()
{
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        return $_SERVER["HTTP_CF_CONNECTING_IP"];
    }
    if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        return trim(explode(',', $_SERVER["HTTP_X_FORWARDED_FOR"])[0]);
    }
    return $_SERVER["REMOTE_ADDR"] ?? '0.0.0.0'; // Fallback dengan default
}

// 2. Fungsi Deteksi Tipe Akses & Validasi IP
function validate_and_format_ip($ip)
{
    // Normalize: hapus whitespace
    $ip = trim($ip);
    
    // Filter loopback address (IPv4: 127.x.x.x)
    if (strpos($ip, '127.') === 0) {
        return ['ip' => 'LOCAL', 'display' => 'Local Access (IPv4)', 'is_local' => true, 'version' => 'ipv4'];
    }
    
    // Filter loopback address IPv6 (::1)
    if ($ip === '::1') {
        return ['ip' => 'LOCAL', 'display' => 'Local Access (IPv6)', 'is_local' => true, 'version' => 'ipv6'];
    }
    
    // Handle IPv4-mapped IPv6 addresses (::ffff:192.168.1.1)
    if (strpos($ip, '::ffff:') === 0) {
        $ipv4 = substr($ip, 7); // Extract IPv4 part
        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ip' => $ipv4, 'display' => $ipv4 . ' (IPv4-mapped)', 'is_local' => false, 'version' => 'ipv4-mapped'];
        }
    }
    
    // Filter localhost
    if ($ip === 'localhost') {
        return ['ip' => 'LOCAL', 'display' => 'Local Access (localhost)', 'is_local' => true, 'version' => 'hostname'];
    }
    
    // Validasi IPv6 format
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return ['ip' => $ip, 'display' => $ip . ' (IPv6)', 'is_local' => false, 'version' => 'ipv6'];
    }
    
    // Validasi IPv4 format
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['ip' => $ip, 'display' => $ip . ' (IPv4)', 'is_local' => false, 'version' => 'ipv4'];
    }
    
    return ['ip' => 'Unknown', 'display' => 'Unknown', 'is_local' => false, 'version' => 'unknown'];
}

// 3. Fungsi Deteksi Metode Akses
function get_access_method()
{
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        // Cek apakah via Cloudflare tunnel
        if (strpos($_SERVER["HTTP_HOST"] ?? '', 'trycloudflare.com') !== false) {
            return 'Cloudflare Tunnel';
        }
        return 'Cloudflare CDN';
    }
    if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        return 'Proxy/Forwarded';
    }
    if (isset($_SERVER["HTTP_X_REAL_IP"])) {
        return 'Nginx Proxy';
    }
    if (isset($_SERVER["HTTP_VIA"])) {
        return 'HTTP Proxy';
    }
    return 'Direct';
}

// 4. Fungsi Deteksi IPv6 vs IPv4 Access
function get_connection_protocol()
{
    $ip = get_real_ip();
    
    // Check if IPv6 (includes IPv4-mapped IPv6)
    if (strpos($ip, ':') !== false) {
        return 'IPv6';
    }
    
    return 'IPv4';
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. log_activity() — INSERT ke tabel activity_log untuk audit trail
// ═══════════════════════════════════════════════════════════════════════════
if (!function_exists('log_activity')) {
    /**
     * Catat aktivitas user ke tabel activity_log.
     *
     * @param mysqli  $conn       Koneksi database
     * @param int     $user_id    ID user (0 untuk guest)
     * @param string  $action     Tipe aksi (login, logout, upload_video, etc)
     * @param string  $media_type Tipe media (video, music, books, user, dll) — opsional
     * @param int|null $media_id  ID media terkait — opsional
     */
    function log_activity(mysqli $conn, int $user_id, string $action, string $media_type = '', ?int $media_id = null): void
    {
        $ip = '0.0.0.0';
        if (PHP_SAPI !== 'cli' && function_exists('get_real_ip')) {
            $ip = get_real_ip();
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // Handle null media_id — gunakan prepared statement berbeda agar NULL dikirim, bukan 0
        if ($media_id === null) {
            $stmt = $conn->prepare(
                "INSERT INTO activity_log (user_id, action, media_type, ip_address, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param("isss", $user_id, $action, $media_type, $ip);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO activity_log (user_id, action, media_type, media_id, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param("issis", $user_id, $action, $media_type, $media_id, $ip);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

// CLI guard — cegah warning $_SERVER undefined saat di CLI (migration, cron, dll)
// Fungsi-fungsi di atas tetap terdefinisi, hanya LOGIC eksekusi yang di-skip
if (PHP_SAPI === 'cli') {
    return;
}

$user_ip_data = validate_and_format_ip(get_real_ip());
$user_ip = $user_ip_data['ip'];
$access_method = get_access_method();
$connection_protocol = get_connection_protocol();

// Debug info (optional, bisa di-remove nanti)
// Uncomment line di bawah untuk debugging
// error_log("[MEeL-Logger] IP: $user_ip | Raw: " . get_real_ip() . " | Protocol: $connection_protocol | Method: $access_method");

if (isset($conn)) {

    // Ambil role langsung dari DB jika di session tidak ada, supaya lebih akurat
    $session_role = $_SESSION['role'] ?? null;
    // Cek apakah IP user masuk dalam daftar ban
    $check_ban = $conn->prepare("SELECT reason FROM ip_ban WHERE ip_address = ?");
    $check_ban->bind_param("s", $user_ip);
    $check_ban->execute();
    $ban_res = $check_ban->get_result();

    $current_page = basename($_SERVER['PHP_SELF']);
    if ($current_page !== 'banned.php' && $current_page !== 'revoked.php') {
        if ($ban_res->num_rows > 0) {
            // Jika bukan admin, baru di-redirect
            if ($session_role !== 'admin') {
                $row = $ban_res->fetch_assoc();
                $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
                $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
                $relative_base = rtrim('/' . ltrim(str_replace($doc_root, '', $root_dir), '/'), '/');
                $banned_url = $relative_base . '/err/banned.php';
                header("Location: " . $banned_url . "?reason=" . urlencode($row['reason']));
                exit();
            }
        }
    }
    // Deteksi halaman dan aktivitas user
    $current_page = basename($_SERVER['PHP_SELF']);
    $dir_name = basename(dirname($_SERVER['PHP_SELF']));
    $id_get = isset($_GET['id']) ? $_GET['id'] : null;

    // --- 1. LOGIKA DETEKSI JUDUL KONTEN ---
    if ($id_get) {
        if ($current_page == 'watch.php') {
            $table = ($dir_name == 'music') ? 'music' : 'video';
            $label = ($dir_name == 'music') ? "Listening: " : "Watching: ";

            $get_title = $conn->prepare("SELECT title FROM $table WHERE id = ?");
            if ($get_title) {
                $get_title->bind_param("i", $id_get);
                $get_title->execute();
                $res = $get_title->get_result()->fetch_assoc();
                if ($res) {
                    $short_title = (mb_strlen($res['title']) > 20) ? mb_substr($res['title'], 0, 17) . '...' : $res['title'];
                    $current_page = $label . $short_title;
                }
            }
        } elseif ($current_page == 'read.php') {
            $get_book = $conn->prepare("SELECT title FROM books WHERE id = ?");
            if ($get_book) {
                $get_book->bind_param("i", $id_get);
                $get_book->execute();
                $res = $get_book->get_result()->fetch_assoc();
                if ($res) {
                    $short_title = (mb_strlen($res['title']) > 20) ? mb_substr($res['title'], 0, 17) . '...' : $res['title'];
                    $current_page = "Reading: " . $short_title;
                }
            }
        } elseif ($current_page == 'read_pdf.php' || $current_page == 'pdf.php') {
            $get_book = $conn->prepare("SELECT title FROM books WHERE id = ?");
            if ($get_book) {
                $get_book->bind_param("i", $id_get);
                $get_book->execute();
                $res = $get_book->get_result()->fetch_assoc();
                if ($res) {
                    $short_title = (mb_strlen($res['title']) > 20) ? mb_substr($res['title'], 0, 17) . '...' : $res['title'];
                    $current_page = "Reading PDF: " . $short_title;
                }
            }
        } elseif ($current_page == 'stream.php' && $dir_name == 'music') {
            // Stream.php dipanggil berkali-kali (range requests) selama playback.
            // Throttle: hanya query DB jika lagu berganti, supaya tidak membebani
            // server dengan query berulang untuk lagu yang sama.
            $last_stream_id = $_SESSION['_last_stream_id'] ?? null;
            if ($last_stream_id !== $id_get) {
                $_SESSION['_last_stream_id'] = $id_get;
                $get_title = $conn->prepare("SELECT title FROM music WHERE id = ?");
                if ($get_title) {
                    $get_title->bind_param("i", $id_get);
                    $get_title->execute();
                    $res = $get_title->get_result()->fetch_assoc();
                    if ($res) {
                        $short_title = (mb_strlen($res['title']) > 20) ? mb_substr($res['title'], 0, 17) . '...' : $res['title'];
                        $current_page = "Streaming: " . $short_title;
                        $_SESSION['_last_stream_page'] = $current_page;
                    }
                }
            } elseif (isset($_SESSION['_last_stream_page'])) {
                // Range request berikutnya untuk lagu yang sama — pakai title yang
                // sudah di-cache di session, bukan query ulang ke DB.
                $current_page = $_SESSION['_last_stream_page'];
            }
        } elseif ($current_page == 'index.php' && $dir_name == 'profile') {
            $target_user = $_GET['u'] ?? 'Someone';
            $current_page = "Viewing Profile: " . htmlspecialchars($target_user);
        }
    } elseif ($current_page == 'index.php') {
        // --- 2. LOGIKA DETEKSI HALAMAN INDEX (BROWSING LIBRARY) ---
        // Saat user membuka index di folder video, music, books, dll.
        // $dir_name = basename(dirname(...)) — bisa kosong '' untuk root HUB
        switch ($dir_name) {
            case 'video':
                $current_page = "Browsing Video Library";
                break;
            case 'music':
                $current_page = "Browsing Music Library";
                break;
            case 'books':
                $current_page = "Browsing Books Library";
                break;
            case 'arcade':
                $current_page = "Browsing Arcade";
                break;
            case 'drive':
                $current_page = "Browsing Drive";
                break;
            case 'profile':
                $current_page = "Browsing Profiles";
                break;
            default:
                // Root index.php (HUB) — dir_name bisa kosong '' atau nama folder proyek
                $current_page = "Browsing HUB";
                break;
        }
    }
    $ua_raw = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $host = $_SERVER['HTTP_HOST'] ?? 'Local';
    $access_via = $access_method; // Gunakan metode akses, bukan hanya hostname

    // Deteksi Device
    $device = "Unknown";
    if (strpos($ua_raw, 'Android') !== false) $device = "Smartphone";
    elseif (strpos($ua_raw, 'Linux') !== false) $device = "Linux PC";
    elseif (strpos($ua_raw, 'Windows') !== false) $device = "Windows PC";
    elseif (strpos($ua_raw, 'Macintosh') !== false) $device = "Mac";
    elseif (strpos($ua_raw, 'iPhone') !== false) $device = "iPhone";

    if (isset($_SESSION['user_id'])) {
        $uid = $_SESSION['user_id'];
        $current_sid = session_id();

        // 1. MIRO: AMBIL DATA SESI & STATUS DARI DB SEKALIGUS
        $stmt_check = $conn->prepare("SELECT last_session_id, role FROM users WHERE id = ?");
        $stmt_check->bind_param("i", $uid);
        $stmt_check->execute();
        $user_status = $stmt_check->get_result()->fetch_assoc();

        // 2. LOGIKA KICK: Jika SID di DB berbeda dengan browser, langsung tendang!
        // Kita kecualikan Admin agar admin tidak menendang dirinya sendiri secara tidak sengaja
        // HANYA kick jika last_session_id TIDAK KOSONG dan BERBEDA dengan current SID
        if ($current_page !== 'banned.php' && $current_page !== 'revoked.php') {
            if ($user_status && $user_status['role'] !== 'admin') {
                if (!empty($user_status['last_session_id']) && $user_status['last_session_id'] !== $current_sid) {
                    session_unset();
                    session_destroy();

                    $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
                    $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
                    $relative_base = rtrim('/' . ltrim(str_replace($doc_root, '', $root_dir), '/'), '/');
                    $revoked_url = $relative_base . '/err/revoked.php';
                    header("Location: " . $revoked_url);
                    exit();
                }
            }
        }
        
        // 3. Update session ID HANYA jika kosong di database (first time)
        if (empty($user_status['last_session_id'])) {
            $stmt_update_sid = $conn->prepare("UPDATE users SET last_session_id = ? WHERE id = ?");
            $stmt_update_sid->bind_param("si", $current_sid, $uid);
            $stmt_update_sid->execute();
        }

        // 3. UPDATE AKTIVITAS (Lanjutkan proses logger asli kamu)
        $stmt = $conn->prepare("UPDATE users SET last_page = ?, user_agent = ?, access_via = ?, ip_address = ?, last_activity = NOW() WHERE id = ?");
        $stmt->bind_param("ssssi", $current_page, $device, $access_via, $user_ip, $uid);
        $stmt->execute();
    } else {
        // LOGIKA GUEST — 1 query (INSERT ON DUPLICATE KEY) bukan 2 query (SELECT + INSERT/UPDATE)
        // Gunakan hash dari full session_id sebagai username unik agar ON DUPLICATE KEY benar-benar bekerja
        // saat UNIQUE KEY pada kolom username sudah ditambahkan via migrasi v7
        $guest_id = "g_" . substr(md5(session_id()), 0, 10);
        $role     = 'guest';
        $guest_upd = $conn->prepare(
            "INSERT INTO users (username, role, last_page, user_agent, access_via, ip_address, last_activity)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                last_page = VALUES(last_page),
                user_agent = VALUES(user_agent),
                access_via = VALUES(access_via),
                ip_address = VALUES(ip_address),
                last_activity = NOW()"
        );
        $guest_upd->bind_param("ssssss", $guest_id, $role, $current_page, $device, $access_via, $user_ip);
        $guest_upd->execute();
    }
}
