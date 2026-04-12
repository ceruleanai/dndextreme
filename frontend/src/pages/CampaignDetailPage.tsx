import { useState, useEffect } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { api } from '../api/client';
import { Campaign, GameSession } from '../api/types';

export default function CampaignDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [loading, setLoading] = useState(true);
  const [starting, setStarting] = useState(false);

  useEffect(() => {
    api.get<Campaign>(`/campaigns/${id}`).then(setCampaign).finally(() => setLoading(false));
  }, [id]);

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

  if (loading) return <div className="page"><p>Loading...</p></div>;
  if (!campaign) return <div className="page"><p>Campaign not found</p></div>;

  const hasCharacter = campaign.characters && campaign.characters.length > 0;

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

      <section className="section">
        <h2>Characters</h2>
        {hasCharacter ? (
          <div className="character-list">
            {campaign.characters!.map(c => (
              <div key={c.id} className="character-card character-card-detail">
                <div className="character-card-header">
                  <img
                    src={`/art/classes/${c.character_class.toLowerCase()}.svg`}
                    alt={c.character_class}
                    className="class-icon"
                  />
                  <div>
                    <h3>{c.name}</h3>
                    <p className="char-subtitle">{c.race} {c.character_class}</p>
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
            ))}
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
          ) : (
            <button onClick={startSession} disabled={starting} className="btn-primary btn-adventure">
              {starting ? 'Starting...' : 'Start New Session'}
            </button>
          )}
        </div>
      </section>
    </div>
  );
}
