<?php

// ════════════════════════════════════════════════════════════════
// helpers.php — Shim (Backward-Compatible)
//
// Sebelumnya file ini berisi ~895 baris helper. Kini helper dipecah
// menjadi modul per-domain di folder helpers/ dengan end-point
// helpers/main.php. File ini dipertahankan sebagai shim tipis agar
// SEMUA pemanggil lama (require_once 'modules/core/helpers.php')
// tetap berfungsi tanpa perubahan apa pun.
//
// Modul pecahan: url, csrf, user, storage, audio, metadata, mfa, subtitle
// ════════════════════════════════════════════════════════════════

require_once __DIR__ . '/helpers/main.php';
