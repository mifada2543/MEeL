<?php
?>
<!-- ── FASE: DOWNLOAD ── -->
<div class="meel-phase" id="meel-phase-download">
  <div class="dl-icon-wrap">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round">
      <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
      <polyline points="7 10 12 15 17 10" />
      <line x1="12" y1="15" x2="12" y2="3" />
    </svg>
  </div>
  <div>
    <div class="meel-label" style="color:#3b82f6;margin-bottom:6px;">Mengunduh</div>
    <div id="meel-dl-url" style="font-size:11px;color:rgba(255,255,255,.28);letter-spacing:.04em;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
  </div>
  <div style="width:100%;">
    <div class="dl-track" style="margin-bottom:10px;">
      <div class="meel-bar" id="meel-dl-bar" style="background:#3b82f6"></div>
      <div class="meel-scan"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:10px;letter-spacing:.1em;color:rgba(255,255,255,.25);">
      <span id="meel-dl-pct" style="color:rgba(255,255,255,.5);">0%</span>
      <span id="meel-dl-eta"></span>
    </div>
  </div>
  <div class="dl-stats-grid">
    <div class="dl-stat">
      <div class="dl-stat-label">Ukuran</div>
      <div class="dl-stat-val" id="meel-dl-size">—</div>
    </div>
    <div class="dl-stat">
      <div class="dl-stat-label">Kecepatan</div>
      <div class="dl-stat-val" id="meel-dl-speed">—</div>
    </div>
    <div class="dl-stat">
      <div class="dl-stat-label">Fragmen</div>
      <div class="dl-stat-val" id="meel-dl-frag">—</div>
    </div>
  </div>
  <div style="font-size:10px;color:rgba(255,255,255,.15);letter-spacing:.08em;font-style:italic;">Jangan tutup tab ini selama proses berlangsung</div>
</div>
