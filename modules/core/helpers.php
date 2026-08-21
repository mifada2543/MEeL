<?php
// Shim backward-compatible: helpers kini terpecah per domain.
// - helpers non-keamanan  → modules/core/helpers/main.php
// - infrastruktur keamanan → modules/auth/loader.php
// Entry point lama yang me-require file ini tetap berfungsi tanpa perubahan.
require_once __DIR__ . '/helpers/main.php';
require_once __DIR__ . '/../auth/loader.php';
