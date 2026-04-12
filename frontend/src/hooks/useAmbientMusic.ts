import { useState, useRef, useCallback, useEffect } from 'react';

export type Mood = 'exploration' | 'tavern' | 'combat' | 'dungeon' | 'mystical' | 'camp';

interface AmbientLayer {
  name: string;
  node: OscillatorNode | AudioBufferSourceNode;
  gain: GainNode;
}

// Curated music tracks — royalty-free / CC-licensed
const MUSIC_TRACKS: Record<Mood, string[]> = {
  exploration: [
    '/music/exploration.mp3',
  ],
  tavern: [
    '/music/tavern.mp3',
  ],
  combat: [
    '/music/combat.mp3',
  ],
  dungeon: [
    '/music/dungeon.mp3',
  ],
  mystical: [
    '/music/mystical.mp3',
  ],
  camp: [
    '/music/camp.mp3',
  ],
};

function createNoiseBuffer(ctx: AudioContext, seconds: number): AudioBuffer {
  const sampleRate = ctx.sampleRate;
  const length = sampleRate * seconds;
  const buffer = ctx.createBuffer(1, length, sampleRate);
  const data = buffer.getChannelData(0);
  for (let i = 0; i < length; i++) {
    data[i] = Math.random() * 2 - 1;
  }
  return buffer;
}

function buildProceduralLayers(ctx: AudioContext, mood: Mood, master: GainNode): AmbientLayer[] {
  const layers: AmbientLayer[] = [];

  const addOsc = (name: string, type: OscillatorType, freq: number, vol: number, detune = 0) => {
    const osc = ctx.createOscillator();
    osc.type = type;
    osc.frequency.value = freq;
    osc.detune.value = detune;
    const gain = ctx.createGain();
    gain.gain.value = vol;
    osc.connect(gain).connect(master);
    osc.start();
    layers.push({ name, node: osc, gain });
  };

  const addNoise = (name: string, vol: number, lowFreq: number, highFreq: number) => {
    const noise = ctx.createBufferSource();
    noise.buffer = createNoiseBuffer(ctx, 4);
    noise.loop = true;
    const bandpass = ctx.createBiquadFilter();
    bandpass.type = 'bandpass';
    bandpass.frequency.value = (lowFreq + highFreq) / 2;
    bandpass.Q.value = 0.5;
    const gain = ctx.createGain();
    gain.gain.value = vol;
    noise.connect(bandpass).connect(gain).connect(master);
    noise.start();
    layers.push({ name, node: noise, gain });
  };

  switch (mood) {
    case 'exploration':
      // Gentle wind + soft drone
      addNoise('wind', 0.06, 200, 800);
      addOsc('drone-low', 'sine', 65, 0.04);
      addOsc('drone-fifth', 'sine', 98, 0.025);
      addOsc('shimmer', 'sine', 392, 0.008, 3);
      break;

    case 'tavern':
      // Warm crackling fire + murmur
      addNoise('fire-crackle', 0.05, 1000, 4000);
      addNoise('murmur', 0.03, 200, 600);
      addOsc('hearth-hum', 'sine', 80, 0.03);
      break;

    case 'combat':
      // Tense low drone + heartbeat pulse
      addOsc('tension-bass', 'sawtooth', 55, 0.04);
      addOsc('tension-mid', 'square', 110, 0.015);
      addOsc('dissonance', 'sine', 117, 0.02); // tritone tension
      addNoise('rumble', 0.04, 30, 120);
      break;

    case 'dungeon':
      // Dark dripping + eerie drone
      addNoise('drip-echo', 0.03, 2000, 5000);
      addOsc('dark-drone', 'sine', 50, 0.04);
      addOsc('eerie', 'sine', 233, 0.012, 5); // slightly detuned for unease
      addNoise('distant-rumble', 0.025, 40, 100);
      break;

    case 'mystical':
      // Ethereal pads + shimmering overtones
      addOsc('pad-root', 'sine', 130, 0.03);
      addOsc('pad-fifth', 'sine', 196, 0.02);
      addOsc('pad-octave', 'sine', 262, 0.015);
      addOsc('shimmer-high', 'triangle', 523, 0.008, 7);
      addNoise('sparkle', 0.015, 3000, 8000);
      break;

    case 'camp':
      // Campfire + crickets + gentle wind
      addNoise('campfire', 0.05, 800, 3000);
      addNoise('wind-gentle', 0.03, 100, 400);
      addOsc('cricket-1', 'sine', 4200, 0.006);
      addOsc('cricket-2', 'sine', 4800, 0.004);
      addOsc('night-drone', 'sine', 70, 0.02);
      break;
  }

  return layers;
}

