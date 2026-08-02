/* ============================================================
 * mini-player.js — Mini player (Spotify-style) yang dipakai
 * BERSAMA oleh music/index.php & music/view_playlist.php.
 * Satu sumber kebenaran: state lintas halaman lewat
 * sessionStorage 'meel_audio_state' + localStorage 'meel_global_loop'.
 *
 * Fungsi khusus halaman (setupMusicItemClicks, loadPlaylistById,
 * dropdown library) TIDAK ada di sini — ada di
 * index/library-ui.js & view_playlist/view_playlist.js.
 * Depends on: shared/format-time.js (formatTime), shared/keyboard.js
 * (meelKeyShortcutIgnored), htmx (window.htmx), lucide
 * ============================================================ */

const miniPlayerIndex = document.getElementById('mini-player-index');
let audioPlayer = null;
let isMiniPlayerIndexActive = false;
let currentState = null; // state object aktif

// --- Helpers ---
function saveIndexState() {
    if (!currentState || !audioPlayer) return;
    currentState.currentTime = audioPlayer.currentTime;
    currentState.isPlaying = !audioPlayer.paused;
    currentState.isLooping = isMiniLoopIndexActive;
    sessionStorage.setItem('meel_audio_state', JSON.stringify(currentState));
}

// --- Buat / ganti audio element ---
function loadAudio(state, autoplay) {
    if (!audioPlayer) {
        audioPlayer = document.createElement('audio');
        audioPlayer.id = 'hidden-audio-player';
        // preload=none hindari loading FLAC yg berat saat pertama kali play
        audioPlayer.preload = 'none';
        document.body.appendChild(audioPlayer);

        audioPlayer.addEventListener('timeupdate', updateIndexProgress);
        audioPlayer.addEventListener('play', () => setPlayIcon('pause'));
        audioPlayer.addEventListener('pause', () => setPlayIcon('play'));
        audioPlayer.addEventListener('ended', () => miniNextIndex());
    }

    // Hindari memuat ulang audio jika lagu yang sama sedang dimainkan
    if (currentState && currentState.filename === state.filename) {
        return;
    }

    currentState = state;
    // Set src langsung memicu loading — tidak perlu panggil .load()
    // (memanggil .load() setelah .src malah restart loading, double-load untuk file besar)
    audioPlayer.src = `stream.php?id=${state.id}`;

    // Restore loop state dari global key (sumber kebenaran) + state object sebagai fallback
    const _gLoop = localStorage.getItem("meel_global_loop") === "true";
    if (state.isLooping !== undefined && state.isLooping !== _gLoop) {
        // state lebih baru (misalnya baru di-toggle di watch.php) — update global
        isMiniLoopIndexActive = state.isLooping;
        localStorage.setItem("meel_global_loop", String(state.isLooping));
    } else {
        isMiniLoopIndexActive = _gLoop;
    }
    audioPlayer.loop = isMiniLoopIndexActive;
    updateMiniLoopUIIndex();

    if (autoplay) {
        audioPlayer.currentTime = state.currentTime || 0;
        audioPlayer.play().catch(() => {});
    }
}

// --- Update seluruh UI ---
// Elemen di-cache sekali (markup mini-player-index statis, tidak
// pernah di-swap oleh htmx), jadi tidak perlu getElementById lagi
// di setiap tick timeupdate.
let _idxEls = null;

function _getIdxEls() {
    if (!_idxEls) {
        _idxEls = {
            fill: document.getElementById('mp-seekbar-fill-index'),
            thumb: document.getElementById('mp-seekbar-thumb-index'),
            ct: document.getElementById('mini-current-time-index'),
            dt: document.getElementById('mini-duration-index'),
            img: document.getElementById('mini-thumbnail-index'),
            title: document.getElementById('mini-title-index'),
            artist: document.getElementById('mini-artist-index'),
        };
    }
    return _idxEls;
}

