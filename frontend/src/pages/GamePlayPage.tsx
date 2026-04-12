import { useState, useEffect, useRef, FormEvent } from 'react';
import { useParams, useSearchParams, useNavigate } from 'react-router-dom';
import ReactMarkdown from 'react-markdown';
import { api } from '../api/client';
import { Campaign, Message, GameSession } from '../api/types';

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
  const messagesEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!id || !sessionId) return;
    api.get<Campaign>(`/campaigns/${id}`).then(setCampaign);
    api.get<Message[]>(`/campaigns/${id}/sessions/${sessionId}/messages`).then(setMessages);
  }, [id, sessionId]);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  const sendMessage = async (e: FormEvent) => {
    e.preventDefault();
    if (!input.trim() || sending) return;

    const playerMsg = input.trim();
    setInput('');
    setSending(true);

    // Optimistically add the player message
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
      // Replace optimistic message and add DM response
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
    setSending(true);
    try {
      const session = await api.post<GameSession>(`/campaigns/${id}/sessions/${sessionId}/end`);
      setSessionStatus('ended');
      // Reload messages to get the closing narration
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

  const character = campaign?.characters?.[0];

  return (
    <div className="game-layout">
      {/* Sidebar */}
      <aside className="game-sidebar">
        <button onClick={() => navigate(`/campaigns/${id}`)} className="back-link">Back</button>

        {character && (
          <div className="sidebar-section">
            <h3>{character.name}</h3>
            <p className="char-info">{character.race} {character.character_class} Lvl {character.level}</p>
            <div className="hp-bar">
              <div className="hp-fill" style={{ width: `${(character.hp / character.max_hp) * 100}%` }} />
              <span className="hp-text">HP: {character.hp}/{character.max_hp}</span>
            </div>
            <div className="sidebar-stats">
              {character.stats && Object.entries(character.stats).map(([k, v]) => (
                <span key={k} className="mini-stat">{k.toUpperCase()}: {v as number}</span>
              ))}
            </div>
          </div>
        )}

        <div className="sidebar-section">
          <h4>Dice</h4>
          <div className="dice-grid">
            {[4, 6, 8, 10, 12, 20].map(d => (
              <button key={d} onClick={() => rollDice(d)} className="dice-btn">d{d}</button>
            ))}
          </div>
        </div>

        {sessionStatus === 'active' && (
          <button onClick={endSession} className="btn-end-session" disabled={sending}>
            End Session
          </button>
        )}
      </aside>

      {/* Main Chat Area */}
      <main className="game-main">
        <div className="messages-area">
          {messages.map(msg => (
            <div key={msg.id} className={`message message-${msg.role}`}>
              <div className="message-header">
                {msg.role === 'assistant' ? 'Dungeon Master' : 'You'}
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
            <input
              type="text"
              value={input}
              onChange={e => setInput(e.target.value)}
              placeholder="What do you do?"
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
    </div>
  );
}
