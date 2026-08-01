/* ============================================================
 * library-ui.js — UI interaksi halaman Music Library (index.php):
 * klik item musik (.music-item), navigasi playlist (loadPlaylistById,
 * setActivePlaylist, loadPlaylistMobile), dropdown artist/playlist
 * (desktop & mobile), auto-scroll sidebar, dan boot handler.
 * Depends on: shared/utils.js, shared/mini-player.js, htmx, lucide
 * ============================================================ */

// ── Setup klik item musik di library (dimuat via HTMX) ──────────────
function setupMusicItemClicks() {
    const allItems = () => Array.from(document.querySelectorAll('.music-item'));
    document.querySelectorAll('.music-item').forEach(item => {
        if (item.dataset.listenerAdded) return;
        item.dataset.listenerAdded = 'true';
        item.addEventListener('click', function(e) {
            // Jika klik pada tombol download/share, abaikan
            if (e.target.closest('.no-player')) return;

            // Tandai sessionStorage agar tidak men-trigger modal resume di watch.php
            sessionStorage.setItem('skip_resume_once', 'true');

            // Cari indeks lagu ini dan tentukan nextSongUrl dari lagu berikutnya
            const items = allItems();
            const idx = items.indexOf(this);
            let nextSongUrl = '';
            if (idx >= 0 && idx < items.length - 1) {
                const nextItem = items[idx + 1];
                const nextId = nextItem.dataset.id;
                if (nextId) nextSongUrl = `watch.php?id=${nextId}`;
            }

            // Hapus playlist context saat user klik lagu dari library biasa
            localStorage.removeItem('meel_last_playlist_id');

            const state = {
                id: this.dataset.id,
                musicId: this.dataset.id,
                title: this.dataset.title,
                artist: this.dataset.artist,
                thumbnail: this.dataset.thumbnail,
                thumbnailUrl: this.dataset.thumbnailUrl || `upload/thumbnail/${this.dataset.thumbnail}`,
                filename: this.dataset.filename,
                watchUrl: e.target.closest('a') ? e.target.closest('a').getAttribute('href') : `watch.php?id=${this.dataset.id}`,
                nextSongUrl: nextSongUrl,
                currentTime: 0,
                isPlaying: true,
            };
            loadAudio(state, true);
            updateIndexUI();
            sessionStorage.setItem('meel_audio_state', JSON.stringify(state));
            // Tampilkan mini player
            isMiniPlayerIndexActive = true;
            miniPlayerIndex.classList.add('active');
        });
    });
}

// ── Muat konten playlist (fetch → replace <main>) ───────────────────
function loadPlaylistById(id) {
    if (!id) return;

    // Simpan state load-more SEBELUM replace <main>
    var savedLMUrl = null;
    var savedBtn = document.getElementById('load-more-btn');
    if (savedBtn) savedLMUrl = savedBtn.getAttribute('hx-get');

    fetch('view_playlist.php?id=' + id + '&content_only=1')
        .then(function(r) {
            return r.text();
        })
        .then(function(html) {
            var main = document.querySelector('main');
            if (!main) return;
            main.innerHTML = html;
            setActivePlaylist(id);
            if (typeof history !== 'undefined') {
                history.pushState(null, '', 'view_playlist.php?id=' + id);
            }
            if (typeof lucide !== 'undefined') lucide.createIcons();

            // Pulihkan load-more URL setelah replace <main>
            if (savedLMUrl) {
                var newBtn = document.getElementById('load-more-btn');
                if (newBtn) newBtn.setAttribute('hx-get', savedLMUrl);
            }

            if (typeof htmx !== 'undefined') htmx.process(main);
            if (typeof setupPlaylistItemClicks === 'function') setupPlaylistItemClicks();
        })
        .catch(function(err) {
            console.warn('Gagal load playlist:', err);
        });
}

// ── Boot & Perbaikan Sinkronisasi ──
function bootPlayerIndex() {
    initMiniPlayerIndex();
    setupMusicItemClicks();
    scrollToActiveArtistDesktop();
}

