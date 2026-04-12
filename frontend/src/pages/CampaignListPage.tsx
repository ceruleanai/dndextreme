import { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { Campaign } from '../api/types';
import { useAuth } from '../context/AuthContext';

export default function CampaignListPage() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get<Campaign[]>('/campaigns').then(setCampaigns).finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="page"><p>Loading campaigns...</p></div>;

  return (
    <div className="page">
      <div className="page-hero">
        <header className="page-header">
          <h1>Your Campaigns</h1>
          <div className="header-actions">
            <span>Welcome, {user?.name}</span>
            <button onClick={() => navigate('/campaigns/new')} className="btn-primary">New Campaign</button>
            <button onClick={logout} className="btn-secondary">Logout</button>
          </div>
        </header>
      </div>

      {campaigns.length === 0 ? (
        <div className="empty-state">
          <img src="/art/d20.svg" alt="" className="empty-state-icon" />
          <p>No campaigns yet. Create your first adventure!</p>
          <Link to="/campaigns/new" className="btn-primary">Create Campaign</Link>
        </div>
      ) : (
        <div className="campaign-grid">
          {campaigns.map(c => (
            <Link to={`/campaigns/${c.id}`} key={c.id} className="campaign-card">
              <h3>{c.title}</h3>
              <p className="campaign-setting">{c.setting.substring(0, 120)}...</p>
              <div className="campaign-meta">
                <span className={`status status-${c.status}`}>{c.status}</span>
                <span>{c.characters_count} character(s)</span>
                <span>{c.sessions_count} session(s)</span>
              </div>
            </Link>
          ))}
        </div>
      )}

      <div className="credits">
        Art: Photos by <a href="https://unsplash.com" target="_blank" rel="noopener">Unsplash</a> contributors.
        Icons by <a href="https://game-icons.net" target="_blank" rel="noopener">game-icons.net</a> (CC BY 3.0) &mdash; Lorc, Delapouite.
        Music from <a href="https://opengameart.org" target="_blank" rel="noopener">OpenGameArt.org</a> (CC0).
      </div>
    </div>
  );
}
