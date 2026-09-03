<?php
if (!function_exists('authorize_stream')) {
    
    function authorize_stream(int $id): void
    {
        if ($id <= 0) return;

        if (!isset($_SESSION['stream_ok']) || !is_array($_SESSION['stream_ok'])) {
            $_SESSION['stream_ok'] = [];
        }

        $_SESSION['stream_ok'][$id] = time();

        if (count($_SESSION['stream_ok']) > 100) {
            $oldest = array_search(min($_SESSION['stream_ok']), $_SESSION['stream_ok'], true);
            if ($oldest !== false) {
                unset($_SESSION['stream_ok'][$oldest]);
            }
        }
    }
}

if (!function_exists('is_stream_authorized')) {
    
    function is_stream_authorized(int $id, int $ttl = 7200): bool
    {
        if ($id <= 0) return false;
        if (!is_array($_SESSION['stream_ok'] ?? null)) return false;
        if (empty($_SESSION['stream_ok'][$id])) return false;

        return (time() - (int)$_SESSION['stream_ok'][$id]) <= $ttl;
    }
}
