import { useState } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { api } from '../api/client';

export default function JoinCampaignPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [code, setCode] = useState(searchParams.get('code') || '');
  const [joining, setJoining] = useState(false);
  const [error, setError] = useState('');

  const handleJoin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!code.trim()) return;

    setJoining(true);
    setError('');
    try {
      const res = await api.post<{ campaign_id: number; message: string }>('/campaigns/join', {
        invite_code: code.trim().toUpperCase(),
      });
      navigate(`/campaigns/${res.campaign_id}`);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Invalid invite code');
    } finally {
      setJoining(false);
    }
  };

  return (
    <div className="page">
      <Link to="/campaigns" className="back-link">Back to Campaigns</Link>
      <h1>Join a Campaign</h1>
      <p className="join-description">Enter the invite code shared by the campaign owner.</p>

      <form onSubmit={handleJoin} className="join-form">
        <input
          type="text"
          value={code}
          onChange={e => setCode(e.target.value.toUpperCase())}
          placeholder="Enter 6-character code"
          maxLength={6}
          className="join-code-input"
          autoFocus
        />
        {error && <p className="error-text">{error}</p>}
        <button type="submit" disabled={joining || code.length < 6} className="btn-primary">
          {joining ? 'Joining...' : 'Join Campaign'}
        </button>
      </form>
    </div>
  );
}
