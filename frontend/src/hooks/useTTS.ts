import { useState, useRef, useCallback } from 'react';

const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

export function useTTS() {
  const [speaking, setSpeaking] = useState(false);
  const [speakingId, setSpeakingId] = useState<number | null>(null);
  const audioRef = useRef<HTMLAudioElement | null>(null);

  const speak = useCallback(async (text: string, id?: number) => {
    // Stop any current playback
    stop();

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

    setSpeaking(true);
    setSpeakingId(id ?? null);

    try {
      const token = localStorage.getItem('auth_token');
      const response = await fetch(`${API_BASE}/tts`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'audio/*',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify({ text: cleanText, voice: 'Charon' }),
      });

      if (!response.ok) {
        // Fall back to browser TTS
        browserTTS(cleanText);
        return;
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const audio = new Audio(url);
      audioRef.current = audio;

      audio.onended = () => {
        setSpeaking(false); setSpeakingId(null);
        URL.revokeObjectURL(url);
      };
      audio.onerror = () => {
        setSpeaking(false); setSpeakingId(null);
        URL.revokeObjectURL(url);
        // Fall back to browser TTS on error
        browserTTS(cleanText);
      };

      await audio.play();
    } catch {
      // Fall back to browser TTS
      browserTTS(cleanText);
    }
  }, []);

  const browserTTS = useCallback((text: string) => {
    if (!('speechSynthesis' in window)) {
      setSpeaking(false); setSpeakingId(null);
      return;
    }

    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.rate = 0.9;
    utterance.pitch = 0.8;

    // Try to pick a deep male voice
    const voices = window.speechSynthesis.getVoices();
    const preferred = voices.find(v => v.name.includes('Male') && v.lang.startsWith('en')) ||
      voices.find(v => v.lang.startsWith('en'));
    if (preferred) utterance.voice = preferred;

    utterance.onend = () => setSpeaking(false); setSpeakingId(null);
    utterance.onerror = () => setSpeaking(false); setSpeakingId(null);

    window.speechSynthesis.speak(utterance);
    setSpeaking(true);
  }, []);

  const stop = useCallback(() => {
    if (audioRef.current) {
      audioRef.current.pause();
      audioRef.current = null;
    }
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
    }
    setSpeaking(false); setSpeakingId(null);
  }, []);

  return { speaking, speakingId, speak, stop };
}