// Bagian "panas": dipanggil di setiap event timeupdate (bisa puluhan
// kali/detik), jadi hanya menyentuh seekbar + label waktu.
function updateIndexProgress() {
    if (!audioPlayer) return;
    const els = _getIdxEls();
    const pct = audioPlayer.duration > 0 ?
        (audioPlayer.currentTime / audioPlayer.duration) * 100 : 0;

    if (els.fill) els.fill.style.width = pct + '%';
    if (els.thumb) els.thumb.style.left = pct + '%';
    if (els.ct) els.ct.textContent = formatTime(audioPlayer.currentTime);
    if (els.dt) els.dt.textContent = formatTime(audioPlayer.duration);
}

// Bagian "dingin": thumbnail/judul/artis hanya berubah saat lagu
// berganti, jadi dipisah agar tidak ikut ditulis ulang tiap tick.
function updateIndexMeta() {
    if (!currentState) return;
    const els = _getIdxEls();
    if (els.img) els.img.src = currentState.thumbnailUrl || `upload/thumbnail/${currentState.thumbnail}`;
    if (els.title) els.title.textContent = currentState.title || 'Unknown';
    if (els.artist) els.artist.textContent = currentState.artist || 'Unknown';
}

function updateIndexUI() {
    if (!audioPlayer || !currentState) return;
    updateIndexProgress();
    updateIndexMeta();
}

function setPlayIcon(icon) {
    const btn = document.getElementById('mini-play-btn-index');
    if (btn) {
        btn.innerHTML = `<i data-lucide="${icon}" style="width:18px;height:18px;"></i>`;
        lucide.createIcons();
    }
}

// --- Init: baca sessionStorage ---
function initMiniPlayerIndex() {
    const miniPlayerBar = document.getElementById('mini-player-index');
    if (miniPlayerBar) {
        miniPlayerBar.style.cursor = 'default';
        miniPlayerBar.addEventListener('click', (e) => {
            if (e.target.closest('.mp-thumbnail') || e.target.closest('#mini-player-img') || e.target.tagName === 'IMG') {
                expandPlayerFromMiniPlayer();
            }
        });
    }
    // Selalu apply global loop key ke UI saat init (bahkan jika tidak ada audio state)
    isMiniLoopIndexActive = localStorage.getItem("meel_global_loop") === "true";
    updateMiniLoopUIIndex();

    const raw = sessionStorage.getItem('meel_audio_state');
    if (!raw) return;
    try {
        const state = JSON.parse(raw);
        isMiniPlayerIndexActive = true;

        // Update meta IMMEDIATELY dari state (sebelum setTimeout) agar
        // title/artist/thumbnail tampil tanpa flash default "Tidak ada musik".
        const els = _getIdxEls();
        if (els.img) els.img.src = state.thumbnailUrl || `upload/thumbnail/${state.thumbnail}`;
        if (els.title) els.title.textContent = state.title || 'Unknown';
        if (els.artist) els.artist.textContent = state.artist || 'Unknown';

        // Tunggu render selesai dulu baru load audio (hindari blocking saat navigasi dari watch.php dengan FLAC)
        setTimeout(() => {
            loadAudio(state, state.isPlaying);
            updateIndexUI();
        }, 100);
        // Prioritaskan global loop key; sinkronisasi dari sessionStorage jika lebih baru
        const globalLoop = localStorage.getItem("meel_global_loop") === "true";
        if (state.isLooping !== undefined) {
            // Sinkronisasi: jika state dan global berbeda, global key menang
            isMiniLoopIndexActive = globalLoop;
            // Tapi jika state.isLooping berbeda dari global, update global dari state
            // (kasus: toggle di watch.php baru saja terjadi)
            if (state.isLooping !== globalLoop) {
                isMiniLoopIndexActive = state.isLooping;
                localStorage.setItem("meel_global_loop", String(state.isLooping));
            }
        } else {
            isMiniLoopIndexActive = globalLoop;
        }
        if (audioPlayer) audioPlayer.loop = isMiniLoopIndexActive;
        updateMiniLoopUIIndex();
        miniPlayerIndex.classList.add('active');

        // Muat konten playlist — hanya jika halaman menyediakan loadPlaylistById
        // (index/library-ui.js). view_playlist.php TIDAK memanggil ini.
        if (!window._playlistLoaded && typeof loadPlaylistById === 'function') {
            var plId = state.playlistId;
            if (!plId || plId <= 0) {
                var lastPl = localStorage.getItem('meel_last_playlist_id');
                plId = lastPl ? parseInt(lastPl) : 0;
            }
            if (!plId || plId <= 0) {
                plId = parseInt(window.MEEL_INDEX_CONFIG?.playlistId || 0);
            }
            if (plId > 0) {
                window._playlistLoaded = true;
                loadPlaylistById(plId);
            }
        }
    } catch (e) {
        console.warn('Mini player init error:', e);
    }
}

