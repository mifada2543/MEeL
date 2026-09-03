if (typeof lucide !== "undefined") lucide.createIcons();

window.setActivePlaylistSidebar = function (id) {
  document.querySelectorAll(".sidebar-link.pl-link").forEach(function (el) {
    if (parseInt(el.dataset.playlistId) === id) {
      el.classList.add("active");
    } else {
      el.classList.remove("active");
    }
  });
  var label = document.getElementById("playlist-dropdown-label-pl");
  var options = document.querySelectorAll("#playlist-options-pl button");
  options.forEach(function (btn) {
    btn.classList.remove("text-orange-500", "font-bold");
    btn.classList.add("text-gray-300", "hover:bg-white/[.04]");
  });
  options.forEach(function (btn) {
    if (parseInt(btn.dataset.playlistId) === id) {
      btn.classList.remove("text-gray-300", "hover:bg-white/[.04]");
      btn.classList.add("text-orange-500", "font-bold");
      if (label) label.textContent = btn.textContent.trim();
    }
  });
};

window.toggleArtistDropdownPL = function () {
  var dd = document.getElementById("artist-options-pl");
  if (!dd) return;
  var hidden = dd.classList.contains("hidden");
  dd.classList.toggle("hidden");
  if (hidden) {
    document.body.classList.add("artist-dropdown-active");
    var active = dd.querySelector(".text-orange-500");
    if (active) active.scrollIntoView({ block: "nearest", behavior: "smooth" });
  } else {
    setTimeout(function () {
      document.body.classList.remove("artist-dropdown-active");
    }, 350);
  }
};
window.closeArtistDropdownPL = function () {
  const dropdown = document.getElementById("artist-options-pl");
  if (dropdown) dropdown.classList.add("hidden");
  setTimeout(function () {
    const artistStillOpen =
      document.getElementById("artist-options-pl") &&
      !document
        .getElementById("artist-options-pl")
        .classList.contains("hidden");
    const playlistStillOpen =
      document.getElementById("playlist-options-pl") &&
      !document
        .getElementById("playlist-options-pl")
        .classList.contains("hidden");
    if (!artistStillOpen && !playlistStillOpen) {
      document.body.classList.remove("artist-dropdown-active");
    }
  }, 350);
};
window.navigateToArtistPL = function (artist) {
  closeArtistDropdownPL();
  if (artist === "all") {
    window.location.href = "beranda";
  } else {
    window.location.href = "beranda?artist=" + encodeURIComponent(artist);
  }
};

window.togglePlaylistDropdownPL = function () {
  const dropdown = document.getElementById("playlist-options-pl");
  if (dropdown) {
    const isHidden = dropdown.classList.contains("hidden");
    if (isHidden) {
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
window.closePlaylistDropdownPL = function () {
  const dropdown = document.getElementById("playlist-options-pl");
  if (dropdown) dropdown.classList.add("hidden");
  setTimeout(function () {
    const artistStillOpen =
      document.getElementById("artist-options-pl") &&
      !document
        .getElementById("artist-options-pl")
        .classList.contains("hidden");
    const playlistStillOpen =
      document.getElementById("playlist-options-pl") &&
      !document
        .getElementById("playlist-options-pl")
        .classList.contains("hidden");
    if (!artistStillOpen && !playlistStillOpen) {
      document.body.classList.remove("artist-dropdown-active");
    }
  }, 350);
};
window.navigateToPlaylistPL = function (id) {
  closePlaylistDropdownPL();
  setActivePlaylistSidebar(id);
  var plEl = document.querySelector('[data-playlist-id="' + id + '"]');
  var url =
    plEl && plEl.dataset.playlistUrl
      ? plEl.dataset.playlistUrl
      : "playlist?id=" + id;
  var sep = url.indexOf("?") === -1 ? "?" : "&";
  htmx.ajax("GET", url + sep + "content_only=1", {
    target: "#playlist-main",
    swap: "innerHTML",
    pushUrl: url,
  });
};

document.addEventListener("click", function (e) {
  const artistDropdown = document.getElementById("artist-options-pl");
  const artistTrigger = e.target.closest("#custom-artist-dropdown-pl");
  if (
    !artistTrigger &&
    artistDropdown &&
    !artistDropdown.classList.contains("hidden")
  ) {
    closeArtistDropdownPL();
  }
  const playlistDropdown = document.getElementById("playlist-options-pl");
  const playlistTrigger = e.target.closest("#custom-playlist-dropdown-pl");
  if (
    !playlistTrigger &&
    playlistDropdown &&
    !playlistDropdown.classList.contains("hidden")
  ) {
    closePlaylistDropdownPL();
  }
});

function bootPlaylistPage() {
  initMiniPlayerIndex();
  setupPlaylistItemClicks();
}
document.addEventListener("DOMContentLoaded", bootPlaylistPage);
document.addEventListener("htmx:afterSwap", function () {
  if (typeof lucide !== "undefined") lucide.createIcons();
  if (typeof setupPlaylistItemClicks === "function") setupPlaylistItemClicks();
});
