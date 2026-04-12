import { useState, FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { Campaign } from '../api/types';

export default function CreateCampaignPage() {
  const navigate = useNavigate();
  const [title, setTitle] = useState('');
  const [setting, setSetting] = useState('');
  const [aiProvider, setAiProvider] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const campaign = await api.post<Campaign>('/campaigns', {
        title,
        setting,
        ai_provider: aiProvider || null,
      });
      navigate(`/campaigns/${campaign.id}`);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to create campaign');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="page">
      <h1>Create New Campaign</h1>
      {error && <p className="error">{error}</p>}
      <form onSubmit={handleSubmit} className="form">
        <label>
          Campaign Title
          <input type="text" value={title} onChange={e => setTitle(e.target.value)} placeholder="e.g. The Lost Mines of Phandelver" required />
        </label>
        <label>
          World Setting
          <textarea value={setting} onChange={e => setSetting(e.target.value)} placeholder="Describe the world, tone, and any specific details for the DM. e.g. A dark fantasy world where an ancient evil stirs beneath the Spine of the World mountains..." rows={6} required />
        </label>
        <label>
          AI Provider (optional)
          <select value={aiProvider} onChange={e => setAiProvider(e.target.value)}>
            <option value="">Default</option>
            <option value="claude">Claude</option>
            <option value="gemini">Gemini</option>
          </select>
        </label>
        <button type="submit" disabled={loading} className="btn-primary">
          {loading ? 'Creating...' : 'Create Campaign'}
        </button>
      </form>
    </div>
  );
}
