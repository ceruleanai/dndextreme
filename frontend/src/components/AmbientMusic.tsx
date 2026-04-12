import { useAmbientMusic, Mood } from '../hooks/useAmbientMusic';

const MOODS: { value: Mood; label: string; icon: string }[] = [
  { value: 'exploration', label: 'Exploration', icon: '\uD83C\uDF0D' },
  { value: 'tavern', label: 'Tavern', icon: '\uD83C\uDF7A' },
  { value: 'combat', label: 'Combat', icon: '\u2694\uFE0F' },
  { value: 'dungeon', label: 'Dungeon', icon: '\uD83D\uDD73\uFE0F' },
  { value: 'mystical', label: 'Mystical', icon: '\u2728' },
  { value: 'camp', label: 'Camp', icon: '\uD83C\uDF43' },
];

export default function AmbientMusic() {
  const { playing, mood, volume, start, stop, setMood, setVolume } = useAmbientMusic();

  return (
    <div className="ambient-music">
      <div className="ambient-header">
        <h4>Ambience</h4>
        <button
          onClick={() => playing ? stop() : start()}
          className={`ambient-toggle ${playing ? 'ambient-on' : ''}`}
        >
          {playing ? 'ON' : 'OFF'}
        </button>
      </div>

      {playing && (
        <>
          <div className="ambient-moods">
            {MOODS.map(m => (
              <button
                key={m.value}
                onClick={() => setMood(m.value)}
                className={`mood-btn ${mood === m.value ? 'mood-active' : ''}`}
                title={m.label}
              >
                <span className="mood-icon">{m.icon}</span>
                <span className="mood-label">{m.label}</span>
              </button>
            ))}
          </div>
          <div className="ambient-volume">
            <span className="vol-label">Vol</span>
            <input
              type="range"
              min="0"
              max="1"
              step="0.05"
              value={volume}
              onChange={e => setVolume(parseFloat(e.target.value))}
              className="vol-slider"
            />
          </div>
        </>
      )}
    </div>
  );
}
