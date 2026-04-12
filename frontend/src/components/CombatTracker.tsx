import { useState, useEffect } from 'react';
import { CombatEncounter } from '../api/types';
import { api } from '../api/client';

interface Props {
  campaignId: string;
  sessionId: string;
}

export default function CombatTracker({ campaignId, sessionId }: Props) {
  const [combat, setCombat] = useState<CombatEncounter | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchCombat = async () => {
    try {
      const data = await api.get<CombatEncounter | null>(
        `/campaigns/${campaignId}/sessions/${sessionId}/combat`
      );
      setCombat(data);
    } catch {
      setCombat(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchCombat();
    const interval = setInterval(fetchCombat, 10000);
    return () => clearInterval(interval);
  }, [campaignId, sessionId]); // eslint-disable-line react-hooks/exhaustive-deps

  if (loading || !combat) return null;

  const currentCombatant = combat.initiative_order[combat.current_turn];

  return (
    <div className="sidebar-section combat-tracker">
      <h4>Combat - Round {combat.round}</h4>

      <div className="initiative-list">
        {combat.initiative_order.map((c, i) => {
          const isCurrent = i === combat.current_turn;
          const isDead = c.hp <= 0;
          const hpPercent = Math.max(0, (c.hp / c.max_hp) * 100);

          return (
            <div
              key={i}
              className={`initiative-item ${isCurrent ? 'initiative-active' : ''} ${isDead ? 'initiative-dead' : ''}`}
            >
              <div className="init-name">
                {isCurrent && <span className="init-arrow">{'\u25B6'}</span>}
                <span>{c.name}</span>
                {c.is_player && <span className="init-player-badge">PC</span>}
              </div>
              <div className="init-stats">
                <span className="init-hp">
                  {isDead ? 'DEAD' : `${c.hp}/${c.max_hp}`}
                </span>
                <div className="init-hp-bar">
                  <div
                    className="init-hp-fill"
                    style={{
                      width: `${hpPercent}%`,
                      backgroundColor: hpPercent > 50 ? '#4ade80' : hpPercent > 25 ? '#facc15' : '#ef4444',
                    }}
                  />
                </div>
                <span className="init-ac" title="AC">AC {c.ac}</span>
              </div>
            </div>
          );
        })}
      </div>

      {currentCombatant && (
        <p className="combat-current-turn">
          Current: <strong>{currentCombatant.name}</strong>
        </p>
      )}

      {/* Combat Log (last 5 entries) */}
      {combat.combat_log && combat.combat_log.length > 0 && (
        <div className="combat-log">
          <h5>Combat Log</h5>
          {combat.combat_log.slice(-5).map((entry, i) => (
            <p key={i} className={`log-entry ${entry.hit ? 'log-hit' : 'log-miss'}`}>
              {entry.attacker} {entry.hit ? 'hits' : 'misses'} {entry.target}
              {entry.hit && entry.damage > 0 && ` for ${entry.damage} ${entry.damage_type}`}
            </p>
          ))}
        </div>
      )}
    </div>
  );
}
