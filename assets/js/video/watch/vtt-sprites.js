const _vttSpriteCache = {};
function refreshVttSprites(e) {
  if (!player) return;
  ((player.config.previewThumbnails.src = e),
    (player.config.previewThumbnails.enabled = !0),
    player.previewThumbnails &&
      ((player.previewThumbnails.thumbnails = []),
      (player.previewThumbnails.loaded = !1),
      "function" == typeof player.previewThumbnails.load &&
        player.previewThumbnails.load()));
  const t = (e) => {
    (document
      .querySelectorAll(".plyr__preview-thumb__image-container")
      .forEach((t) => {
        t.style.backgroundImage = `url("${e}")`;
      }),
      document
        .querySelectorAll(
          ".plyr__preview-thumb__image-container img, .plyr__preview-scrubbing img",
        )
        .forEach((t) => {
          t.src = e;
        }));
  };
  _vttSpriteCache[e]
    ? t(_vttSpriteCache[e])
    : fetch(e)
        .then((e) => e.text())
        .then((n) => {
          const o = n.match(/([\w-]+\.(jpg|png|webp|jpeg))/i);
          if (o) {
            const n = e.substring(0, e.lastIndexOf("/") + 1) + o[1];
            ((_vttSpriteCache[e] = n), t(n));
          }
        })
        .catch((e) => console.error("Gagal refresh VTT sprites:", e));
}
