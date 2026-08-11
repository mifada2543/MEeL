/* state-keys.js — konstanta kunci localStorage/sessionStorage terpusat.
 * Muat SEBELUM file JS lain yang membaca kunci meel_* / skip_*.
 */
window.MEEL_KEYS = Object.freeze({
  AUDIO_STATE: 'meel_audio_state',
  SKIP_RESUME_ONCE: 'skip_resume_once',
  GLOBAL_LOOP: 'meel_global_loop',
  LAST_PLAYLIST_ID: 'meel_last_playlist_id',
  AUTONEXT_ENABLED: 'meel_autonext_enabled',
  AUTONAV: 'meel_autonav',
  EQ_STATE: 'meel_music_eq_state',
  HEALTH_ALERT: 'meel_health_alert',
  GLOW_ENABLED: 'meel_glow_enabled'
});