// --- Play / Pause ---
window.miniPlayPauseIndex = function() {
    if (!audioPlayer) return;
    // Jeda kesehatan (20-20-20) aktif → jangan izinkan memulai pemutaran baru.
    if (window.meelHealthAlertActive && audioPlayer.paused) return;
    audioPlayer.paused ? audioPlayer.play() : audioPlayer.pause();
};

// --- Seek ---
window.miniSeekIndex = function(event) {
    if (!audioPlayer) return;
    const rect = event.currentTarget.getBoundingClientRect();
    const pct = (event.clientX - rect.left) / rect.width;
    audioPlayer.currentTime = Math.max(0, Math.min(pct * audioPlayer.duration, audioPlayer.duration));
};

// --- Next: Cari lagu berikutnya di DOM (termasuk playlist items) ---
window.miniNextIndex = function() {
    if (!audioPlayer) return;
    // Jeda kesehatan (20-20-20) aktif → tolak auto-next / pindah lagu.
    if (window.meelHealthAlertActive) return;
    if (audioPlayer.loop) return;
    if (currentState && currentState.filename) {
        const allItems = Array.from(document.querySelectorAll('.music-item, .music-pl-item'));
        const idx = allItems.findIndex(el => el.dataset.filename === currentState.filename);
        if (idx !== -1 && idx < allItems.length - 1) {
            allItems[idx + 1].click();
            return;
        }
    }
    audioPlayer.currentTime = 0;
    audioPlayer.pause();
    const btn = document.getElementById('mini-play-btn-index');
    if (btn) {
        btn.innerHTML = `<i data-lucide="play" style="width:18px;height:18px;"></i>`;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
};
// --- Prev: restart jika > 3 detik, else coba lagu sebelumnya ---
window.miniPrevIndex = function() {
    if (!audioPlayer) return;
    if (audioPlayer.currentTime > 3) {
        audioPlayer.currentTime = 0;
        return;
    }
    // Cari lagu sebelumnya di DOM
    if (currentState && currentState.filename) {
        const allItems = Array.from(document.querySelectorAll('.music-item, .music-pl-item'));
        const idx = allItems.findIndex(el => el.dataset.filename === currentState.filename);
        if (idx > 0) {
            allItems[idx - 1].click();
            return;
        }
    }
    audioPlayer.currentTime = 0;
};

function expandPlayerFromMiniPlayer() {
    // Simpan detik terakhir dulu
    saveIndexState();

    // Tandai agar watch.php tidak menampilkan resume modal
    sessionStorage.setItem('skip_resume_once', 'true');

    // Ambil data state terakhir untuk mendapatkan ID lagu atau URL-nya
    const savedState = sessionStorage.getItem('meel_audio_state');
    if (savedState) {
        const state = JSON.parse(savedState);

        // Pengecekan untuk membaca 'id' maupun 'musicId'
        if (state.watchUrl) {
            window.location.href = state.watchUrl;
        } else if (state.id) {
            window.location.href = `watch.php?id=${state.id}`;
        } else if (state.musicId) {
            window.location.href = `watch.php?id=${state.musicId}`;
        } else {
            // Fallback jika tidak ada ID
            const fallbackItem = document.querySelector(`[data-filename="${state.filename}"]`);
            if (fallbackItem && fallbackItem.closest('a')) {
                window.location.href = fallbackItem.closest('a').getAttribute('href');
            }
        }
    }
}

// --- Loop toggle untuk mini player ---
// Inisialisasi dari global key agar konsisten dengan watch.php
let isMiniLoopIndexActive = localStorage.getItem("meel_global_loop") === "true";

window.toggleMiniLoopIndex = function() {
    isMiniLoopIndexActive = !isMiniLoopIndexActive;
    // Simpan ke global key — ini sumber kebenaran tunggal untuk loop state
    localStorage.setItem("meel_global_loop", String(isMiniLoopIndexActive));
    if (audioPlayer) audioPlayer.loop = isMiniLoopIndexActive;
    updateMiniLoopUIIndex();
    // Sinkronisasi loop state ke sessionStorage juga
    saveIndexState();
};

function updateMiniLoopUIIndex() {
    const btn = document.getElementById('mini-loop-btn-index');
    if (!btn) return;
    if (isMiniLoopIndexActive) {
        btn.style.color = '#f97316';
        btn.style.opacity = '1';
    } else {
        btn.style.color = '';
        btn.style.opacity = '0.5';
    }
}

// --- Tutup ---
window.closeMiniPlayerIndex = function() {
    if (audioPlayer) audioPlayer.pause();
    miniPlayerIndex.classList.remove('active');
    sessionStorage.removeItem('meel_audio_state');
    isMiniPlayerIndexActive = false;
    currentState = null;
};

// ── Setup klik untuk playlist items (dipakai index & view_playlist) ──────────
function setupPlaylistItemClicks() {
    document.querySelectorAll('.music-pl-item').forEach(function(item) {
        if (item.dataset.plListenerAdded) return;
        item.dataset.plListenerAdded = 'true';

        item.addEventListener('click', function(e) {
            // Abaikan klik pada form hapus atau link
            if (e.target.closest('form') || e.target.closest('a')) return;
            e.preventDefault();

            sessionStorage.setItem('skip_resume_once', 'true');

            var allItems = Array.from(document.querySelectorAll('.music-pl-item'));
            var idx = allItems.indexOf(this);
            var nextSongUrl = '';
            if (idx >= 0 && idx < allItems.length - 1) {
                nextSongUrl = allItems[idx + 1].dataset.watchUrl || '';
            }

            var state = {
                id: this.dataset.id,
                musicId: this.dataset.id,
                title: this.dataset.title,
                artist: this.dataset.artist,
                thumbnail: this.dataset.thumbnail,
                thumbnailUrl: this.dataset.thumbnailUrl || `upload/thumbnail/${this.dataset.thumbnail}`,
                filename: this.dataset.filename,
                watchUrl: this.dataset.watchUrl || `watch.php?id=${this.dataset.id}&playlist_id=${this.dataset.playlistId}`,
                nextSongUrl: nextSongUrl,
                playlistId: this.dataset.playlistId,
                currentTime: 0,
                isPlaying: true,
            };
            loadAudio(state, true);
            updateIndexUI();
            sessionStorage.setItem('meel_audio_state', JSON.stringify(state));
            isMiniPlayerIndexActive = true;
            miniPlayerIndex.classList.add('active');
        });

        // Tombol play (ikon play di kolom nomor)
        var playBtn = item.querySelector('.pl-play-btn');
        if (playBtn) {
            playBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                item.click();
            });
        }
    });
}

// Keyboard shortcuts mini player (halaman library & playlist)
document.addEventListener('keydown', (e) => {
    // Guard: input/textarea, modifier (Ctrl/Alt/Meta), auto-repeat —
    // kini di shared/keyboard.js (meelKeyShortcutIgnored)
    if (window.meelKeyShortcutIgnored?.(e)) return;

    const key = e.key.toLowerCase();

    // Keyboard 'i' → Pindah kembali ke full player (watch.php)
    if (key === 'i') {
        e.preventDefault();
        expandPlayerFromMiniPlayer();
    }

    // Keyboard 'l' → Toggle loop mini player
    if (key === 'l') {
        e.preventDefault();
        window.toggleMiniLoopIndex();
    }
});

// Auto-save tiap 5 detik
setInterval(() => {
    if (isMiniPlayerIndexActive) saveIndexState();
}, 5000);
