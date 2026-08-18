<?php
// Deteksi protokol & host
if (function_exists('detectProtocol')) {
    $_head_proto = detectProtocol();
} else {
    $_head_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https'
        : (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https'
        : (!empty($_SERVER['HTTP_CF_VISITOR']) && ($cf = @json_decode($_SERVER['HTTP_CF_VISITOR'], true)) && !empty($cf['scheme']) && $cf['scheme'] === 'https' ? 'https'
        : 'http'));
}
$_head_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_head_base = $_head_proto . '://' . $_head_host;

// Project root base (untuk asset tetap seperti manifest, favicon, OG image)
$_head_root_path = str_replace('\\', '/', dirname(__DIR__));
$_head_doc_root  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '/');
$_head_root_rel  = str_replace(rtrim($_head_doc_root, '/'), '', $_head_root_path);
$_head_root      = $_head_proto . '://' . $_head_host . rtrim($_head_root_rel, '/\\');

// Default values
$_META_TITLE        = $_META_TITLE        ?? 'MEeL | Media Hub';
$_META_DESC         = $_META_DESC         ?? 'Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.';
$_META_IMAGE        = $_META_IMAGE        ?? $_head_root . '/assets/MEeL.png';
$_META_IMAGE_W      = $_META_IMAGE_W      ?? '500';
$_META_IMAGE_H      = $_META_IMAGE_H      ?? '500';
$_META_TYPE         = $_META_TYPE         ?? 'website';
$_META_URL          = $_META_URL          ?? $_head_base . ($_SERVER['REQUEST_URI'] ?? '/');
$_META_SITE_NAME    = $_META_SITE_NAME    ?? 'MEeL';
$_META_LOCALE       = $_META_LOCALE       ?? 'id_ID';
$_META_TWITTER_SITE = $_META_TWITTER_SITE ?? '@meel_hub';
$_META_THEME_COLOR  = $_META_THEME_COLOR  ?? '#05070c';
$_META_ROBOTS       = $_META_ROBOTS       ?? 'index, follow';
$_META_CANONICAL    = $_META_CANONICAL    ?? $_META_URL;
$_META_EXTRA        = $_META_EXTRA        ?? '';

// Escape semuanya
$_e_title    = htmlspecialchars($_META_TITLE, ENT_QUOTES, 'UTF-8');
$_e_desc     = htmlspecialchars($_META_DESC, ENT_QUOTES, 'UTF-8');
$_e_image    = htmlspecialchars($_META_IMAGE, ENT_QUOTES, 'UTF-8');
$_e_image_w  = htmlspecialchars($_META_IMAGE_W, ENT_QUOTES, 'UTF-8');
$_e_image_h  = htmlspecialchars($_META_IMAGE_H, ENT_QUOTES, 'UTF-8');
$_e_url      = htmlspecialchars($_META_URL, ENT_QUOTES, 'UTF-8');
$_e_site     = htmlspecialchars($_META_SITE_NAME, ENT_QUOTES, 'UTF-8');
$_e_locale   = htmlspecialchars($_META_LOCALE, ENT_QUOTES, 'UTF-8');
$_e_twitter  = htmlspecialchars($_META_TWITTER_SITE, ENT_QUOTES, 'UTF-8');
$_e_canonical = htmlspecialchars($_META_CANONICAL, ENT_QUOTES, 'UTF-8');
$_e_theme    = htmlspecialchars($_META_THEME_COLOR, ENT_QUOTES, 'UTF-8');
$_e_robots   = htmlspecialchars($_META_ROBOTS, ENT_QUOTES, 'UTF-8');
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= $_e_title ?></title>
<meta name="description" content="<?= $_e_desc ?>">
<meta name="robots" content="<?= $_e_robots ?>">
<meta name="theme-color" content="<?= $_e_theme ?>">

<!-- Canonical URL -->
<link rel="canonical" href="<?= $_e_canonical ?>">

