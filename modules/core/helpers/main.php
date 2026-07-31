<?php

// ════════════════════════════════════════════════════════════════
// helpers/main.php — Endpoint Modul Helper
//
// Memuat semua pecahan modules/core/helpers.php:
//   url, csrf, user, storage, audio, metadata, mfa, subtitle
//
// Jangan require file pecahan secara langsung kecuali memang hanya
// butuh fungsi di file tersebut — selalu gunakan helpers.php atau
// file ini agar seluruh fungsi tersedia.
//
// Semua fungsi di setiap file dibungkus function_exists() guard
// sebagai defense-in-depth terhadap double-include.
// ════════════════════════════════════════════════════════════════

require_once __DIR__ . '/url.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/user.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/audio.php';
require_once __DIR__ . '/metadata.php';
require_once __DIR__ . '/mfa.php';
require_once __DIR__ . '/subtitle.php';
