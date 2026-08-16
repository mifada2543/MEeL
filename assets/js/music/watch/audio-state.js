function saveAudioState() {
  // Interval saveAudioState() (5s) milik view watch TIDAK boleh
  // menulis state saat view aktif bukan watch (mis. sudah pindah ke index
  // via goBackToLibrary) — kalau tidak, dia menimpa meel_audio_state dengan
  // lagu LAMA dan bertabrakan dengan saveIndexState() milik index.
  if (window.__meelCurrentView !== "watch") return;
  if (!window.MEEL_MUSIC_CONFIG) return;
  const e = window.MEEL_MUSIC_CONFIG,
    t = e.playlistId || 0;
  // watchUrl HARUS membawa playlist_id (kalau ada) — dipakai expandPlayerFromMiniPlayer()
  // di index.php untuk kembali ke full player. Tanpa ini, konteks playlist hilang
  // saat bolak-balik watch.php <-> mini-player index.php. `watchUrl` (global dari
  // state.js, = window.location.href di player-core.js) sudah memuat query string asli.
  const url =
    (typeof watchUrl === "string" && watchUrl ? watchUrl : "") ||
    `watch?id=${e.id}`;
  (sessionStorage.setItem(
    MEEL_KEYS.AUDIO_STATE,
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
      ? localStorage.setItem(MEEL_KEYS.LAST_PLAYLIST_ID, String(t))
      : localStorage.removeItem(MEEL_KEYS.LAST_PLAYLIST_ID));
}
