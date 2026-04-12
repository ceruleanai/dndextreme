import { useEffect, useRef } from 'react';
import { useGeminiLive } from '../hooks/useGeminiLive';

interface VoiceModeProps {
  campaignId: string;
  sessionId: string;
  onClose: () => void;
  onTranscriptSaved: () => void;
}

export default function VoiceMode({ campaignId, sessionId, onClose, onTranscriptSaved }: VoiceModeProps) {
  const { status, start, stop, toggleMute, isMuted, transcript, error } = useGeminiLive({
    campaignId,
    sessionId,
  });
  const transcriptEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    transcriptEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [transcript]);

  const handleClose = async () => {
    await stop();
    onTranscriptSaved();
    onClose();
  };

  return (
    <div className="voice-overlay">
      <div className="voice-container">
        <div className="voice-header">
          <h2>Voice Mode</h2>
          <button onClick={handleClose} className="voice-close">Exit</button>
        </div>

        {status === 'idle' && (
          <div className="voice-center">
            <p className="voice-hint">Speak directly with your Dungeon Master</p>
            <button onClick={start} className="voice-start-btn">
              Start Voice Session
            </button>
            {error && <p className="error">{error}</p>}
          </div>
        )}

        {status === 'connecting' && (
          <div className="voice-center">
            <div className="voice-pulse" />
            <p>Connecting to Dungeon Master...</p>
          </div>
        )}

        {status === 'reconnecting' && (
          <div className="voice-center">
            <div className="voice-pulse" />
            <p>Reconnecting...</p>
          </div>
        )}

        {status === 'error' && (
          <div className="voice-center">
            <p className="error">{error}</p>
            <button onClick={start} className="btn-primary">Retry</button>
          </div>
        )}

        {status === 'active' && (
          <>
            <div className="voice-active-area">
              <div className={`voice-indicator ${isMuted ? 'voice-muted' : 'voice-listening'}`}>
                <div className="voice-ring voice-ring-1" />
                <div className="voice-ring voice-ring-2" />
                <div className="voice-ring voice-ring-3" />
                <div className="voice-mic-icon">{isMuted ? '\u{1F507}' : '\u{1F3A4}'}</div>
              </div>

              <div className="voice-controls">
                <button onClick={toggleMute} className={`btn-voice-ctl ${isMuted ? 'btn-unmute' : 'btn-mute'}`}>
                  {isMuted ? 'Unmute' : 'Mute'}
                </button>
                <button onClick={handleClose} className="btn-voice-ctl btn-end-voice">
                  End Voice Mode
                </button>
              </div>
            </div>

            <div className="voice-transcript">
              <h4>Live Transcript</h4>
              <div className="voice-transcript-scroll">
                {transcript.map((entry, i) => (
                  <div key={i} className={`voice-line voice-line-${entry.role}`}>
                    <span className="voice-line-role">
                      {entry.role === 'user' ? 'You' : 'DM'}
                    </span>
                    <span className="voice-line-text">{entry.text}</span>
                  </div>
                ))}
                <div ref={transcriptEndRef} />
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
