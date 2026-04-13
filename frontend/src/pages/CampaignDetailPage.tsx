import { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { api } from '../api/client';
import { Campaign, CampaignPlayer, GameSession } from '../api/types';
import { useAuth } from '../context/AuthContext';

const API_BASE = import.meta.env.VITE_API_URL?.replace('/api', '') || 'http://localhost:8000';

export default function CampaignDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);
  const [copiedInvite, setCopiedInvite] = useState(false);

  useEffect(() => {
    api.get<Campaign>(`/campaigns/${id}`).then(setCampaign).finally(() => setLoading(false));
  }, [id]);

  const isOwner = campaign?.players?.some(p => p.user_id === user?.id && p.role === 'owner');
  const myPlayer = campaign?.players?.find(p => p.user_id === user?.id);

  const startSession = async () => {
    setStarting(true);
    try {
      const session = await api.post<GameSession>(`/campaigns/${id}/sessions`);
      navigate(`/campaigns/${id}/play?session=${session.id}`);
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to start session');
    } finally {
      setStarting(false);
    }
  };

  const resumeSession = () => {
    if (campaign?.active_session) {
      navigate(`/campaigns/${id}/play?session=${campaign.active_session.id}`);
    }
  };

  const copyInviteCode = () => {
    if (!campaign?.invite_code) return;
    navigator.clipboard.writeText(campaign.invite_code);
    setCopiedInvite(true);
    setTimeout(() => setCopiedInvite(false), 2000);
  };

  const selectCharacter = async (characterId: number) => {
    try {
      await api.post(`/campaigns/${id}/players/select-character`, { character_id: characterId });
      const updated = await api.get<Campaign>(`/campaigns/${id}`);
      setCampaign(updated);
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to select character');
    }
  };

  const kickPlayer = async (userId: number) => {
    if (!confirm('Remove this player from the campaign?')) return;
    try {
      await api.post(`/campaigns/${id}/players/kick`, { user_id: userId });
      const updated = await api.get<Campaign>(`/campaigns/${id}`);
      setCampaign(updated);
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to remove player');
    }
  };

  if (loading) return <div className="page"><p>Loading...</p></div>;
  if (!campaign) return <div className="page"><p>Campaign not found</p></div>;

  const hasCharacter = campaign.characters && campaign.characters.length > 0;
  const unclaimedCharacters = campaign.characters?.filter(
    c => !campaign.players?.some(p => p.character_id === c.id)
  ) || [];

  return (
    <div className="page campaign-detail-page">
      <div className="campaign-detail-hero">
        <Link to="/campaigns" className="back-link">Back to Campaigns</Link>
        <h1>{campaign.title}</h1>
        <span className={`status status-${campaign.status}`}>{campaign.status}</span>
      </div>

      <div className="campaign-detail-setting">
        <h2>World Setting</h2>
        <p>{campaign.setting}</p>
      </div>

      {/* Players Section */}
      <section className="section">
        <h2>Party Members</h2>
        <div className="players-list">
          {campaign.players?.map(p => (
            <div key={p.id} className="player-card">
              <div className="player-info">
                <span className="player-name">{p.user?.name || 'Unknown'}</span>
                <span className={`player-role role-${p.role}`}>{p.role}</span>
              </div>
              {p.character ? (
                <div className="player-character">
                  {p.character.portrait_path && (
                    <img
                      src={`${API_BASE}${p.character.portrait_path}`}
                      alt={p.character.name}
                      className="player-char-portrait"
                    />
                  )}
                  <span>{p.character.name} (Lv{p.character.level} {p.character.race} {p.character.character_class})</span>
                </div>
              ) : (
                <span className="player-no-char">No character selected</span>
              )}
              {isOwner && p.role !== 'owner' && (
                <button onClick={() => kickPlayer(p.user_id)} className="btn-kick">Remove</button>
              )}
            </div>
          ))}
        </div>

        {/* Invite Code */}
        {campaign.invite_code && (
          <div className="invite-section">
            <span className="invite-label">Invite Code:</span>
            <code className="invite-code">{campaign.invite_code}</code>
            <button onClick={copyInviteCode} className="btn-copy">
              {copiedInvite ? 'Copied!' : 'Copy'}
            </button>
          </div>
        )}

        {/* Character Selection for non-owner players without a character */}
        {myPlayer && !myPlayer.character_id && unclaimedCharacters.length > 0 && (
          <div className="character-select-section">
            <h3>Select Your Character</h3>
            <div className="character-select-grid">
              {unclaimedCharacters.map(c => (
                <button key={c.id} onClick={() => selectCharacter(c.id)} className="character-select-btn">
                  {c.name} (Lv{c.level} {c.race} {c.character_class})
                </button>
              ))}
            </div>
          </div>
        )}
      </section>

      <section className="section">
        <h2>Characters</h2>
        {hasCharacter ? (
          <div className="character-list">
            {campaign.characters!.map(c => {
              const claimedBy = campaign.players?.find(p => p.character_id === c.id);
              return (
                <div key={c.id} className={`character-card character-card-detail${c.portrait_path ? ' has-portrait' : ''}`}>
                  {c.portrait_path && (
                    <div className="character-card-portrait">
                      <img src={`${API_BASE}${c.portrait_path}`} alt={`${c.name} portrait`} />
                    </div>
                  )}
                  <div className="character-card-header">
                    {!c.portrait_path && (
                      <img
                        src={`/art/classes/${c.character_class.toLowerCase()}.svg`}
                        alt={c.character_class}
                        className="class-icon"
                      />
                    )}
                    <div>
                      <h3>{c.name}</h3>
                      <p className="char-subtitle">{c.race} {c.character_class}</p>
                      {claimedBy && (
                        <p className="char-player-name">Player: {claimedBy.user?.name}</p>
                      )}
                    </div>
                  </div>
                  <div className="character-card-body">
                    <div className="char-level-hp">
                      <span className="char-level">Level {c.level}</span>
                      <div className="hp-bar">
                        <div className="hp-fill" style={{ width: `${(c.hp / c.max_hp) * 100}%` }} />
                        <span className="hp-text">HP: {c.hp}/{c.max_hp}</span>
                      </div>
                    </div>
                    <div className="stats-row">
                      {Object.entries(c.stats).map(([k, v]) => (
                        <span key={k} className="stat">{k.toUpperCase()}: {v}</span>
                      ))}
                    </div>
                    {c.inventory && c.inventory.length > 0 && (
                      <div className="char-inventory">
                        <span className="char-inventory-label">Inventory:</span> {c.inventory.join(', ')}
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        ) : (
          <div className="empty-state-inline">
            <img src="/art/d20.svg" alt="" className="empty-state-icon" />
            <p>No characters yet. Create one to begin your adventure.</p>
          </div>
        )}
        <Link to={`/campaigns/${id}/character/new`} className="btn-secondary">
          {hasCharacter ? 'Add Another Character' : 'Create Character'}
        </Link>
      </section>

      <section className="section adventure-section">
        <div className="adventure-section-inner">
          <h2>Adventure</h2>
          {!hasCharacter ? (
            <p className="adventure-hint">Create a character before starting your adventure.</p>
          ) : campaign.active_session ? (
            <div className="adventure-active">
              <p>Session {campaign.active_session.session_number} is in progress.</p>
              <button onClick={resumeSession} className="btn-primary btn-adventure">Resume Session</button>
            </div>
          ) : isOwner ? (
            <button onClick={startSession} disabled={starting} className="btn-primary btn-adventure">
              {starting ? 'Starting...' : 'Start New Session'}
            </button>
          ) : (
            <p className="adventure-hint">Waiting for the campaign owner to start a session.</p>
          )}
        </div>
      </section>
    </div>
  );
}
