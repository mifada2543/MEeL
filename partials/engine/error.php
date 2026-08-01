<?php
/**
 * partials/engine/error.php — Fase ERROR overlay MEeL Engine.
 * Di-include oleh partials/ui.php (assembler).
 * Styling: assets/css/engine/ (modul buttons.css).
 */
?>
<!-- ── FASE: ERROR ── -->
<div class="meel-phase" id="meel-phase-error">
  <div class="meel-icon-wrap" style="background:rgba(239,68,68,.09);border:0.5px solid rgba(239,68,68,.28)">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round">
      <line x1="18" y1="6" x2="6" y2="18" />
      <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
  </div>
  <div class="meel-label" style="color:#ef4444">Proses Gagal</div>
  <div id="meel-error-log" style="width:100%;background:rgba(239,68,68,.06);border:0.5px solid rgba(239,68,68,.18);
       border-radius:6px;padding:10px 12px;max-height:80px;overflow:auto;
       font-size:9px;color:rgba(239,68,68,.65);text-align:left;line-height:1.65;
       white-space:pre-wrap;word-break:break-all"></div>
  <div style="display:flex;gap:10px;margin-top:2px;flex-wrap:wrap;justify-content:center">
    <a href="upload_advanced.php" class="meel-nav-btn" style="color:#ef4444;border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.07)">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="1 4 1 10 7 10" />
        <path d="M3.51 15a9 9 0 1 0 .49-3.28" />
      </svg>
      Coba Lagi
    </a>
    <a href="index.php" class="meel-nav-btn" style="color:rgba(255,255,255,.4);border-color:rgba(255,255,255,.1);background:rgba(255,255,255,.04)">
      Beranda
    </a>
  </div>
</div>
