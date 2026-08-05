/* ============================================================
 * index.js — Bootstrap halaman Music Library (music/index.php): init lucide, boot handler (mini player + klik item + scroll), dan sinkronisasi setelah swap HTMX (search, filter, load-more).
 * Depends on: shared/utils.js, shared/mini-player.js,
 *            index/library-ui.js, index/load-more.js
 * ============================================================ */
if (typeof lucide !== "undefined") lucide.createIcons();
document.addEventListener("DOMContentLoaded", () => {
  bootPlayerIndex();
});
document.addEventListener("htmx:afterSwap", (e) => {
  if (typeof lucide !== "undefined") lucide.createIcons();
  const targetId = e.target?.id || "";
  const isContentUpdate =
    targetId.includes("music-list") ||
    targetId.includes("recommendation") ||
    targetId.includes("search") ||
    targetId.includes("load-more-music") ||
    targetId.includes("library-container") ||
    targetId === "main";
  document.body.classList.remove("artist-dropdown-active");
  if (!isContentUpdate) {
    bootPlayerIndex();
  } else {
    setupMusicItemClicks();
  }
  if (typeof setupPlaylistItemClicks === "function") {
    setupPlaylistItemClicks();
  }
  const isFromLoadMore = e.detail?.elt?.closest?.("#load-more-music") != null;
  if (isContentUpdate && !isFromLoadMore && !targetId.includes("music-list")) {
    scrollToActiveArtistDesktop();
  }
});
