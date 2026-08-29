/* library-ui.js — UI interaksi halaman Music Library (index.php): */
// Setup klik item musik di library (dimuat via HTMX)
function setupMusicItemClicks() {
  const allItems = () => Array.from(document.querySelectorAll(".music-item"));
  document.querySelectorAll(".music-item").forEach((item) => {
    if (item.dataset.listenerAdded) return;
    item.dataset.listenerAdded = "true";
    item.addEventListener("click", function (e) {
      if (e.target.closest(".no-player")) return;
      sessionStorage.setItem(MEEL_KEYS.SKIP_RESUME_ONCE, "true");
      const items = allItems();
      const idx = items.indexOf(this);
      let nextSongUrl = "";
      if (idx >= 0 && idx < items.length - 1) {
        const nextItem = items[idx + 1];
        const nextId = nextItem.dataset.id;
        if (nextId) nextSongUrl = `watch?id=${nextId}`;
      }
      localStorage.removeItem(MEEL_KEYS.LAST_PLAYLIST_ID);
      const state = {
        id: this.dataset.id,
        musicId: this.dataset.id,
        title: this.dataset.title,
        artist: this.dataset.artist,
        thumbnail: this.dataset.thumbnail,
        thumbnailUrl:
          this.dataset.thumbnailUrl ||
          `upload/thumbnail/${this.dataset.thumbnail}`,
        filename: this.dataset.filename,
        watchUrl: e.target.closest("a")
          ? e.target.closest("a").getAttribute("href")
          : `watch?id=${this.dataset.id}`,
        nextSongUrl: nextSongUrl,
        currentTime: 0,
        isPlaying: true,
      };
      loadAudio(state, true);
      updateIndexUI();
      sessionStorage.setItem(MEEL_KEYS.AUDIO_STATE, JSON.stringify(state));
      isMiniPlayerIndexActive = true;
      getMiniPlayerIndexEl()?.classList.add("active");
    });
  });
}
// Muat konten playlist
function loadPlaylistById(id) {
  if (!id) return;
  var savedLMUrl = null;
  var savedBtn = document.getElementById("load-more-btn");
  if (savedBtn) savedLMUrl = savedBtn.getAttribute("hx-get");
  var plEl = document.querySelector('[data-playlist-id="' + id + '"]');
  var url =
    plEl && plEl.dataset.playlistUrl
      ? plEl.dataset.playlistUrl
      : "playlist?id=" + id;
  var sep = url.indexOf("?") === -1 ? "?" : "&";
  fetch(url + sep + "content_only=1")
    .then(function (r) {
      return r.text();
    })
    .then(function (html) {
      var main = document.querySelector("main");
      if (!main) return;
      main.innerHTML = html;
      setActivePlaylist(id);
      if (typeof resetLibraryFilters === "function") resetLibraryFilters();
      if (typeof window.meelSyncViewTitle === "function")
        window.meelSyncViewTitle();
      if (typeof history !== "undefined") {
        history.pushState(null, "", url);
      }
      if (typeof lucide !== "undefined") lucide.createIcons();
      if (savedLMUrl) {
        var newBtn = document.getElementById("load-more-btn");
        if (newBtn) newBtn.setAttribute("hx-get", savedLMUrl);
      }
      if (typeof htmx !== "undefined") htmx.process(main);
      if (typeof setupPlaylistItemClicks === "function")
        setupPlaylistItemClicks();
    })
    .catch(function (err) {
      console.warn("Gagal load playlist:", err);
    });
}
// Boot & Perbaikan Sinkronisasi
function bootPlayerIndex() {
  initMiniPlayerIndex();
  setupMusicItemClicks();
  scrollToActiveArtistDesktop();
}
// Auto-scroll sidebar desktop ke artist yg aktif
function scrollToActiveArtistDesktop() {
  var artistList = document.getElementById("desktop-artist-list");
  if (!artistList) return;
  var activeItem = artistList.querySelector(".sidebar-link.active");
  if (activeItem) {
    activeItem.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }
}
// Mobile Playlist Dropdown (custom toggle)
window.togglePlaylistDropdown = function () {
  const dropdown = document.getElementById("playlist-options");
  if (dropdown) {
    const isHidden = dropdown.classList.contains("hidden");
    if (isHidden) {
      // Tutup dropdown artist jika sedang terbuka
      var artistDrop = document.getElementById("artist-options");
      if (artistDrop && !artistDrop.classList.contains("hidden")) {
        artistDrop.classList.add("hidden");
      }
      dropdown.classList.remove("hidden");
      document.body.classList.add("artist-dropdown-active");
    } else {
      dropdown.classList.add("hidden");
      setTimeout(function () {
        document.body.classList.remove("artist-dropdown-active");
      }, 350);
    }
  }
};
window.closePlaylistDropdown = function () {
  const dropdown = document.getElementById("playlist-options");
  if (dropdown) dropdown.classList.add("hidden");
  setTimeout(function () {
    const artistStillOpen =
      document.getElementById("artist-options") &&
      !document.getElementById("artist-options").classList.contains("hidden");
    const playlistStillOpen =
      document.getElementById("playlist-options") &&
      !document.getElementById("playlist-options").classList.contains("hidden");
    if (!artistStillOpen && !playlistStillOpen) {
      document.body.classList.remove("artist-dropdown-active");
    }
  }, 350);
};
window.navigateToPlaylistMobile = function (id) {
  closePlaylistDropdown();
  loadPlaylistMobile(id);
  var label = document.getElementById("playlist-dropdown-label");
  var activeBtn = document.querySelector(
    '#playlist-options button[data-playlist-id="' + id + '"]',
  );
  if (label && activeBtn) label.textContent = activeBtn.textContent.trim();
};
document.addEventListener("click", (e) => {
  const artistDropdown = document.getElementById("artist-options");
  const artistTrigger = e.target.closest("#custom-artist-dropdown");
  if (
    !artistTrigger &&
    artistDropdown &&
    !artistDropdown.classList.contains("hidden")
  ) {
    closeArtistDropdown();
  }
  const playlistDropdown = document.getElementById("playlist-options");
  const playlistTrigger = e.target.closest("#custom-playlist-dropdown");
  if (
    !playlistTrigger &&
    playlistDropdown &&
    !playlistDropdown.classList.contains("hidden")
  ) {
    closePlaylistDropdown();
  }
});
window.toggleArtistDropdown = function () {
  const dropdown = document.getElementById("artist-options");
  if (dropdown) {
    const isHidden = dropdown.classList.contains("hidden");
    if (isHidden) {
      // Tutup dropdown playlist jika sedang terbuka
      var playlistDrop = document.getElementById("playlist-options");
      if (playlistDrop && !playlistDrop.classList.contains("hidden")) {
        playlistDrop.classList.add("hidden");
      }
      dropdown.classList.remove("hidden");
      document.body.classList.add("artist-dropdown-active");
      var activeItem = dropdown.querySelector(".text-orange-500");
      if (activeItem) {
        activeItem.scrollIntoView({ block: "nearest", behavior: "smooth" });
      }
    } else {
      dropdown.classList.add("hidden");
      setTimeout(function () {
        document.body.classList.remove("artist-dropdown-active");
      }, 350);
    }
  }
};
window.closeArtistDropdown = function () {
  const dropdown = document.getElementById("artist-options");
  if (dropdown) dropdown.classList.add("hidden");
  setTimeout(() => {
    const artistStillOpen =
      document.getElementById("artist-options") &&
      !document.getElementById("artist-options").classList.contains("hidden");
    const playlistStillOpen =
      document.getElementById("playlist-options") &&
      !document.getElementById("playlist-options").classList.contains("hidden");
    if (!artistStillOpen && !playlistStillOpen) {
      document.body.classList.remove("artist-dropdown-active");
    }
  }, 350);
};
// Fungsi navigasi playlist
function setActivePlaylist(id) {
  document.querySelectorAll(".pl-link").forEach(function (el) {
    if (el.dataset.playlistId == id) {
      el.classList.add("active");
      el.style.color = "#f97316";
      el.style.background = "rgba(249,115,22,.08)";
      el.style.borderColor = "rgba(249,115,22,.15)";
    } else {
      el.classList.remove("active");
      el.style.color = "";
      el.style.background = "";
      el.style.borderColor = "";
    }
  });
}
window.loadPlaylistMobile = function (id) {
  if (!id) return;
  var plEl = document.querySelector('[data-playlist-id="' + id + '"]');
  var url =
    plEl && plEl.dataset.playlistUrl
      ? plEl.dataset.playlistUrl
      : "playlist?id=" + id;
  var sep = url.indexOf("?") === -1 ? "?" : "&";
  htmx.ajax("GET", url + sep + "content_only=1", {
    target: "main",
    swap: "innerHTML",
    pushUrl: url,
  });
  setActivePlaylist(id);
  if (typeof resetLibraryFilters === "function") resetLibraryFilters();
};
window.resetActivePlaylist = function () {
  document.querySelectorAll(".pl-link").forEach(function (el) {
    el.classList.remove("active");
    el.style.color = "";
    el.style.background = "";
    el.style.borderColor = "";
  });
};
window.resetArtistHighlight = function () {
  document
    .querySelectorAll("#desktop-artist-list .sidebar-link")
    .forEach(function (el) {
      el.classList.remove("active");
    });
  var allArtistBtns = document.querySelectorAll("#artist-options button");
  allArtistBtns.forEach(function (btn) {
    btn.classList.remove("text-orange-500", "font-bold");
  });
};
// Reset pill format ke "All" — dipakai saat masuk playlist (sidebar beranda
// tetap tampil, tapi state filter tidak boleh "mengikuti" pengguna ke playlist).
window.resetFormatPills = function () {
  document.querySelectorAll(".format-pill").forEach(function (el) {
    el.classList.remove("active-orange", "active-green", "active-blue");
  });
  document
    .querySelectorAll('a.format-pill[href*="format=all"]')
    .forEach(function (el) {
      el.classList.add("active-orange");
    });
};
// Reset semua filter library (artist + format) — konsisten dengan sidebar
// halaman playlist (tidak ada artist/format yang aktif).
window.resetLibraryFilters = function () {
  if (typeof resetArtistHighlight === "function") resetArtistHighlight();
  if (typeof resetFormatPills === "function") resetFormatPills();
};
