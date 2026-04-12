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
    <div className="page">
      <Link to="/campaigns" className="back-link">Back to Campaigns</Link>
      <h1>{campaign.title}</h1>
      <p className="campaign-setting">{campaign.setting}</p>
      <span className={`status status-${campaign.status}`}>{campaign.status}</span>

      <section className="section">
        <h2>Characters</h2>
        {hasCharacter ? (
          <div className="character-list">
            {campaign.characters!.map(c => (
              <div key={c.id} className="character-card">
                <h3>{c.name}</h3>
                <p>{c.race} {c.character_class} (Level {c.level})</p>
                <p>HP: {c.hp}/{c.max_hp}</p>
                <div className="stats-row">
                  {Object.entries(c.stats).map(([k, v]) => (
                    <span key={k} className="stat">{k.toUpperCase()}: {v}</span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p>No characters yet.</p>
        )}
        <Link to={`/campaigns/${id}/character/new`} className="btn-secondary">
          {hasCharacter ? 'Add Another Character' : 'Create Character'}
        </Link>
      </section>

      <section className="section">
        <h2>Adventure</h2>
        {!hasCharacter ? (
          <p>Create a character before starting your adventure.</p>
        ) : campaign.active_session ? (
          <div>
            <p>Session {campaign.active_session.session_number} is in progress.</p>
            <button onClick={resumeSession} className="btn-primary">Resume Session</button>
          </div>
        ) : (
          <button onClick={startSession} disabled={starting} className="btn-primary">
            {starting ? 'Starting...' : 'Start New Session'}
          </button>
        )}
      </section>
    </div>
  );
}
