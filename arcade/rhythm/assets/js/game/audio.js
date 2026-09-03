/**
 * MEeL!Mania Game — Audio Engine
 * Web Audio API init, SFX playback, BGM generation.
 */
import { S } from "./state.js";

/* WEB AUDIO API */
export let audioCtx = null;
export let masterGain = null;
export let sfxGain = null;
export let bgmGain = null;

export function initAudio() {
  if (audioCtx) return;
  audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  masterGain = audioCtx.createGain();
  masterGain.connect(audioCtx.destination);
  sfxGain = audioCtx.createGain();
  sfxGain.gain.value = 0.7;
  sfxGain.connect(masterGain);
  bgmGain = audioCtx.createGain();
  bgmGain.gain.value = 0.5;
  bgmGain.connect(masterGain);
}

export function resumeAudio() {
  if (audioCtx && audioCtx.state === "suspended") audioCtx.resume();
}

/* SFX */
export function playSFX(type) {
  if (!audioCtx) return;
  const now = audioCtx.currentTime;
  const osc = audioCtx.createOscillator();
  const gain = audioCtx.createGain();
  const cfgs = {
    perfect: { freq: 1046, wave: "sine", vol: 0.2, dur: 0.06 },
    great: { freq: 784, wave: "sine", vol: 0.16, dur: 0.055 },
    good: { freq: 523, wave: "triangle", vol: 0.12, dur: 0.05 },
    bad: { freq: 262, wave: "sawtooth", vol: 0.08, dur: 0.04 },
    miss: { freq: 131, wave: "sawtooth", vol: 0.1, dur: 0.12 },
  };
  const c = cfgs[type] || cfgs.miss;
  osc.type = c.wave;
  osc.frequency.setValueAtTime(c.freq, now);
  if (type === "miss") osc.frequency.exponentialRampToValueAtTime(55, now + c.dur);
  gain.gain.setValueAtTime(c.vol, now);
  gain.gain.exponentialRampToValueAtTime(0.001, now + c.dur);
  osc.connect(gain);
  gain.connect(sfxGain);
  osc.start(now);
  osc.stop(now + c.dur + 0.01);
}

/* BGM */
let bgmInterval = null;

export function startBGM() {
  if (!audioCtx || !S.song) return;

  // If song has a real audio file, play it instead of synthetic BGM
  if (S.song.audioUrl) {
    try {
      S._audioPlaying = true;
      // audioElement is already loaded by loader.js
      const el = document.getElementById("audioPlayer");
      if (el) {
        el.currentTime = 0;
        el.play().catch(function() {});
      }
    } catch (e) {}
    return;
  }

  // Synthetic BGM for builtin songs (no audio file)
  S._audioPlaying = false;
  let beat = 0;
  const bpm = S.song.bpm;
  const beatMs = 60000 / bpm;

  function playKick(t) {
    const o = audioCtx.createOscillator(), g = audioCtx.createGain();
    o.type = "sine";
    o.frequency.setValueAtTime(150, t);
    o.frequency.exponentialRampToValueAtTime(30, t + 0.12);
    g.gain.setValueAtTime(0.2, t);
    g.gain.exponentialRampToValueAtTime(0.001, t + 0.12);
    o.connect(g); g.connect(bgmGain);
    o.start(t); o.stop(t + 0.15);
  }

  function playHihat(t) {
    const len = audioCtx.sampleRate * 0.03;
    const buf = audioCtx.createBuffer(1, len, audioCtx.sampleRate);
    const d = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * 0.3;
    const n = audioCtx.createBufferSource();
    n.buffer = buf;
    const bp = audioCtx.createBiquadFilter();
    bp.type = "bandpass"; bp.frequency.value = 8000; bp.Q.value = 1;
    const g = audioCtx.createGain();
    g.gain.setValueAtTime(0.07, t);
    g.gain.exponentialRampToValueAtTime(0.001, t + 0.03);
    n.connect(bp); bp.connect(g); g.connect(bgmGain);
    n.start(t); n.stop(t + 0.05);
  }

  function playBass(t, freq) {
    const o = audioCtx.createOscillator(), g = audioCtx.createGain();
    o.type = "square";
    o.frequency.setValueAtTime(freq, t);
    g.gain.setValueAtTime(0.05, t);
    g.gain.exponentialRampToValueAtTime(0.001, t + 0.18);
    o.connect(g); g.connect(bgmGain);
    o.start(t); o.stop(t + 0.2);
  }

  const bassNotes = [110, 110, 130.81, 146.83, 110, 130.81, 146.83, 164.81];

  bgmInterval = setInterval(() => {
    if (S.gameState !== "playing") { clearInterval(bgmInterval); return; }
    const now = audioCtx.currentTime;
    const bi = beat % 8;
    if (bi === 0 || bi === 4) playKick(now);
    playHihat(now);
    setTimeout(() => { if (S.gameState === "playing") playHihat(audioCtx.currentTime); }, beatMs / 2);
    if (bi % 2 === 0) playBass(now, bassNotes[bi]);
    beat++;
  }, 60000 / bpm / 2);
}

export function stopBGM() {
  if (bgmInterval) { clearInterval(bgmInterval); bgmInterval = null; }
  // Also stop real audio if playing
  if (S._audioPlaying) {
    try {
      const el = document.getElementById("audioPlayer");
      if (el) el.pause();
    } catch (e) {}
  }
}

/* COUNTDOWN BEEP */
export function playCountdownBeep(freq, duration) {
  if (!audioCtx) return;
  try {
    if (audioCtx.state === "suspended") audioCtx.resume();
    var osc = audioCtx.createOscillator();
    var gain = audioCtx.createGain();
    osc.type = "sine";
    osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
    gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start(audioCtx.currentTime);
    osc.stop(audioCtx.currentTime + duration);
  } catch (e) {}
}
