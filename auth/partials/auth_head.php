<?php
/**
 * auth/partials/auth_head.php — <head> + body open bersama untuk halaman auth
 *
 * Variabel yang HARUS diset sebelum include:
 *   $auth_title       (string) Judul & og:title (mis. "MEeL | Login")
 *   $auth_description (string) Meta description
 *   $auth_og_title    (string) og:title
 *   $auth_og_desc     (string) og:description
 *
 * Variabel OPSIONAL (dipakai halaman MFA):
 *   $auth_extra_style (string) CSS tambahan yang disisipkan di dalam <style>
 *   $auth_extra_head  (string) HTML tambahan di dalam <head> (mis. <script src="qrcode.min.js">)
 *
 * Digunakan oleh: auth/login.php, auth/register.php, auth/mfa_setup.php, auth/mfa_verify.php
 */
$auth_extra_style = $auth_extra_style ?? '';
$auth_extra_head  = $auth_extra_head ?? '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($auth_description ?? '') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($auth_og_title ?? '') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($auth_og_desc ?? '') ?>">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title><?= htmlspecialchars($auth_title ?? '') ?></title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../assets/js/compatibilitas/lucide.js"></script>
    <style>
        body {
            background-color: #0b0e14;
        }

        .glass-effect {
            background: rgba(22, 27, 34, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        <?= $auth_extra_style ?>
    </style>
    <?= $auth_extra_head ?>
</head>

<body class="text-gray-200 min-h-screen flex items-center justify-center p-4">
