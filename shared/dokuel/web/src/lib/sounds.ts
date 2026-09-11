import { reviewMemory } from "./review-memory.ts";
let audioCtx: AudioContext | null = null;

function getContext(): AudioContext | null {
  if (typeof window === "undefined" || typeof AudioContext === "undefined")
    return null;
  if (!audioCtx) {
    audioCtx = new AudioContext();
  }
  return audioCtx;
}

function playTone(frequency: number, duration: number, volume = 0.1) {
  const ctx = getContext();
  if (!ctx || !getSoundEnabled()) return;

  const osc = ctx.createOscillator();
  const gain = ctx.createGain();
  osc.connect(gain);
  gain.connect(ctx.destination);

  osc.frequency.value = frequency;
  osc.type = "sine";
  gain.gain.value = volume;
  gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);

  osc.start(ctx.currentTime);
  osc.stop(ctx.currentTime + duration);
}

const STORAGE_KEY = "sudoku_sound";

// In-memory cache doubles as the storage-denied fallback: this getter
// runs inside every placement/erase/note (via game-feedback), and in
// browsers with storage access blocked reviewMemory.getItem THROWS —
// which used to crash input handling before the move dispatched.
let soundEnabledCache: boolean | null = null;

export function getSoundEnabled(): boolean {
  if (soundEnabledCache === null) {
    try {
      soundEnabledCache = reviewMemory.getItem(STORAGE_KEY) !== "false";
    } catch {
      soundEnabledCache = true;
    }
  }
  return soundEnabledCache;
}

export function setSoundEnabled(enabled: boolean) {
  soundEnabledCache = enabled;
  try {
    reviewMemory.setItem(STORAGE_KEY, String(enabled));
  } catch {
    // Storage unavailable — the in-memory value still applies for this
    // session.
  }
}

export const sounds = {
  place: () => playTone(800, 0.08),
  erase: () => playTone(400, 0.08),
  note: () => playTone(600, 0.05, 0.05),
  conflict: () => {
    playTone(200, 0.15);
    setTimeout(() => playTone(180, 0.15), 100);
  },
  complete: () => {
    const notes = [523, 659, 784, 1047];
    for (let i = 0; i < notes.length; i++) {
      setTimeout(() => playTone(notes[i]!, 0.2, 0.08), i * 100);
    }
  },
};
