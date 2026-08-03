/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
/* ────────────────────────────────────────────────────────────────
 * shared/htmx-lucide.js — Inisialisasi ikon Lucide + re-init setelah
 * diduplikasi di assets/js/music/shared & assets/js/video/shared).
 * ──────────────────────────────────────────────────────────────── */
lucide.createIcons();
document.body.addEventListener('htmx:afterOnLoad', function(e) {
    lucide.createIcons({}, e.detail?.target || document.body);
});