<!-- Open Graph -->
<meta property="og:title" content="<?= $_e_title ?>">
<meta property="og:description" content="<?= $_e_desc ?>">
<meta property="og:image" content="<?= $_e_image ?>">
<meta property="og:image:width" content="<?= $_e_image_w ?>">
<meta property="og:image:height" content="<?= $_e_image_h ?>">
<meta property="og:url" content="<?= $_e_url ?>">
<meta property="og:type" content="<?= htmlspecialchars($_META_TYPE, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:site_name" content="<?= $_e_site ?>">
<meta property="og:locale" content="<?= $_e_locale ?>">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $_e_title ?>">
<meta name="twitter:description" content="<?= $_e_desc ?>">
<meta name="twitter:image" content="<?= $_e_image ?>">
<?php if (!empty($_META_TWITTER_SITE)): ?>
<meta name="twitter:site" content="<?= $_e_twitter ?>">
<?php endif; ?>
<!-- Icons & App (pakai $_head_root agar selalu mengarah ke root project) -->
<link rel="manifest" href="<?= $_head_root ?>/assets/manifest.json">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $_head_root ?>/assets/MEeL.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= $_head_root ?>/assets/MEeL.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $_head_root ?>/assets/MEeL-180.png">

<!-- iOS / Android standalone PWA -->
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MEeL">

<!-- Structured Data (JSON-LD) -->
<script type="application/ld+json">
<?php if (isset($_META_JSONLD) && is_array($_META_JSONLD)): ?>
<?= json_encode($_META_JSONLD, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
<?php else: ?>
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": <?= json_encode($_META_TITLE, JSON_UNESCAPED_UNICODE) ?>,
  "description": <?= json_encode($_META_DESC, JSON_UNESCAPED_UNICODE) ?>,
  "url": <?= json_encode($_META_URL, JSON_UNESCAPED_SLASHES) ?>,
  "image": <?= json_encode($_META_IMAGE, JSON_UNESCAPED_SLASHES) ?>,
  "inLanguage": "id-ID",
  "isAccessibleForFree": true,
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "<?= $_head_root ?>/search?q={search_term_string}"
    },
    "query-input": "required name=search_term_string"
  }
}
<?php endif; ?>
</script>

<!-- PWA: Service Worker Registration -->
<script>
if ('serviceWorker' in navigator) {
    // Dapatkan base path project dari lokasi file ini (partials/head.php)
    const swUrl = (<?= json_encode(rtrim($_head_root_rel, '/')) ?> || '') + '/sw.js';
    window.addEventListener('load', function() {
        // updateViaCache:'none' → sw.js SELALU diambil fresh dari server
        // (browser default hanya mengecek update SW maks 1x/24 jam, bikin
        // perubahan logo/asset baru tidak muncul saat Ctrl+R)
        navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' }).then(function(reg) {
            // Cek update di setiap load halaman → SW baru (mis. versi baru
            // dengan logo/asset baru) langsung ter-install tanpa nunggu 24 jam
            reg.update().catch(function() {
                // Offline — abaikan, cek berikutnya akan mencoba lagi
            });

            // Update ditemukan → tandai, nanti controllerchange auto-reload
            reg.addEventListener('updatefound', function() {
                const installing = reg.installing || reg.waiting;
                if (!installing) return;
                installing.addEventListener('statechange', function() {
                    if (this.state === 'installed' && navigator.serviceWorker.controller) {
                        // SW baru tersedia (skipWaiting + clients.claim aktif)
                        sessionStorage.setItem('meel_pwa_update', '1');
                    }
                });
            });
        }).catch(function(err) {
            console.warn('[PWA] Registration failed:', err.message);
        });
    });

    // SW baru mengambil alih halaman → reload sekali saja, tanpa nunggu user
    // refresh manual (flag sessionStorage menghindari reload saat install pertama).
    navigator.serviceWorker.addEventListener('controllerchange', function() {
        if (sessionStorage.getItem('meel_pwa_update')) {
            sessionStorage.removeItem('meel_pwa_update');
            window.location.reload();
        }
    });
}
</script>

<!-- Extra head content (if any) -->
<?= $_META_EXTRA ?>
<!-- Cleanup variabel global agar tidak bocor ke halaman lain -->
<?php
unset(
    $_head_proto, $_head_host, $_head_base,
    $_head_root_path, $_head_doc_root, $_head_root_rel, $_head_root,
    $_META_TITLE, $_META_DESC, $_META_IMAGE, $_META_IMAGE_W, $_META_IMAGE_H,
    $_META_TYPE, $_META_URL, $_META_SITE_NAME, $_META_LOCALE,
    $_META_TWITTER_SITE, $_META_THEME_COLOR, $_META_ROBOTS, $_META_CANONICAL,
    $_META_JSONLD, $_META_EXTRA,
    $_e_title, $_e_desc, $_e_image, $_e_image_w, $_e_image_h,
    $_e_url, $_e_site, $_e_locale, $_e_twitter, $_e_canonical, $_e_theme, $_e_robots
);
?>
