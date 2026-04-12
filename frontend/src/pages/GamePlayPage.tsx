import React, { useState, useEffect, useRef, useCallback, FormEvent, Suspense, lazy } from 'react';
import { useParams, useSearchParams, useNavigate } from 'react-router-dom';
import ReactMarkdown from 'react-markdown';
import { api } from '../api/client';
import { Campaign, Character, Message, GameSession, MapMetadata, CampaignMap, CombatEncounter } from '../api/types';
import { useVoiceInput } from '../hooks/useVoiceInput';
import { useTTS } from '../hooks/useTTS';
import { useAmbientMusic, Mood } from '../hooks/useAmbientMusic';
import VoiceMode from '../components/VoiceMode';
import AmbientMusic from '../components/AmbientMusic';
import CharacterPanel from '../components/CharacterPanel';
import SpellPanel from '../components/SpellPanel';
import CombatTracker from '../components/CombatTracker';
const MapPanel = lazy(() => import('../components/MapPanel'));

const VALID_MOODS: Mood[] = ['exploration', 'tavern', 'combat', 'dungeon', 'mystical', 'camp'];

export default function GamePlayPage() {
  const { id } = useParams<{ id: string }>();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const sessionId = searchParams.get('session');

  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [sessionStatus, setSessionStatus] = useState<string>('active');
  const [voiceModeActive, setVoiceModeActive] = useState(false);
  const [currentMap, setCurrentMap] = useState<MapMetadata | null>(null);
  const [combatData, setCombatData] = useState<CombatEncounter | null>(null);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  const { speaking, speakingId, speak, stop: stopSpeaking } = useTTS();
  const ambient = useAmbientMusic();

  const onVoiceResult = useCallback((text: string) => {
    setInput(prev => prev + (prev ? ' ' : '') + text);
  }, []);
  const { listening, toggle: toggleVoice, isSupported: voiceSupported } = useVoiceInput(onVoiceResult);

  useEffect(() => {
    if (!id || !sessionId) return;
    api.get<Campaign>(`/campaigns/${id}`).then(setCampaign);
    api.get<Message[]>(`/campaigns/${id}/sessions/${sessionId}/messages`).then(setMessages);
    // Load current map
    api.get<CampaignMap | null>(`/campaigns/${id}/maps/current`).then(map => {
      if (map && map.id && map.image_path) setCurrentMap({
        id: map.id, image_path: map.image_path,
        location_name: map.location_name, category: map.category, map_type: map.map_type,
      });
    }).catch(() => {});
    // Load combat state
    api.get<CombatEncounter>(`/campaigns/${id}/sessions/${sessionId}/combat`)
      .then(setCombatData)
      .catch(() => setCombatData(null));
  }, [id, sessionId]);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // Duck ambient music when narration is playing
  useEffect(() => {
    if (speaking) {
      ambient.duck();
    } else {
      ambient.unduck();
    }
  }, [speaking]); // eslint-disable-line react-hooks/exhaustive-deps

  // Auto-switch ambient mood and map when DM sends a message
  useEffect(() => {
    if (messages.length === 0) return;
    const lastMsg = messages[messages.length - 1];
    if (lastMsg.role === 'assistant' && lastMsg.metadata) {
      const mood = lastMsg.metadata.mood as string;
      if (mood && VALID_MOODS.includes(mood as Mood)) {
        ambient.setMood(mood as Mood);
      }
      // Update map if the DM changed location
      const map = lastMsg.metadata.map as MapMetadata | undefined;
      if (map) {
        setCurrentMap(map);
      }
    }
  }, [messages]); // eslint-disable-line react-hooks/exhaustive-deps

  const sendMessage = async (e: FormEvent) => {
    e.preventDefault();
    if (!input.trim() || sending) return;

    stopSpeaking();

    const playerMsg = input.trim();
    setInput('');
    setSending(true);

    const tempPlayerMsg: Message = {
      id: Date.now(),
      role: 'user',
      type: 'action',
      content: playerMsg,
      metadata: null,
      created_at: new Date().toISOString(),
    };
    setMessages(prev => [...prev, tempPlayerMsg]);

    try {
      const dmResponse = await api.post<Message>(`/campaigns/${id}/sessions/${sessionId}/message`, {
        message: playerMsg,
      });
      setMessages(prev => {
        const withoutTemp = prev.filter(m => m.id !== tempPlayerMsg.id);
        return [...withoutTemp, { ...tempPlayerMsg, id: dmResponse.id - 1 }, dmResponse];
      });
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to send message');
    } finally {
      setSending(false);
    }
  };

  const endSession = async () => {
    if (!confirm('End this session? The DM will generate a summary.')) return;
    stopSpeaking();
    setSending(true);
    try {
      await api.post<GameSession>(`/campaigns/${id}/sessions/${sessionId}/end`);
      setSessionStatus('ended');
      const msgs = await api.get<Message[]>(`/campaigns/${id}/sessions/${sessionId}/messages`);
      setMessages(msgs);
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to end session');
    } finally {
      setSending(false);
    }
  };

  const rollDice = (sides: number) => {
    const result = Math.floor(Math.random() * sides) + 1;
    setInput(prev => prev + (prev ? ' ' : '') + `[Rolled d${sides}: ${result}]`);
  };

  const [character, setCharacter] = useState<Character | null>(null);

  // Load full character sheet
  useEffect(() => {
    if (!campaign?.characters?.[0]) return;
    const char = campaign.characters[0];
    api.get<Character>(`/campaigns/${id}/characters/${char.id}`).then(setCharacter);
  }, [campaign, id]);

  return (
    <div className="game-layout">
      {/* Sidebar */}
      <aside className="game-sidebar">
        <button onClick={() => navigate(`/campaigns/${id}`)} className="back-link">Back</button>

        {character && (
          <CharacterPanel
            character={character}
            campaignId={id!}
            onUpdate={setCharacter}
          />
        )}

        {character && (
          <SpellPanel
            character={character}
            campaignId={id!}
            onUpdate={setCharacter}
          />
        )}

        {sessionId && (
          <CombatTracker
            campaignId={id!}
            sessionId={sessionId}
          />
        )}

        <div className="sidebar-section">
          <h4>Dice</h4>
          <div className="dice-grid">
            {[4, 6, 8, 10, 12, 20].map(d => (
              <button key={d} onClick={() => rollDice(d)} className="dice-btn">d{d}</button>
            ))}
          </div>
        </div>

        <div className="sidebar-section">
          <h4>Voice</h4>
          {speaking && (
            <button onClick={stopSpeaking} className="btn-voice btn-stop-voice">
              Stop Narration
            </button>
          )}
          {sessionStatus === 'active' && (
            <button
              onClick={() => { stopSpeaking(); setVoiceModeActive(true); }}
              className="btn-voice-mode"
            >
              Voice Mode
            </button>
          )}
        </div>

        <AmbientMusic
          playing={ambient.playing}
          mood={ambient.mood}
          volume={ambient.volume}
          onToggle={() => ambient.playing ? ambient.stop() : ambient.start()}
          onMoodChange={ambient.setMood}
          onVolumeChange={ambient.setVolume}
        />

        {sessionStatus === 'active' && (
          <button onClick={endSession} className="btn-end-session" disabled={sending}>
            End Session
          </button>
        )}
      </aside>

      {/* Main Chat Area */}
      <main className="game-main">
        {(currentMap || combatData?.status === 'active') && (
          <Suspense fallback={<div className="map-placeholder"><p>Loading map...</p></div>}>
            <MapPanel
              campaignId={id!}
              mapData={currentMap}
              combatants={combatData?.initiative_order}
              combatActive={combatData?.status === 'active'}
              currentTurn={combatData?.current_turn}
            />
          </Suspense>
        )}
        <div className="messages-area">
          {messages.map(msg => (
            <div key={msg.id} className={`message message-${msg.role}`}>
              <div className="message-header">
                <span>{msg.role === 'assistant' ? 'Dungeon Master' : 'You'}</span>
                {msg.role === 'assistant' && (
                  <button
                    className={`btn-speak-msg ${speakingId === msg.id ? 'btn-speak-active' : ''}`}
                    onClick={() => speakingId === msg.id ? stopSpeaking() : speak(msg.content, msg.id)}
                    title={speakingId === msg.id ? 'Stop' : 'Read aloud'}
                  >
                    {speakingId === msg.id ? '\u23F9' : '\u25B6'}
                  </button>
                )}
              </div>
              <div className="message-content">
                {msg.role === 'assistant' ? (
                  <ReactMarkdown>{msg.content}</ReactMarkdown>
                ) : (
                  <p>{msg.content}</p>
                )}
              </div>
            </div>
          ))}
          {sending && (
            <div className="message message-assistant">
              <div className="message-header">Dungeon Master</div>
              <div className="message-content typing">
                <span className="dot" /><span className="dot" /><span className="dot" />
              </div>
            </div>
          )}
          <div ref={messagesEndRef} />
        </div>

        {sessionStatus === 'active' && (
          <form onSubmit={sendMessage} className="input-area">
            {voiceSupported && (
              <button
                type="button"
                onClick={toggleVoice}
                className={`btn-mic ${listening ? 'btn-mic-active' : ''}`}
                title={listening ? 'Stop listening' : 'Voice input'}
              >
                {listening ? '\u23F9' : '\uD83C\uDF99'}
              </button>
            )}
            <input
              type="text"
              value={input}
              onChange={e => setInput(e.target.value)}
              placeholder={listening ? 'Listening...' : 'What do you do?'}
              disabled={sending}
              autoFocus
            />
            <button type="submit" disabled={sending || !input.trim()}>Send</button>
          </form>
        )}

        {sessionStatus === 'ended' && (
          <div className="session-ended">
            <p>Session ended. <button onClick={() => navigate(`/campaigns/${id}`)}>Back to Campaign</button></p>
          </div>
        )}
      </main>

      {voiceModeActive && id && sessionId && (
        <VoiceMode
          campaignId={id}
          sessionId={sessionId}
          onClose={() => setVoiceModeActive(false)}
          onTranscriptSaved={() => {
            api.get<Message[]>(`/campaigns/${id}/sessions/${sessionId}/messages`).then(setMessages);
          }}
        />
      )}
    </div>
  );
}
