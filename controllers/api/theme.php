<?php



require_once '../../modules/core/helpers.php';
meel_boot_session();

include '../../auth/config.php';

header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user_id'] ?? null;


if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user_id) {
        $stmt = $conn->prepare("SELECT custom_theme FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $theme = ($row && in_array($row['custom_theme'], ['light', 'dark'], true))
            ? $row['custom_theme']
            : 'dark';
    } else {
        
        $theme = 'dark';
    }

    echo json_encode(['theme' => $theme]);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $theme = $input['theme'] ?? $_POST['theme'] ?? '';
    if (!in_array($theme, ['light', 'dark'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid theme']);
        exit;
    }

    if ($user_id) {
        $stmt = $conn->prepare("UPDATE users SET custom_theme = ? WHERE id = ?");
        $stmt->bind_param("si", $theme, $user_id);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['ok' => true, 'theme' => $theme]);
    exit;
}


http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
