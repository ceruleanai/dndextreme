import { useState } from 'react';
import { Character, RestResult, LevelUpResult } from '../api/types';
import { api } from '../api/client';

interface Props {
  character: Character;
  campaignId: string;
  onUpdate: (char: Character) => void;
}

export default function CharacterPanel({ character, campaignId, onUpdate }: Props) {
  const [showDetails, setShowDetails] = useState(false);
  const [resting, setResting] = useState(false);

  const abilityMod = (score: number) => {
    const mod = Math.floor((score - 10) / 2);
    return mod >= 0 ? `+${mod}` : `${mod}`;
  };

  const refreshCharacter = async () => {
    const updated = await api.get<Character>(`/campaigns/${campaignId}/characters/${character.id}`);
    onUpdate(updated);
  };

  const doShortRest = async () => {
    setResting(true);
    try {
      await api.post<RestResult>(`/campaigns/${campaignId}/characters/${character.id}/short-rest`, {
        hit_dice: 1,
      });
      await refreshCharacter();
    } catch { /* ignore */ } finally {
      setResting(false);
    }
  };

  const doLongRest = async () => {
    if (!confirm('Take a long rest? This restores HP, spell slots, and hit dice.')) return;
    setResting(true);
    try {
      await api.post<RestResult>(`/campaigns/${campaignId}/characters/${character.id}/long-rest`);
      await refreshCharacter();
    } catch { /* ignore */ } finally {
      setResting(false);
    }
  };

  const doLevelUp = async () => {
    try {
      const result = await api.post<LevelUpResult>(`/campaigns/${campaignId}/characters/${character.id}/level-up`);
      if (result.error) {
        alert(result.error);
      } else {
        alert(`Level up! Now level ${result.new_level}. HP +${result.hp_gained}.${result.has_asi ? ' You have an ASI available!' : ''}`);
        await refreshCharacter();
      }
    } catch { /* ignore */ }
  };

  const hpPercent = Math.max(0, Math.min(100, (character.hp / character.max_hp) * 100));
  const hpColor = hpPercent > 50 ? '#4ade80' : hpPercent > 25 ? '#facc15' : '#ef4444';

  return (
    <div className="char-panel">
      <div className="char-panel-header" onClick={() => setShowDetails(!showDetails)}>
        <img
          src={`/art/classes/${character.character_class.toLowerCase()}.svg`}
          alt={character.character_class}
          className="sidebar-class-icon"
        />
        <div>
          <h3 className="char-panel-name">{character.name}</h3>
          <p className="char-info">
            {character.race} {character.character_class} Lvl {character.level}
          </p>
        </div>
        <span className="char-panel-toggle">{showDetails ? '\u25B2' : '\u25BC'}</span>
      </div>

      {/* HP Bar */}
      <div className="hp-bar">
        <div className="hp-fill" style={{ width: `${hpPercent}%`, backgroundColor: hpColor }} />
        <span className="hp-text">
          HP: {character.hp}/{character.max_hp}
          {character.temp_hp > 0 && ` (+${character.temp_hp} temp)`}
        </span>
      </div>

      {/* Quick Stats Row */}
      <div className="char-quick-stats">
        <span title="Armor Class">AC {character.armor_class}</span>
        <span title="Proficiency Bonus">Prof +{character.proficiency_bonus}</span>
        <span title="Speed">Speed {character.speed}ft</span>
        <span title="Gold">Gold {character.gold}</span>
      </div>

      {/* Ability Scores */}
      <div className="sidebar-stats">
        {Object.entries(character.stats).map(([k, v]) => (
          <span key={k} className="mini-stat" title={`${k.toUpperCase()}: ${v}`}>
            <strong>{k.toUpperCase()}</strong>
            <br />
            {v} ({abilityMod(v as number)})
          </span>
        ))}
      </div>

      {showDetails && (
        <div className="char-details">
          {/* XP */}
          <div className="char-detail-row">
            <span>XP: {character.xp}</span>
            {character.xp_to_next_level !== undefined && character.xp_to_next_level > 0 && (
              <span className="char-detail-sub">Next: {character.xp_to_next_level} more</span>
            )}
          </div>

          {/* Hit Dice */}
          <div className="char-detail-row">
            <span>Hit Dice: {character.hit_dice_remaining}/{character.hit_dice_total} d{character.hit_die || 8}</span>
          </div>

          {/* Spell Slots */}
          {character.spell_slots_max && Object.keys(character.spell_slots_max).length > 0 && (
            <div className="char-detail-section">
              <h4>Spell Slots</h4>
              {character.spell_save_dc && (
                <p className="char-detail-sub">
                  Save DC: {character.spell_save_dc} | Attack: +{character.spell_attack_mod}
                </p>
              )}
              <div className="spell-slots-grid">
                {Object.entries(character.spell_slots_max).map(([lvl, max]) => {
                  const current = character.spell_slots?.[lvl] ?? 0;
                  return (
                    <div key={lvl} className="spell-slot-row">
                      <span>Lvl {lvl}:</span>
                      <div className="slot-pips">
                        {Array.from({ length: max }, (_, i) => (
                          <span key={i} className={`slot-pip ${i < current ? 'slot-filled' : 'slot-empty'}`} />
                        ))}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Conditions */}
          {character.conditions && character.conditions.length > 0 && (
            <div className="char-detail-section">
              <h4>Conditions</h4>
              <div className="condition-tags">
                {character.conditions.map(c => (
                  <span key={c.name} className="condition-tag">{c.name}</span>
                ))}
              </div>
            </div>
          )}

          {/* Class Features */}
          {character.class_features && character.class_features.length > 0 && (
            <div className="char-detail-section">
              <h4>Features</h4>
              <p className="char-detail-sub">{character.class_features.join(', ')}</p>
            </div>
          )}

          {/* Death Saves */}
          {character.hp === 0 && (
            <div className="char-detail-section death-saves">
              <h4>Death Saves</h4>
              <div>
                <span>Successes: </span>
                {[0, 1, 2].map(i => (
                  <span key={i} className={`death-pip ${i < character.death_save_successes ? 'death-success' : ''}`} />
                ))}
              </div>
              <div>
                <span>Failures: </span>
                {[0, 1, 2].map(i => (
                  <span key={i} className={`death-pip ${i < character.death_save_failures ? 'death-failure' : ''}`} />
                ))}
              </div>
            </div>
          )}

          {/* Actions */}
          <div className="char-actions">
            <button onClick={doShortRest} disabled={resting} className="btn-rest">Short Rest</button>
            <button onClick={doLongRest} disabled={resting} className="btn-rest">Long Rest</button>
            {character.can_level_up && (
              <button onClick={doLevelUp} className="btn-level-up">Level Up!</button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
