function saveAudioState() {
  if (!window.MEEL_MUSIC_CONFIG) return;
  const e = window.MEEL_MUSIC_CONFIG,
    t = e.playlistId || 0;
  // watchUrl HARUS membawa playlist_id (kalau ada) — dipakai expandPlayerFromMiniPlayer()
  // di index.php untuk kembali ke full player. Tanpa ini, konteks playlist hilang
  // saat bolak-balik watch.php <-> mini-player index.php. `watchUrl` (global dari
  // state.js, = window.location.href di player-core.js) sudah memuat query string asli.
  const url =
    (typeof watchUrl === "string" && watchUrl ? watchUrl : "") ||
    `watch.php?id=${e.id}`;
  (sessionStorage.setItem(
    "meel_audio_state",
    JSON.stringify({
      id: e.id,
      musicId: e.id,
      playlistId: t,
      watchUrl: url,
      currentTime: player ? player.currentTime : 0,
      isPlaying: !!player && !player.paused,
      isLooping: !!player && player.loop,
      title: e.title,
      artist: e.artist,
      thumbnail: e.thumbnail,
      thumbnailUrl: e.thumbnailUrl || "",
      filename: e.filename,
    }),
  ),
    t > 0
      ? localStorage.setItem("meel_last_playlist_id", String(t))
      : localStorage.removeItem("meel_last_playlist_id"));
}
