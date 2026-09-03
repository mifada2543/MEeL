if (typeof lucide !== "undefined") lucide.createIcons();




document.addEventListener("htmx:configRequest", (e) => {
  const path = e.detail?.path || e.detail?.requestConfig?.path || "";
  if (!path.includes("/music/search") && !path.includes("search_music")) return;
  let id = 0;
  try {
    const raw = sessionStorage.getItem(window.MEEL_KEYS?.AUDIO_STATE);
    if (raw) {
      const s = JSON.parse(raw);
      id = s?.musicId ?? s?.id ?? 0;
    }
  } catch (err) {}
  e.detail.parameters["exclude"] = String(id || 0);
});
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
  
  
  
  
  
  

  
  
  
  const searchReqUrl = `${e.detail?.requestConfig?.path || ""} ${e.detail?.xhr?.responseURL || ""}`;
  if (targetId === "music-list" && searchReqUrl.includes("/music/search")) {
    const lm = document.getElementById("load-more-music");
    if (lm) lm.remove();
  }
  if (isContentUpdate && !isFromLoadMore && !targetId.includes("music-list")) {
    scrollToActiveArtistDesktop();
  }
});
