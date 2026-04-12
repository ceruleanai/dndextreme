import { useState, useRef, useCallback, useEffect } from 'react';

export type Mood = 'exploration' | 'tavern' | 'combat' | 'dungeon' | 'mystical' | 'camp';

const CROSSFADE_MS = 2000;
const DUCK_MS = 600;
const DUCK_RATIO = 0.15; // duck to 15% of set volume

export function useAmbientMusic() {
  const [playing, setPlaying] = useState(false);
  const [mood, setMoodState] = useState<Mood>('exploration');
  const [volume, setVolumeState] = useState(0.4);

  const [ducked, setDucked] = useState(false);

  const currentAudioRef = useRef<HTMLAudioElement | null>(null);
  const fadingOutRef = useRef<HTMLAudioElement | null>(null);
  const fadeIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const duckIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const moodRef = useRef(mood);
  const volumeRef = useRef(volume);
  const playingRef = useRef(false);
  const duckedRef = useRef(false);

  useEffect(() => { moodRef.current = mood; }, [mood]);
  useEffect(() => { volumeRef.current = volume; }, [volume]);
  useEffect(() => { playingRef.current = playing; }, [playing]);

  const fadeOut = useCallback((audio: HTMLAudioElement, onDone?: () => void) => {
    const startVol = audio.volume;
    const steps = 40;
    const stepMs = CROSSFADE_MS / steps;
    let step = 0;

    const interval = setInterval(() => {
      step++;
      audio.volume = Math.max(0, startVol * (1 - step / steps));
      if (step >= steps) {
        clearInterval(interval);
        audio.pause();
        audio.src = '';
        onDone?.();
      }
    }, stepMs);

    return interval;
  }, []);

  const playTrack = useCallback((trackMood: Mood, vol: number) => {
    const audio = new Audio(`/music/${trackMood}.mp3`);
    audio.loop = true;
    audio.volume = 0;

    audio.addEventListener('canplaythrough', () => {
      if (!playingRef.current) return;
      audio.play().catch(() => {});

      // Fade in
      const steps = 40;
      const stepMs = CROSSFADE_MS / steps;
      const targetVol = vol;
      let step = 0;

      const interval = setInterval(() => {
        step++;
        audio.volume = Math.min(targetVol, targetVol * (step / steps));
        if (step >= steps) {
          clearInterval(interval);
        }
      }, stepMs);
    }, { once: true });

    audio.addEventListener('error', () => {
      console.warn(`[Ambient] No track found for mood: ${trackMood}`);
    }, { once: true });

    currentAudioRef.current = audio;
  }, []);

  const switchTrack = useCallback((newMood: Mood) => {
    // Clear any ongoing fade
    if (fadeIntervalRef.current) {
      clearInterval(fadeIntervalRef.current);
      fadeIntervalRef.current = null;
    }

    // Fade out old track
    if (fadingOutRef.current) {
      fadingOutRef.current.pause();
      fadingOutRef.current.src = '';
    }

    if (currentAudioRef.current) {
      const old = currentAudioRef.current;
      fadingOutRef.current = old;
      currentAudioRef.current = null;
      fadeIntervalRef.current = fadeOut(old, () => {
        fadingOutRef.current = null;
        fadeIntervalRef.current = null;
      });
    }

    // Start new track with fade in
    playTrack(newMood, volumeRef.current);
  }, [fadeOut, playTrack]);

  const start = useCallback((initialMood?: Mood) => {
    const m = initialMood ?? moodRef.current;
    setMoodState(m);
    setPlaying(true);
    playTrack(m, volumeRef.current);
  }, [playTrack]);

  const stop = useCallback(() => {
    setPlaying(false);
    if (fadeIntervalRef.current) {
      clearInterval(fadeIntervalRef.current);
      fadeIntervalRef.current = null;
    }
    if (currentAudioRef.current) {
      currentAudioRef.current.pause();
      currentAudioRef.current.src = '';
      currentAudioRef.current = null;
    }
    if (fadingOutRef.current) {
      fadingOutRef.current.pause();
      fadingOutRef.current.src = '';
      fadingOutRef.current = null;
    }
  }, []);

  const setMood = useCallback((newMood: Mood) => {
    if (newMood === moodRef.current) return;
    setMoodState(newMood);
    if (playingRef.current) {
      switchTrack(newMood);
    }
  }, [switchTrack]);

  const setVolume = useCallback((v: number) => {
    const clamped = Math.max(0, Math.min(1, v));
    setVolumeState(clamped);
    if (currentAudioRef.current) {
      currentAudioRef.current.volume = duckedRef.current ? clamped * DUCK_RATIO : clamped;
    }
  }, []);

  const smoothVolume = useCallback((target: number, durationMs: number) => {
    if (duckIntervalRef.current) {
      clearInterval(duckIntervalRef.current);
    }
    const audio = currentAudioRef.current;
    if (!audio) return;
    const startVol = audio.volume;
    const steps = 30;
    const stepMs = durationMs / steps;
    let step = 0;
    duckIntervalRef.current = setInterval(() => {
      step++;
      audio.volume = startVol + (target - startVol) * (step / steps);
      if (step >= steps) {
        clearInterval(duckIntervalRef.current!);
        duckIntervalRef.current = null;
        audio.volume = target;
      }
    }, stepMs);
  }, []);

  const duck = useCallback(() => {
    if (duckedRef.current) return;
    duckedRef.current = true;
    setDucked(true);
    smoothVolume(volumeRef.current * DUCK_RATIO, DUCK_MS);
  }, [smoothVolume]);

  const unduck = useCallback(() => {
    if (!duckedRef.current) return;
    duckedRef.current = false;
    setDucked(false);
    smoothVolume(volumeRef.current, DUCK_MS);
  }, [smoothVolume]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (currentAudioRef.current) {
        currentAudioRef.current.pause();
        currentAudioRef.current.src = '';
      }
      if (fadingOutRef.current) {
        fadingOutRef.current.pause();
        fadingOutRef.current.src = '';
      }
      if (fadeIntervalRef.current) {
        clearInterval(fadeIntervalRef.current);
      }
      if (duckIntervalRef.current) {
        clearInterval(duckIntervalRef.current);
      }
    };
  }, []);

  return { playing, mood, volume, ducked, start, stop, setMood, setVolume, duck, unduck };
}
