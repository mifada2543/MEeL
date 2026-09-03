export class ChessSoundEffects {
  constructor() {
    this.ctx = null;
    this.initialized = false;
  }
  init() {
    if (!this.initialized) {
      this.ctx = new (window.AudioContext || window.webkitAudioContext)();
      this.initialized = true;
      if (this.ctx.state === "suspended") this.ctx.resume();
    }
  }
  
  playTone({
    type,
    freq1,
    freq2,
    duration,
    vol = 0.15,
    attack = 0.005,
    release = 0.05,
  }) {
    if (!this.ctx) return;
    try {
      const now = this.ctx.currentTime;
      const osc = this.ctx.createOscillator();
      const gain = this.ctx.createGain();
      osc.type = type;
      osc.frequency.setValueAtTime(freq1, now);
      if (freq2) {
        osc.frequency.exponentialRampToValueAtTime(freq2, now + duration);
      }
      
      gain.gain.setValueAtTime(0, now);
      gain.gain.linearRampToValueAtTime(vol, now + attack);
      gain.gain.setValueAtTime(vol, now + duration - release);
      gain.gain.exponentialRampToValueAtTime(0.001, now + duration);

      osc.connect(gain);
      gain.connect(this.ctx.destination);
      osc.start(now);
      osc.stop(now + duration);
    } catch (e) {
      console.warn("Audio error:", e);
    }
  }
  
  playMove() {
    this.init();
    this.playTone({
      type: "sine",
      freq1: 400,
      freq2: 350,
      duration: 0.08,
      vol: 0.08,
      attack: 0.002,
      release: 0.02,
    });
    this.playTone({
      type: "triangle",
      freq1: 320,
      freq2: 280,
      duration: 0.06,
      vol: 0.06,
      attack: 0.001,
      release: 0.01,
    });
  }
  
  playCapture() {
    this.init();
    this.playTone({
      type: "square",
      freq1: 380,
      freq2: 200,
      duration: 0.12,
      vol: 0.1,
      attack: 0.001,
      release: 0.03,
    });
    
    this.playTone({
      type: "triangle",
      freq1: 220,
      freq2: 120,
      duration: 0.15,
      vol: 0.12,
      attack: 0.003,
      release: 0.04,
    });
    
    this.playTone({
      type: "sawtooth",
      freq1: 600,
      freq2: 300,
      duration: 0.06,
      vol: 0.06,
      attack: 0.001,
      release: 0.015,
    });
  }
  
  playCheck() {
    this.init();
    const now = this.ctx.currentTime;
    [
      { f: 660, t: 0.12 },
      { f: 880, t: 0.18 },
    ].forEach(({ f, t }) => {
      try {
        const osc = this.ctx.createOscillator();
        const gain = this.ctx.createGain();
        osc.type = "sine";
        osc.frequency.setValueAtTime(f, now);
        gain.gain.setValueAtTime(0.18, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + t);
        osc.connect(gain);
        gain.connect(this.ctx.destination);
        osc.start(now);
        osc.stop(now + t);
      } catch (e) {}
    });
  }
  
  playCastle() {
    this.init();
    this.playTone({
      type: "sine",
      freq1: 250,
      freq2: 180,
      duration: 0.2,
      vol: 0.08,
      attack: 0.01,
      release: 0.06,
    });
    this.playTone({
      type: "triangle",
      freq1: 300,
      freq2: 220,
      duration: 0.18,
      vol: 0.06,
      attack: 0.005,
      release: 0.04,
    });
  }
  
  playPromotion() {
    this.init();
    const now = this.ctx.currentTime;
    [523, 659, 784].forEach((freq, i) => {
      try {
        const osc = this.ctx.createOscillator();
        const gain = this.ctx.createGain();
        osc.type = "sine";
        osc.frequency.setValueAtTime(freq, now + i * 0.08);
        gain.gain.setValueAtTime(0, now + i * 0.08);
        gain.gain.linearRampToValueAtTime(0.15, now + i * 0.08 + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.08 + 0.2);
        osc.connect(gain);
        gain.connect(this.ctx.destination);
        osc.start(now + i * 0.08);
        osc.stop(now + i * 0.08 + 0.2);
      } catch (e) {}
    });
  }
  
  playGameOver() {
    this.init();
    const now = this.ctx.currentTime;
    
    [440, 370, 330, 262].forEach((freq, i) => {
      try {
        const osc = this.ctx.createOscillator();
        const gain = this.ctx.createGain();
        osc.type = "triangle";
        osc.frequency.setValueAtTime(freq, now + i * 0.12);
        gain.gain.setValueAtTime(0, now + i * 0.12);
        gain.gain.linearRampToValueAtTime(0.18, now + i * 0.12 + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.12 + 0.35);
        osc.connect(gain);
        gain.connect(this.ctx.destination);
        osc.start(now + i * 0.12);
        osc.stop(now + i * 0.12 + 0.35);
      } catch (e) {}
    });
  }
}
export const sounds = new ChessSoundEffects();
