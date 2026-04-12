import { useState, useEffect } from 'react';
import { Character, Spell, SpellCastResult } from '../api/types';
import { api } from '../api/client';

interface Props {
  character: Character;
  campaignId: string;
  onUpdate: (char: Character) => void;
}

interface SpellData {
  known: string[];
  prepared: string[];
  cantrips: string[];
  available: Record<string, Spell>;
  slots: { current: Record<string, number>; max: Record<string, number> };
}

export default function SpellPanel({ character, campaignId, onUpdate }: Props) {
  const [spellData, setSpellData] = useState<SpellData | null>(null);
  const [loading, setLoading] = useState(false);
  const [castingSpell, setCastingSpell] = useState<string | null>(null);

  const hasSpells = character.spellcasting_ability !== null;

  useEffect(() => {
    if (!hasSpells) return;
    setLoading(true);
    api.get<SpellData>(`/campaigns/${campaignId}/characters/${character.id}/spells`)
      .then(setSpellData)
      .finally(() => setLoading(false));
  }, [campaignId, character.id, hasSpells]);

  if (!hasSpells) return null;

  const castSpell = async (slug: string, level?: number) => {
    setCastingSpell(slug);
    try {
      const result = await api.post<SpellCastResult>(
        `/campaigns/${campaignId}/characters/${character.id}/cast-spell`,
        { spell: slug, slot_level: level }
      );
      if (result.error) {
        alert(result.error);
      } else {
        const updated = await api.get<Character>(`/campaigns/${campaignId}/characters/${character.id}`);
        onUpdate(updated);
        // Refresh spell data
        api.get<SpellData>(`/campaigns/${campaignId}/characters/${character.id}/spells`).then(setSpellData);
      }
    } catch { /* ignore */ } finally {
      setCastingSpell(null);
    }
  };

  if (loading || !spellData) {
    return <div className="sidebar-section"><h4>Spells</h4><p>Loading...</p></div>;
  }

  const knownSpells = [...(spellData.cantrips || []), ...(spellData.prepared || []), ...(spellData.known || [])];
  const uniqueSpells = [...new Set(knownSpells)];

  return (
    <div className="sidebar-section spell-panel">
      <h4>Spells</h4>

      {/* Spell Slots Summary */}
      {spellData.slots.max && Object.keys(spellData.slots.max).length > 0 && (
        <div className="spell-slots-mini">
          {Object.entries(spellData.slots.max).map(([lvl, max]) => {
            const current = spellData.slots.current[lvl] ?? 0;
            return (
              <span key={lvl} className="slot-mini" title={`Level ${lvl}: ${current}/${max}`}>
                L{lvl}: {current}/{max}
              </span>
            );
          })}
        </div>
      )}

      {/* Spell List */}
      <div className="spell-list">
        {uniqueSpells.length === 0 && <p className="char-detail-sub">No spells known</p>}
        {uniqueSpells.map(slug => {
          const spell = spellData.available[slug];
          const isCantrip = spell && spell.level === 0;
          return (
            <div key={slug} className="spell-item">
              <div className="spell-item-info">
                <span className="spell-name">{spell?.name || slug}</span>
                <span className="spell-level">{isCantrip ? 'Cantrip' : `Lvl ${spell?.level}`}</span>
              </div>
              <button
                className="btn-cast"
                onClick={() => castSpell(slug)}
                disabled={castingSpell === slug}
                title={spell?.description}
              >
                Cast
              </button>
            </div>
          );
        })}
      </div>
    </div>
  );
}
