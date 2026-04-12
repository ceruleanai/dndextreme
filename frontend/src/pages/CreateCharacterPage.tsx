import { useState, FormEvent } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { Character } from '../api/types';

const RACES = ['Human', 'Elf', 'Dwarf', 'Halfling', 'Gnome', 'Half-Elf', 'Half-Orc', 'Tiefling', 'Dragonborn'];
const CLASSES = ['Fighter', 'Wizard', 'Rogue', 'Cleric', 'Ranger', 'Paladin', 'Barbarian', 'Bard', 'Druid', 'Monk', 'Sorcerer', 'Warlock'];

const DEFAULT_STATS = { str: 10, dex: 10, con: 10, int: 10, wis: 10, cha: 10 };
const CLASS_HP: Record<string, number> = {
  Barbarian: 12, Fighter: 10, Paladin: 10, Ranger: 10,
  Bard: 8, Cleric: 8, Druid: 8, Monk: 8, Rogue: 8, Warlock: 8,
  Sorcerer: 6, Wizard: 6,
};

export default function CreateCharacterPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [race, setRace] = useState('Human');
  const [charClass, setCharClass] = useState('Fighter');
  const [stats, setStats] = useState(DEFAULT_STATS);
  const [backstory, setBackstory] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const maxHp = (CLASS_HP[charClass] || 8) + Math.floor((stats.con - 10) / 2);

  const updateStat = (key: string, value: number) => {
    setStats(prev => ({ ...prev, [key]: Math.max(1, Math.min(20, value)) }));
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await api.post<Character>(`/campaigns/${id}/characters`, {
        name, race, character_class: charClass, stats,
        hp: maxHp, max_hp: maxHp,
        inventory: ['Backpack', 'Bedroll', 'Rations (5 days)', 'Waterskin', '50 ft. Rope'],
        backstory: backstory || null,
      });
      navigate(`/campaigns/${id}`);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to create character');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="page">
      <h1>Create Character</h1>
      {error && <p className="error">{error}</p>}
      <form onSubmit={handleSubmit} className="form">
        <label>
          Name
          <input type="text" value={name} onChange={e => setName(e.target.value)} placeholder="e.g. Thorin Ironfist" required />
        </label>

        <div className="form-row">
          <label>
            Race
            <select value={race} onChange={e => setRace(e.target.value)}>
              {RACES.map(r => <option key={r} value={r}>{r}</option>)}
            </select>
          </label>
          <label>
            Class
            <select value={charClass} onChange={e => setCharClass(e.target.value)}>
              {CLASSES.map(c => <option key={c} value={c}>{c}</option>)}
            </select>
          </label>
        </div>

        <div className="stats-grid">
          <h3>Ability Scores</h3>
          {Object.entries(stats).map(([key, value]) => (
            <div key={key} className="stat-input">
              <span className="stat-label">{key.toUpperCase()}</span>
              <button type="button" onClick={() => updateStat(key, value - 1)}>-</button>
              <span className="stat-value">{value}</span>
              <button type="button" onClick={() => updateStat(key, value + 1)}>+</button>
              <span className="stat-mod">({value >= 10 ? '+' : ''}{Math.floor((value - 10) / 2)})</span>
            </div>
          ))}
        </div>

        <p className="hp-preview">HP: {maxHp}</p>

        <label>
          Backstory (optional)
          <textarea value={backstory} onChange={e => setBackstory(e.target.value)} placeholder="Tell us about your character's history, motivations, and personality..." rows={4} />
        </label>

        <button type="submit" disabled={loading} className="btn-primary">
          {loading ? 'Creating...' : 'Create Character'}
        </button>
      </form>
    </div>
  );
}
