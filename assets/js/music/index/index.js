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
  // Pencarian menimpa hanya #music-list; tombol "Load More" library hidup di
  // LUAR #music-list sehingga tidak ikut ter-swap dan tetap tampil walau hasil
  // pencarian kosong / habis (atau duplikat dengan load-more milik search).
  // Hapus saat mode pencarian — klik filter format/artist (atau tombol
  // Library) me-render ulang #library-container/main dari server, sehingga
  // tombol muncul kembali sesuai total library yang sebenarnya.
  //
  // CATATAN: htmx:afterSwap di-dispatch pada elemen TARGET swap (bukan elemen
  // pemicu request), jadi e.detail.elt TIDAK bisa membedakan sumber request
  // (search vs load-more). Pakai URL request sebagai pembeda yang deterministik.
  const searchReqUrl = `${e.detail?.requestConfig?.path || ""} ${e.detail?.xhr?.responseURL || ""}`;
  if (targetId === "music-list" && searchReqUrl.includes("/music/search")) {
    const lm = document.getElementById("load-more-music");
    if (lm) lm.remove();
  }
  if (isContentUpdate && !isFromLoadMore && !targetId.includes("music-list")) {
    scrollToActiveArtistDesktop();
  }
});
