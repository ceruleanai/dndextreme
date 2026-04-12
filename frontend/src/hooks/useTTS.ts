import { useState, useRef, useCallback } from 'react';

const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

function splitIntoChunks(text: string, maxLen = 300): string[] {
  const sentences = text.match(/[^.!?]+[.!?]+|[^.!?]+$/g) || [text];
  const chunks: string[] = [];
  let current = '';

  for (const sentence of sentences) {
    const trimmed = sentence.trim();
    if (!trimmed) continue;
    if (current && (current.length + trimmed.length + 1) > maxLen) {
      chunks.push(current.trim());
      current = trimmed;
    } else {
      current += (current ? ' ' : '') + trimmed;
    }
  }
  if (current.trim()) chunks.push(current.trim());
  return chunks.length > 0 ? chunks : [text];
}

async function fetchAudio(text: string, voice: string, signal?: AbortSignal): Promise<{ url: string; audio: HTMLAudioElement } | null> {
  try {
    const token = localStorage.getItem('auth_token');
    const response = await fetch(`${API_BASE}/tts`, {
      method: 'POST',
      signal,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'audio/*',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({ text, voice }),
    });

    if (!response.ok) return null;

    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const audio = new Audio(url);
    return { url, audio };
  } catch {
    return null;
  }
}

function playAudio(audio: HTMLAudioElement): Promise<void> {
  return new Promise<void>((resolve) => {
    audio.onended = () => resolve();
    audio.onerror = () => resolve();
    audio.play().catch(() => resolve());
  });
}

function doBrowserTTS(
  text: string,
  onEnd: () => void,
) {
  if (!('speechSynthesis' in window)) {
    onEnd();
    return;
  }

  window.speechSynthesis.cancel();
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.rate = 0.9;
  utterance.pitch = 0.8;

  const voices = window.speechSynthesis.getVoices();
  const preferred = voices.find(v => v.name.includes('Male') && v.lang.startsWith('en')) ||
    voices.find(v => v.lang.startsWith('en'));
  if (preferred) utterance.voice = preferred;

  utterance.onend = onEnd;
  utterance.onerror = onEnd;

  window.speechSynthesis.speak(utterance);
}

export function useTTS() {
  const [speaking, setSpeaking] = useState(false);
  const [speakingId, setSpeakingId] = useState<number | null>(null);
  const audioRef = useRef<HTMLAudioElement | null>(null);
  const abortControllerRef = useRef<AbortController | null>(null);

  const stopPlayback = useCallback(() => {
    // Abort any in-flight fetches
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
    }
    // Stop current audio
    if (audioRef.current) {
      audioRef.current.pause();
      audioRef.current.onended = null;
      audioRef.current.onerror = null;
      audioRef.current = null;
    }
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
    }
    setSpeaking(false);
    setSpeakingId(null);
  }, []);

  const speak = useCallback(async (text: string, id?: number) => {
    // Stop any current playback first
    stopPlayback();

    // Strip markdown for cleaner speech
    const cleanText = text
      .replace(/\*\*(.*?)\*\*/g, '$1')
      .replace(/\*(.*?)\*/g, '$1')
      .replace(/#{1,6}\s/g, '')
      .replace(/\[.*?\]/g, '')
      .replace(/\n{2,}/g, '. ')
      .replace(/\n/g, ' ')
      .trim();

    if (!cleanText) return;

    const controller = new AbortController();
    abortControllerRef.current = controller;

    setSpeaking(true);
    setSpeakingId(id ?? null);

    const chunks = splitIntoChunks(cleanText);
    let nextFetch: Promise<{ url: string; audio: HTMLAudioElement } | null> | null = null;

    for (let i = 0; i < chunks.length; i++) {
      if (controller.signal.aborted) break;

      // Use prefetched result or fetch current chunk
      const resultPromise = nextFetch ?? fetchAudio(chunks[i], 'Charon', controller.signal);

      // Start prefetching next chunk in parallel
      if (i + 1 < chunks.length && !controller.signal.aborted) {
        nextFetch = fetchAudio(chunks[i + 1], 'Charon', controller.signal);
      } else {
        nextFetch = null;
      }

      const result = await resultPromise;

      if (controller.signal.aborted) {
        if (result) URL.revokeObjectURL(result.url);
        break;
      }

      if (!result) {
        // API TTS failed — fall back to browser TTS for remaining text
        const remaining = chunks.slice(i).join(' ');
        doBrowserTTS(remaining, () => {
          if (!controller.signal.aborted) {
            setSpeaking(false);
            setSpeakingId(null);
          }
        });
        return;
      }

      audioRef.current = result.audio;
      await playAudio(result.audio);
      URL.revokeObjectURL(result.url);

      if (controller.signal.aborted) break;
    }

    // Done playing all chunks
    if (!controller.signal.aborted) {
      setSpeaking(false);
      setSpeakingId(null);
    }
  }, [stopPlayback]);

  return { speaking, speakingId, speak, stop: stopPlayback };
}