// ── Auto-scroll sidebar desktop ke artist yg aktif ────────────────
// Setiap kali HTMX mengganti #library-container, sidebar artist
// dibuat ulang oleh server dan scroll-nya reset ke 0.
function scrollToActiveArtistDesktop() {
    var artistList = document.getElementById('desktop-artist-list');
    if (!artistList) return;
    var activeItem = artistList.querySelector('.sidebar-link.active');
    if (activeItem) {
        activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// ── Mobile Playlist Dropdown (custom toggle) ──
window.togglePlaylistDropdown = function() {
    const dropdown = document.getElementById('playlist-options');
    if (dropdown) {
        const isHidden = dropdown.classList.contains('hidden');
        if (isHidden) {
            dropdown.classList.remove('hidden');
            document.body.classList.add('artist-dropdown-active');
        } else {
            dropdown.classList.add('hidden');
            setTimeout(function() {
                document.body.classList.remove('artist-dropdown-active');
            }, 350);
        }
    }
};

window.closePlaylistDropdown = function() {
    const dropdown = document.getElementById('playlist-options');
    if (dropdown) dropdown.classList.add('hidden');
    setTimeout(function() {
        const artistStillOpen = document.getElementById('artist-options') && !document.getElementById('artist-options').classList.contains('hidden');
        const playlistStillOpen = document.getElementById('playlist-options') && !document.getElementById('playlist-options').classList.contains('hidden');
        if (!artistStillOpen && !playlistStillOpen) {
            document.body.classList.remove('artist-dropdown-active');
        }
    }, 350);
};

window.navigateToPlaylistMobile = function(id) {
    closePlaylistDropdown();
    loadPlaylistMobile(id);
    // Update label
    var label = document.getElementById('playlist-dropdown-label');
    var activeBtn = document.querySelector('#playlist-options button[data-playlist-id="' + id + '"]');
    if (label && activeBtn) label.textContent = activeBtn.textContent.trim();
};

// Close custom dropdown ketika klik di luar
document.addEventListener('click', (e) => {
    const artistDropdown = document.getElementById('artist-options');
    const artistTrigger = e.target.closest('#custom-artist-dropdown');
    if (!artistTrigger && artistDropdown && !artistDropdown.classList.contains('hidden')) {
        closeArtistDropdown();
    }
    const playlistDropdown = document.getElementById('playlist-options');
    const playlistTrigger = e.target.closest('#custom-playlist-dropdown');
    if (!playlistTrigger && playlistDropdown && !playlistDropdown.classList.contains('hidden')) {
        closePlaylistDropdown();
    }
});

window.toggleArtistDropdown = function() {
    const dropdown = document.getElementById('artist-options');
    if (dropdown) {
        const isHidden = dropdown.classList.contains('hidden');
        if (isHidden) {
            dropdown.classList.remove('hidden');
            document.body.classList.add('artist-dropdown-active');

            // Auto-scroll ke artist yg sedang dipilih (highlight)
            var activeItem = dropdown.querySelector('.text-orange-500');
            if (activeItem) {
                activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        } else {
            dropdown.classList.add('hidden');
            setTimeout(function() {
                document.body.classList.remove('artist-dropdown-active');
            }, 350);
        }
    }
};

window.closeArtistDropdown = function() {
    const dropdown = document.getElementById('artist-options');
    if (dropdown) dropdown.classList.add('hidden');
    setTimeout(() => {
        const artistStillOpen = document.getElementById('artist-options') && !document.getElementById('artist-options').classList.contains('hidden');
        const playlistStillOpen = document.getElementById('playlist-options') && !document.getElementById('playlist-options').classList.contains('hidden');
        if (!artistStillOpen && !playlistStillOpen) {
            document.body.classList.remove('artist-dropdown-active');
        }
    }, 350);
};

// ── Fungsi navigasi playlist ───────────────────────────────────────
function setActivePlaylist(id) {
    document.querySelectorAll('.pl-link').forEach(function(el) {
        if (el.dataset.playlistId == id) {
            el.classList.add('active');
            el.style.color = '#f97316';
            el.style.background = 'rgba(249,115,22,.08)';
            el.style.borderColor = 'rgba(249,115,22,.15)';
        } else {
            el.classList.remove('active');
            el.style.color = '';
            el.style.background = '';
            el.style.borderColor = '';
        }
    });
}

window.loadPlaylistMobile = function(id) {
    if (!id) return;
    htmx.ajax('GET', 'view_playlist.php?id=' + id + '&content_only=1', {
        target: 'main',
        swap: 'innerHTML',
        pushUrl: 'view_playlist.php?id=' + id
    });
    setActivePlaylist(id);
};

window.resetActivePlaylist = function() {
    document.querySelectorAll('.pl-link').forEach(function(el) {
        el.classList.remove('active');
        el.style.color = '';
        el.style.background = '';
        el.style.borderColor = '';
    });
};

window.resetArtistHighlight = function() {
    document.querySelectorAll('#desktop-artist-list .sidebar-link').forEach(function(el) {
        el.classList.remove('active');
    });
    var allArtistBtns = document.querySelectorAll('#artist-options button');
    allArtistBtns.forEach(function(btn) {
        btn.classList.remove('text-orange-500', 'font-bold');
    });
};
