<?php


if (!function_exists('meel_base_url_path')) {
    
    function meel_base_url_path(): string
    {
        $project_root = str_replace('\\', '/', dirname(__DIR__, 2));
        $doc_root     = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '/');
        $relative     = substr($project_root, strlen(rtrim($doc_root, '/')));
        return rtrim($relative, '/');
    }
}
