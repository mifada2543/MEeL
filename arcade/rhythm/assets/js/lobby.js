/**
 * MEeL!Mania — Lobby & Song Select
 * Loads songs from API (songs.php) — built-in + custom
 * Displays per-beatmap scores from localStorage
 */
(function () {
  "use strict";

  /* ═══════════════════════════════════════════════════════
     STATE
     ═══════════════════════════════════════════════════════ */
  let allSongs = [];
  let selectedSong = null;
  let selectedSongData = null;
  let speedMult = 1.5;
  let settings = loadSettings();
  let scores = loadScores();
  let previewAudio = null;

  /* ═══════════════════════════════════════════════════════
     PERSISTENCE
     ═══════════════════════════════════════════════════════ */
  function loadSettings() {
    try {
      return JSON.parse(localStorage.getItem("mania_settings")) || {
        sfxVol: 70, bgmVol: 50, noteSize: "normal", bgDim: 80,
      };
    } catch (e) {
      return { sfxVol: 70, bgmVol: 50, noteSize: "normal", bgDim: 80 };
    }
  }
  function saveSettings() {
    try { localStorage.setItem("mania_settings", JSON.stringify(settings)); } catch (e) {}
  }
  function loadScores() {
    try { return JSON.parse(localStorage.getItem("mania_scores")) || {}; }
    catch (e) { return {}; }
  }
  function saveScores() {
    try { localStorage.setItem("mania_scores", JSON.stringify(scores)); } catch (e) {}
  }

  /* ═══════════════════════════════════════════════════════
     BACKGROUND ANIMATION
     ═══════════════════════════════════════════════════════ */
  const bgCanvas = document.getElementById("bgCanvas");
  const bgCtx = bgCanvas.getContext("2d");
  let particles = [];

  function resizeBg() {
    bgCanvas.width = window.innerWidth;
    bgCanvas.height = window.innerHeight;
  }

  function initParticles() {
    particles = [];
    const count = Math.min(60, Math.floor((bgCanvas.width * bgCanvas.height) / 18000));
    for (let i = 0; i < count; i++) {
      particles.push({
        x: Math.random() * bgCanvas.width,
        y: Math.random() * bgCanvas.height,
        r: Math.random() * 2 + 0.5,
        dx: (Math.random() - 0.5) * 0.3,
        dy: (Math.random() - 0.5) * 0.3,
        alpha: Math.random() * 0.4 + 0.1,
        hue: Math.random() * 60 + 300,
      });
    }
  }

  function drawBg() {
    bgCtx.clearRect(0, 0, bgCanvas.width, bgCanvas.height);
    for (const p of particles) {
      p.x += p.dx; p.y += p.dy;
      if (p.x < 0) p.x = bgCanvas.width;
      if (p.x > bgCanvas.width) p.x = 0;
      if (p.y < 0) p.y = bgCanvas.height;
      if (p.y > bgCanvas.height) p.y = 0;
      bgCtx.beginPath();
      bgCtx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      bgCtx.fillStyle = `hsla(${p.hue}, 80%, 65%, ${p.alpha})`;
      bgCtx.fill();
    }
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dx = particles[i].x - particles[j].x;
        const dy = particles[i].y - particles[j].y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 120) {
          bgCtx.beginPath();
          bgCtx.moveTo(particles[i].x, particles[i].y);
          bgCtx.lineTo(particles[j].x, particles[j].y);
          bgCtx.strokeStyle = `rgba(168, 85, 247, ${0.04 * (1 - dist / 120)})`;
          bgCtx.lineWidth = 0.5;
          bgCtx.stroke();
        }
      }
    }
    requestAnimationFrame(drawBg);
  }

  /* ═══════════════════════════════════════════════════════
     LOAD SONGS FROM API
     ═══════════════════════════════════════════════════════ */
  function loadSongs(sortKey) {
    // Songs already loaded from PHP via window.MANIA_SONGS
    if (window.MANIA_SONGS) {
      allSongs = window.MANIA_SONGS;
    }
    // Sort if needed
    if (sortKey === 'bpm') allSongs.sort((a, b) => b.bpm - a.bpm);
    else if (sortKey === 'difficulty') allSongs.sort((a, b) => b.difficulty - a.difficulty);
    renderSongs();
  }

  /* ═══════════════════════════════════════════════════════
     RENDER SONGS
     ═══════════════════════════════════════════════════════ */
  const songGrid = document.getElementById("songGrid");

  function renderSongs() {
    songGrid.innerHTML = "";
    for (const song of allSongs) {
      const card = document.createElement("div");
      card.className = "song-card" + (selectedSong === song.id ? " selected" : "");
      card.dataset.songId = song.id;

      const stars = Array.from({ length: 5 }, (_, i) =>
        `<span class="star${i < song.difficulty ? " filled" : ""}">★</span>`
      ).join("");

      const hs = scores[String(song.id)] || 0;
      const isCustom = song.type === "custom";
      const coverUrl = song.cover_url || `songs/${song.id}/cover.svg`;

      card.innerHTML = `
        <div class="song-top">
          <div class="song-cover" style="background: linear-gradient(135deg, ${song.color[0]}20, ${song.color[1]}20); border: 1px solid ${song.color[0]}30; overflow: hidden;">
            <img src="${coverUrl}" alt="${song.title}" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;" onerror="this.style.display='none';this.parentElement.innerHTML='<span>${song.emoji}</span>'" />
          </div>
          <div class="song-info">
            <div class="song-title">
              ${song.title}
              ${isCustom ? '<span class="meta-tag" style="background:rgba(34,211,238,0.1);color:#22d3ee;font-size:9px;margin-left:6px;">CUSTOM</span>' : ''}
            </div>
            <div class="song-artist">${song.artist}${isCustom ? ' · by ' + (song.username || 'Unknown') : ''}</div>
            <div class="song-meta">
              <span class="meta-tag meta-bpm">${song.bpm} BPM</span>
              <span class="meta-tag meta-notes">${song.note_count} notes</span>
              <span class="meta-tag" style="background:rgba(251,191,36,0.1);color:var(--accent-gold);">${song.difficulty_label || 'Normal'}</span>
              ${song.play_count > 0 ? `<span class="meta-tag" style="background:rgba(255,255,255,0.05);color:var(--text-muted);">▶ ${song.play_count}</span>` : ''}
            </div>
          </div>
        </div>
        <div class="song-bottom">
          <div class="song-stars">${stars}</div>
          <div class="song-highscore">HI <span class="hs-val">${hs > 0 ? String(hs).padStart(6, "0") : "------"}</span></div>
        </div>
      `;

      card.addEventListener("click", () => selectSong(song));
      songGrid.appendChild(card);
    }
  }

  function selectSong(song) {
    selectedSong = song.id;
    selectedSongData = song;
    document.querySelectorAll(".song-card").forEach((c) => {
      c.classList.toggle("selected", c.dataset.songId == song.id);
    });
    updatePlayButton();

    // Stop any preview
    if (previewAudio) { previewAudio.pause(); previewAudio = null; }
  }

  function updatePlayButton() {
    const btn = document.getElementById("btnPlay");
    if (selectedSong) {
      btn.disabled = false;
      btn.querySelector(".play-text").textContent = "MAIN SEKARANG";
    } else {
      btn.disabled = true;
      btn.querySelector(".play-text").textContent = "PILIH LAGU DULU";
    }
  }

  /* ═══════════════════════════════════════════════════════
     SPEED SELECTOR
     ═══════════════════════════════════════════════════════ */
  document.querySelectorAll(".speed-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".speed-btn").forEach((b) => b.classList.remove("selected"));
      btn.classList.add("selected");
      speedMult = parseFloat(btn.dataset.speed);
    });
  });

  /* ═══════════════════════════════════════════════════════
     SORT
     ═══════════════════════════════════════════════════════ */
  document.querySelectorAll(".sort-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".sort-btn").forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      loadSongs(btn.dataset.sort);
    });
  });

  /* ═══════════════════════════════════════════════════════
     SETTINGS PANEL
     ═══════════════════════════════════════════════════════ */
  const settingsPanel = document.getElementById("settingsPanel");
  document.getElementById("btnSettings").addEventListener("click", () => {
    settingsPanel.classList.remove("hidden");
    applySettingsUI();
  });
  document.getElementById("closeSettings").addEventListener("click", () => settingsPanel.classList.add("hidden"));
  settingsPanel.addEventListener("click", (e) => { if (e.target === settingsPanel) settingsPanel.classList.add("hidden"); });

  function applySettingsUI() {
    document.getElementById("sfxVolume").value = settings.sfxVol;
    document.getElementById("sfxVolumeVal").textContent = settings.sfxVol + "%";
    document.getElementById("bgmVolume").value = settings.bgmVol;
    document.getElementById("bgmVolumeVal").textContent = settings.bgmVol + "%";
    document.getElementById("bgDim").value = settings.bgDim;
    document.getElementById("bgDimVal").textContent = settings.bgDim + "%";
    document.querySelectorAll("[data-note-size]").forEach((b) => {
      b.classList.toggle("active", b.dataset.noteSize === settings.noteSize);
    });
  }

  document.getElementById("sfxVolume").addEventListener("input", (e) => {
    settings.sfxVol = parseInt(e.target.value);
    document.getElementById("sfxVolumeVal").textContent = settings.sfxVol + "%";
    saveSettings();
  });
  document.getElementById("bgmVolume").addEventListener("input", (e) => {
    settings.bgmVol = parseInt(e.target.value);
    document.getElementById("bgmVolumeVal").textContent = settings.bgmVol + "%";
    saveSettings();
  });
  document.getElementById("bgDim").addEventListener("input", (e) => {
    settings.bgDim = parseInt(e.target.value);
    document.getElementById("bgDimVal").textContent = settings.bgDim + "%";
    saveSettings();
  });
  document.querySelectorAll("[data-note-size]").forEach((btn) => {
    btn.addEventListener("click", () => {
      settings.noteSize = btn.dataset.noteSize;
      document.querySelectorAll("[data-note-size]").forEach((b) => {
        b.classList.toggle("active", b.dataset.noteSize === settings.noteSize);
      });
      saveSettings();
    });
  });
  document.getElementById("btnResetAll").addEventListener("click", () => {
    if (confirm("Hapus semua skor dan pengaturan?")) {
      localStorage.removeItem("mania_scores");
      localStorage.removeItem("mania_settings");
      scores = {};
      settings = { sfxVol: 70, bgmVol: 50, noteSize: "normal", bgDim: 80 };
      applySettingsUI();
      renderSongs();
      showToast("Semua data berhasil dihapus!");
    }
  });

  /* ═══════════════════════════════════════════════════════
     STATS PANEL
     ═══════════════════════════════════════════════════════ */
  const statsPanel = document.getElementById("statsPanel");
  document.getElementById("btnStats").addEventListener("click", () => {
    statsPanel.classList.remove("hidden");
    renderStats();
  });
  document.getElementById("closeStats").addEventListener("click", () => statsPanel.classList.add("hidden"));
  statsPanel.addEventListener("click", (e) => { if (e.target === statsPanel) statsPanel.classList.add("hidden"); });

  function renderStats() {
    const scoredSongs = allSongs.filter((s) => scores[String(s.id)]);
    const totalGames = scoredSongs.length;
    let totalScore = 0, bestSong = null, bestScore = 0;

    for (const s of allSongs) {
      const sc = scores[String(s.id)] || 0;
      totalScore += sc;
      if (sc > bestScore) { bestScore = sc; bestSong = s; }
    }

    let html = '<div class="stat-grid">';
    html += '<div class="stat-card"><div class="stat-card-label">Lagu Dimainkan</div><div class="stat-card-value pink">' + totalGames + ' / ' + allSongs.length + '</div></div>';
    html += '<div class="stat-card"><div class="stat-card-label">Total Skor</div><div class="stat-card-value purple">' + totalScore.toLocaleString() + '</div></div>';
    const hsStr = bestScore > 0 ? String(bestScore).padStart(6, '0') : '0';
    html += '<div class="stat-card"><div class="stat-card-label">Skor Tertinggi</div><div class="stat-card-value gold">' + hsStr + '</div></div>';
    const bsStr = bestSong ? (bestSong.emoji + ' ' + bestSong.title) : '—';
    html += '<div class="stat-card"><div class="stat-card-label">Lagu Terbaik</div><div class="stat-card-value cyan" style="font-size:14px;">' + bsStr + '</div></div>';
    html += '</div>';
    html += '<div class="stat-songs">Skor per Beatmap</div>';
    const songRows = allSongs.filter((s) => scores[String(s.id)]);
    if (songRows.length > 0) {
      for (const s of songRows) {
        const sc = String(scores[String(s.id)]).padStart(6, '0');
        html += '<div class="stat-song-row"><span class="stat-song-name">' + s.emoji + ' ' + s.title + '</span><span class="stat-song-score">' + sc + '</span></div>';
      }
    } else {
      html += '<p class="empty-text">Belum ada skor.</p>';
    }
    document.getElementById('statsBody').innerHTML = html;
  }

  /* ═══════════════════════════════════════════════════════
     PLAY → Navigate to Game
     ═══════════════════════════════════════════════════════ */
  document.getElementById("btnPlay").addEventListener("click", () => {
    if (!selectedSongData) return;
    const params = new URLSearchParams({
      id: selectedSongData.id,
      type: selectedSongData.type || "builtin",
      speed: speedMult,
    });
    window.location.href = `game?${params.toString()}`;
  });

  /* ═══════════════════════════════════════════════════════
     TOAST
     ═══════════════════════════════════════════════════════ */
  function showToast(msg) {
    const t = document.getElementById("toast");
    t.textContent = msg;
    t.classList.remove("hidden");
    t.classList.add("show");
    setTimeout(() => { t.classList.remove("show"); setTimeout(() => t.classList.add("hidden"), 300); }, 2500);
  }

  function getCurrentSort() {
    const active = document.querySelector(".sort-btn.active");
    return active ? active.dataset.sort : "default";
  }

  /* ═══════════════════════════════════════════════════════
     INIT
     ═══════════════════════════════════════════════════════ */
  async function init() {
    resizeBg();
    initParticles();
    drawBg();
    window.addEventListener("resize", () => { resizeBg(); initParticles(); });

    await loadSongs("default");

    // Receive score back from game page
    try {
      const params = new URLSearchParams(window.location.search);
      const scoreBack = params.get("score");
      const songBack = params.get("song");
      if (scoreBack && songBack) {
        const sc = parseInt(scoreBack);
        if (!isNaN(sc)) {
          if (!scores[songBack] || sc > scores[songBack]) {
            scores[songBack] = sc;
            saveScores();
            renderSongs();
            showToast("Skor baru tersimpan! 🎉");
          }
        }
        window.history.replaceState({}, "", window.location.pathname);
      }
    } catch (e) {}
  }

  // Force reload on bfcache (back/forward navigation)
  window.addEventListener("pageshow", (e) => {
    if (e.persisted) {
      window.location.reload();
    }
  });

  init();
})();
