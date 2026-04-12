import { Mood } from '../hooks/useAmbientMusic';

const MOODS: { value: Mood; label: string; icon: string }[] = [
  { value: 'exploration', label: 'Exploration', icon: '\uD83C\uDF0D' },
  { value: 'tavern', label: 'Tavern', icon: '\uD83C\uDF7A' },
  { value: 'combat', label: 'Combat', icon: '\u2694\uFE0F' },
  { value: 'dungeon', label: 'Dungeon', icon: '\uD83D\uDD73\uFE0F' },
  { value: 'mystical', label: 'Mystical', icon: '\u2728' },
  { value: 'camp', label: 'Camp', icon: '\uD83C\uDF43' },
];

interface AmbientMusicProps {
  playing: boolean;
  mood: Mood;
  volume: number;
  onToggle: () => void;
  onMoodChange: (mood: Mood) => void;
  onVolumeChange: (vol: number) => void;
}

export default function AmbientMusic({ playing, mood, volume, onToggle, onMoodChange, onVolumeChange }: AmbientMusicProps) {
  return (
    <div className="ambient-music">
      <div className="ambient-header">
        <h4>Ambience</h4>
        <button
          onClick={onToggle}
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
                onClick={() => onMoodChange(m.value)}
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
              onChange={e => onVolumeChange(parseFloat(e.target.value))}
              className="vol-slider"
            />
          </div>
        </>
      )}
    </div>
  );
}
