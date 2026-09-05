<?php
$auth_extra_style = $auth_extra_style ?? '';
$auth_extra_head  = $auth_extra_head ?? '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script>
    (function(){
      try {
        var t = localStorage.getItem('meel_theme');
        if (t === 'light' || t === 'dark') {
          document.documentElement.setAttribute('data-theme', t);
          if (t === 'dark') document.documentElement.classList.add('dark');
          else document.documentElement.classList.remove('dark');
        } else {
          document.documentElement.setAttribute('data-theme', 'dark');
          document.documentElement.classList.add('dark');
        }
      } catch(e) {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.documentElement.classList.add('dark');
      }
    })();
    </script>

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
    <link rel="stylesheet" href="../assets/css/shared/theme-tokens.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/shared/theme-tokens.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/light-theme.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/shared/light-theme.css') ?>">
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