export function useAmbientMusic() {
  const [playing, setPlaying] = useState(false);
  const [mood, setMoodState] = useState<Mood>('exploration');
  const [volume, setVolumeState] = useState(0.5);
  const [hasMusicTrack, setHasMusicTrack] = useState(false);

  const ctxRef = useRef<AudioContext | null>(null);
  const masterGainRef = useRef<GainNode | null>(null);
  const layersRef = useRef<AmbientLayer[]>([]);
  const musicElRef = useRef<HTMLAudioElement | null>(null);
  const musicGainRef = useRef<GainNode | null>(null);
  const musicSourceRef = useRef<MediaElementAudioSourceNode | null>(null);
  const moodRef = useRef(mood);
  const volumeRef = useRef(volume);

  useEffect(() => { moodRef.current = mood; }, [mood]);
  useEffect(() => { volumeRef.current = volume; }, [volume]);

  const stopLayers = useCallback(() => {
    for (const layer of layersRef.current) {
      try {
        layer.gain.gain.setTargetAtTime(0, ctxRef.current?.currentTime ?? 0, 0.5);
        if (layer.node instanceof OscillatorNode) {
          setTimeout(() => { try { layer.node.stop(); } catch {} }, 1000);
        } else {
          setTimeout(() => { try { (layer.node as AudioBufferSourceNode).stop(); } catch {} }, 1000);
        }
      } catch {}
    }
    layersRef.current = [];
  }, []);

  const stopMusic = useCallback(() => {
    if (musicElRef.current) {
      musicElRef.current.pause();
      musicElRef.current.src = '';
      musicElRef.current = null;
    }
    if (musicSourceRef.current) {
      try { musicSourceRef.current.disconnect(); } catch {}
      musicSourceRef.current = null;
    }
    setHasMusicTrack(false);
  }, []);

  const startLayers = useCallback((ctx: AudioContext, currentMood: Mood, master: GainNode) => {
    stopLayers();
    layersRef.current = buildProceduralLayers(ctx, currentMood, master);
  }, [stopLayers]);

  const tryLoadMusic = useCallback((ctx: AudioContext, currentMood: Mood, master: GainNode) => {
    stopMusic();
    const tracks = MUSIC_TRACKS[currentMood];
    if (!tracks || tracks.length === 0) return;

    const track = tracks[Math.floor(Math.random() * tracks.length)];
    const audio = new Audio(track);
    audio.loop = true;
    audio.crossOrigin = 'anonymous';

    // Route through Web Audio for volume control
    const musicGain = ctx.createGain();
    musicGain.gain.value = 0.7; // music slightly louder than procedural
    musicGainRef.current = musicGain;

    audio.addEventListener('canplaythrough', () => {
      try {
        if (!musicSourceRef.current) {
          const source = ctx.createMediaElementSource(audio);
          musicSourceRef.current = source;
          source.connect(musicGain).connect(master);
        }
        audio.play().catch(() => {});
        setHasMusicTrack(true);
      } catch {}
    }, { once: true });

    audio.addEventListener('error', () => {
      // No music file available — procedural only
      setHasMusicTrack(false);
    }, { once: true });

    musicElRef.current = audio;
  }, [stopMusic]);

  const start = useCallback((initialMood?: Mood) => {
    const m = initialMood ?? moodRef.current;
    setMoodState(m);

    if (!ctxRef.current) {
      ctxRef.current = new AudioContext();
    }
    const ctx = ctxRef.current;
    if (ctx.state === 'suspended') ctx.resume();

    if (!masterGainRef.current) {
      masterGainRef.current = ctx.createGain();
      masterGainRef.current.connect(ctx.destination);
    }
    masterGainRef.current.gain.value = volumeRef.current;

    startLayers(ctx, m, masterGainRef.current);
    tryLoadMusic(ctx, m, masterGainRef.current);
    setPlaying(true);
  }, [startLayers, tryLoadMusic]);

  const stop = useCallback(() => {
    stopLayers();
    stopMusic();
    if (ctxRef.current) {
      ctxRef.current.close().catch(() => {});
      ctxRef.current = null;
      masterGainRef.current = null;
    }
    setPlaying(false);
  }, [stopLayers, stopMusic]);

  const setMood = useCallback((newMood: Mood) => {
    setMoodState(newMood);
    if (playing && ctxRef.current && masterGainRef.current) {
      startLayers(ctxRef.current, newMood, masterGainRef.current);
      tryLoadMusic(ctxRef.current, newMood, masterGainRef.current);
    }
  }, [playing, startLayers, tryLoadMusic]);

  const setVolume = useCallback((v: number) => {
    const clamped = Math.max(0, Math.min(1, v));
    setVolumeState(clamped);
    if (masterGainRef.current) {
      masterGainRef.current.gain.setTargetAtTime(clamped, ctxRef.current?.currentTime ?? 0, 0.1);
    }
  }, []);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      layersRef.current.forEach(l => {
        try {
          if (l.node instanceof OscillatorNode) l.node.stop();
          else (l.node as AudioBufferSourceNode).stop();
        } catch {}
      });
      if (musicElRef.current) {
        musicElRef.current.pause();
        musicElRef.current.src = '';
      }
      if (ctxRef.current) ctxRef.current.close().catch(() => {});
    };
  }, []);

  return {
    playing,
    mood,
    volume,
    hasMusicTrack,
    start,
    stop,
    setMood,
    setVolume,
  };
}
