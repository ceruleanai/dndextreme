import { useState, useRef, useCallback, useEffect } from 'react';
import { api } from '../api/client';

type Status = 'idle' | 'connecting' | 'active' | 'reconnecting' | 'error';

interface TranscriptEntry {
  role: 'user' | 'assistant';
  text: string;
}

interface VoiceConfigResponse {
  apiKey: string;
  systemPrompt: string;
  recentMessages: Array<{ role: string; content: string }>;
  model: string;
}

interface UseGeminiLiveOptions {
  campaignId: string;
  sessionId: string;
  voice?: string;
}

function arrayBufferToBase64(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary);
}

function base64ToFloat32(base64: string): Float32Array {
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i);
  }
  const int16 = new Int16Array(bytes.buffer);
  const float32 = new Float32Array(int16.length);
  for (let i = 0; i < int16.length; i++) {
    float32[i] = int16[i] / 32768;
  }
  return float32;
}

export function useGeminiLive({ campaignId, sessionId, voice = 'Charon' }: UseGeminiLiveOptions) {
  const [status, setStatus] = useState<Status>('idle');
  const [transcript, setTranscript] = useState<TranscriptEntry[]>([]);
  const [isMuted, setIsMuted] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const wsRef = useRef<WebSocket | null>(null);
  const micStreamRef = useRef<MediaStream | null>(null);
  const audioContextRef = useRef<AudioContext | null>(null);
  const workletNodeRef = useRef<AudioWorkletNode | null>(null);
  const playbackContextRef = useRef<AudioContext | null>(null);
  const playbackTimeRef = useRef(0);
  const reconnectTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const transcriptRef = useRef<TranscriptEntry[]>([]);
  const configRef = useRef<VoiceConfigResponse | null>(null);

  useEffect(() => {
    transcriptRef.current = transcript;
  }, [transcript]);

  const saveTranscript = useCallback(async (entries: TranscriptEntry[]) => {
    if (entries.length === 0) return;
    try {
      await api.post(`/campaigns/${campaignId}/sessions/${sessionId}/voice-transcript`, {
        exchanges: entries,
      });
    } catch (err) {
      console.error('Failed to save transcript:', err);
    }
  }, [campaignId, sessionId]);

  const playAudioChunk = useCallback((base64Data: string) => {
    if (!playbackContextRef.current) {
      playbackContextRef.current = new AudioContext({ sampleRate: 24000 });
    }
    const ctx = playbackContextRef.current;
    const float32 = base64ToFloat32(base64Data);

    const buffer = ctx.createBuffer(1, float32.length, 24000);
    buffer.getChannelData(0).set(float32);

    const source = ctx.createBufferSource();
    source.buffer = buffer;
    source.connect(ctx.destination);

    const now = ctx.currentTime;
    const startTime = Math.max(now, playbackTimeRef.current);
    source.start(startTime);
    playbackTimeRef.current = startTime + buffer.duration;
  }, []);

  const stopMic = useCallback(() => {
    if (workletNodeRef.current) {
      workletNodeRef.current.disconnect();
      workletNodeRef.current = null;
    }
    if (micStreamRef.current) {
      micStreamRef.current.getTracks().forEach(t => t.stop());
      micStreamRef.current = null;
    }
    if (audioContextRef.current) {
      audioContextRef.current.close().catch(() => {});
      audioContextRef.current = null;
    }
  }, []);

  const stop = useCallback(async () => {
    if (reconnectTimerRef.current) {
      clearTimeout(reconnectTimerRef.current);
      reconnectTimerRef.current = null;
    }

    if (wsRef.current) {
      wsRef.current.close(1000);
      wsRef.current = null;
    }

    stopMic();

    if (playbackContextRef.current) {
      await playbackContextRef.current.close().catch(() => {});
      playbackContextRef.current = null;
      playbackTimeRef.current = 0;
    }

    const remaining = transcriptRef.current;
    if (remaining.length > 0) {
      await saveTranscript(remaining);
    }

    setStatus('idle');
  }, [saveTranscript, stopMic]);

  const startMic = useCallback(async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          sampleRate: 16000,
          channelCount: 1,
          echoCancellation: true,
          noiseSuppression: true,
        },
      });
      micStreamRef.current = stream;

      const audioCtx = new AudioContext({ sampleRate: 16000 });
      audioContextRef.current = audioCtx;

      await audioCtx.audioWorklet.addModule('/audio-processor.js');

      const source = audioCtx.createMediaStreamSource(stream);
      const worklet = new AudioWorkletNode(audioCtx, 'pcm-processor');
      workletNodeRef.current = worklet;

      worklet.port.onmessage = (event: MessageEvent<ArrayBuffer>) => {
        if (wsRef.current?.readyState === WebSocket.OPEN) {
          const base64 = arrayBufferToBase64(event.data);
          wsRef.current.send(JSON.stringify({
            realtimeInput: {
              mediaChunks: [{
                mimeType: 'audio/pcm;rate=16000',
                data: base64,
              }],
            },
          }));
        }
      };

      source.connect(worklet);
      // Connect to a silent destination so the worklet processes
      worklet.connect(audioCtx.destination);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Microphone access denied');
      setStatus('error');
    }
  }, []);

  const connect = useCallback(async () => {
    setError(null);

    try {
      setStatus('connecting');

      // Get voice config (API key + context) from backend
      if (!configRef.current) {
        console.log('[GeminiLive] Fetching voice config...');
        configRef.current = await api.post<VoiceConfigResponse>(
          `/campaigns/${campaignId}/sessions/${sessionId}/voice-config`
        );
        console.log('[GeminiLive] Voice config received, model:', configRef.current.model);
      }
      const config = configRef.current;

      // Connect directly to Gemini Live API with API key
      const wsUrl = `wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent?key=${config.apiKey}`;
      const ws = new WebSocket(wsUrl);
      wsRef.current = ws;

      ws.onopen = () => {
        console.log('[GeminiLive] WebSocket opened, sending setup...');
        const setup = {
          setup: {
            model: `models/${config.model}`,
            generationConfig: {
              responseModalities: ['AUDIO'],
              speechConfig: {
                voiceConfig: {
                  prebuiltVoiceConfig: { voiceName: voice },
                },
              },
            },
            systemInstruction: {
              parts: [{ text: config.systemPrompt }],
            },
            inputAudioTranscription: {},
            outputAudioTranscription: {},
          },
        };
        ws.send(JSON.stringify(setup));
      };

      ws.onmessage = async (event) => {
        let raw = event.data;
        // Handle Blob responses from WebSocket
        if (raw instanceof Blob) {
          raw = await raw.text();
        }
        const data = JSON.parse(raw);

        if (data.setupComplete !== undefined) {
          console.log('[GeminiLive] Setup complete, starting mic...');
          setStatus('active');
          startMic();

          // Schedule reconnect at 14 minutes
          reconnectTimerRef.current = setTimeout(async () => {
            await saveTranscript(transcriptRef.current);
            setTranscript([]);
            configRef.current = null; // Refresh config on reconnect
            setStatus('reconnecting');
            stopMic();
            if (wsRef.current) {
              wsRef.current.close(1000);
              wsRef.current = null;
            }
            setTimeout(() => connect(), 1000);
          }, 14 * 60 * 1000);
        }

        // Audio from model
        if (data.serverContent?.modelTurn?.parts) {
          for (const part of data.serverContent.modelTurn.parts) {
            if (part.inlineData?.data) {
              playAudioChunk(part.inlineData.data);
            }
          }
        }

        // Input transcription
        if (data.serverContent?.inputTranscription?.text) {
          const text = data.serverContent.inputTranscription.text.trim();
          if (text) {
            setTranscript(prev => [...prev, { role: 'user', text }]);
          }
        }

        // Output transcription
        if (data.serverContent?.outputTranscription?.text) {
          const text = data.serverContent.outputTranscription.text.trim();
          if (text) {
            setTranscript(prev => {
              const last = prev[prev.length - 1];
              if (last && last.role === 'assistant') {
                return [...prev.slice(0, -1), { role: 'assistant', text: last.text + text }];
              }
              return [...prev, { role: 'assistant', text }];
            });
          }
        }
      };

      ws.onerror = (e) => {
        console.error('[GeminiLive] WebSocket error:', e);
        setError('WebSocket connection failed. Check your API key and network.');
        setStatus('error');
      };

      ws.onclose = (event) => {
        console.log('[GeminiLive] WebSocket closed:', event.code, event.reason);
        if (event.code !== 1000 && event.code !== 0) {
          setError(`Connection closed (code ${event.code}): ${event.reason || 'Unknown reason'}`);
          setStatus('error');
        }
      };

    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to connect');
      setStatus('error');
    }
  }, [campaignId, sessionId, voice, playAudioChunk, saveTranscript, startMic, stopMic]);

  const start = useCallback(async () => {
    setTranscript([]);
    configRef.current = null;
    await connect();
  }, [connect]);

  const toggleMute = useCallback(() => {
    setIsMuted(prev => {
      const newMuted = !prev;
      if (micStreamRef.current) {
        micStreamRef.current.getAudioTracks().forEach(t => {
          t.enabled = !newMuted;
        });
      }
      return newMuted;
    });
  }, []);

  useEffect(() => {
    return () => {
      if (wsRef.current) wsRef.current.close(1000);
      if (micStreamRef.current) micStreamRef.current.getTracks().forEach(t => t.stop());
      if (audioContextRef.current) audioContextRef.current.close().catch(() => {});
      if (playbackContextRef.current) playbackContextRef.current.close().catch(() => {});
      if (reconnectTimerRef.current) clearTimeout(reconnectTimerRef.current);
    };
  }, []);

  return { status, start, stop, toggleMute, isMuted, transcript, error };
}
