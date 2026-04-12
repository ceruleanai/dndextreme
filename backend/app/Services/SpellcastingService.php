<?php

namespace App\Services;

use App\Models\Character;

class SpellcastingService
{
    public function __construct(private DndDataService $dnd) {}

    public function castSpell(Character $character, string $spellSlug, ?int $slotLevel = null): array
    {
        $spell = $this->dnd->getSpell($spellSlug);
        if (!$spell) {
            return ['error' => 'Spell not found'];
        }

        $spellLevel = $spell['level'] ?? 0;

        // Cantrips don't use slots
        if ($spellLevel === 0) {
            $cantrips = $character->cantrips ?? [];
            if (!in_array($spellSlug, $cantrips)) {
                return ['error' => 'You don\'t know this cantrip'];
            }
            return ['success' => true, 'spell' => $spell, 'slot_used' => null];
        }

        // Check spell is known/prepared
        $prepared = $character->prepared_spells ?? [];
        $known = $character->known_spells ?? [];
        if (!in_array($spellSlug, $prepared) && !in_array($spellSlug, $known)) {
            return ['error' => 'Spell is not prepared or known'];
        }

        // Determine slot to use
        $castLevel = $slotLevel ?? $spellLevel;
        if ($castLevel < $spellLevel) {
            return ['error' => "Cannot cast a level {$spellLevel} spell with a level {$castLevel} slot"];
        }

        // Check and consume slot
        $slots = $character->spell_slots ?? [];
        $key = (string) $castLevel;
        if (($slots[$key] ?? 0) < 1) {
            return ['error' => "No level {$castLevel} spell slots remaining"];
        }

        $slots[$key]--;
        $character->spell_slots = $slots;
        $character->save();

        return [
            'success' => true,
            'spell' => $spell,
            'slot_used' => $castLevel,
            'slots_remaining' => $slots,
        ];
    }

    public function prepareSpells(Character $character, array $spellSlugs): array
    {
        $classData = $this->dnd->getClassData($character->character_class);
        if (!$classData || !$classData['spellcasting']) {
            return ['error' => 'This class cannot cast spells'];
        }

        $ability = $classData['spellcasting']['ability'];
        $mod = $character->getAbilityModifier($ability);

        // Calculate preparation limit
        $prepLimit = max(1, $character->level + $mod);

        if (count($spellSlugs) > $prepLimit) {
            return ['error' => "Can only prepare {$prepLimit} spells"];
        }

        // Validate all spells are known and correct class
        $known = $character->known_spells ?? [];
        foreach ($spellSlugs as $slug) {
            if (!in_array($slug, $known)) {
                $spell = $this->dnd->getSpell($slug);
                if (!$spell) return ['error' => "Unknown spell: {$slug}"];
                $className = strtolower($character->character_class);
                if (!in_array($className, $spell['classes'] ?? [])) {
                    return ['error' => "{$spell['name']} is not on the {$character->character_class} spell list"];
                }
            }
        }

        $character->prepared_spells = $spellSlugs;
        $character->save();

        return ['success' => true, 'prepared' => $spellSlugs, 'limit' => $prepLimit];
    }

    public function learnSpell(Character $character, string $spellSlug): array
    {
        $spell = $this->dnd->getSpell($spellSlug);
        if (!$spell) {
            return ['error' => 'Spell not found'];
        }

        // Check class compatibility
        $className = strtolower($character->character_class);
        if (!in_array($className, $spell['classes'] ?? [])) {
            return ['error' => "{$spell['name']} is not available to {$character->character_class}"];
        }

        // Check level requirement
        $spellLevel = $spell['level'] ?? 0;
        $maxSlots = $character->spell_slots_max ?? [];
        if ($spellLevel > 0 && !isset($maxSlots[(string) $spellLevel])) {
            return ['error' => "Cannot learn level {$spellLevel} spells yet"];
        }

        if ($spellLevel === 0) {
            $cantrips = $character->cantrips ?? [];
            if (!in_array($spellSlug, $cantrips)) {
                $cantrips[] = $spellSlug;
                $character->cantrips = $cantrips;
            }
        } else {
            $known = $character->known_spells ?? [];
            if (!in_array($spellSlug, $known)) {
                $known[] = $spellSlug;
                $character->known_spells = $known;
            }
        }

        $character->save();

        return ['success' => true, 'spell' => $spell];
    }

    public function getAvailableSpells(Character $character): array
    {
        $className = strtolower($character->character_class);
        $maxSpellLevel = $this->getMaxSpellLevel($character);

        return $this->dnd->getSpellsForClass($className, $maxSpellLevel);
    }

    public function getSpellSlotsStatus(Character $character): array
    {
        return [
            'current' => $character->spell_slots ?? [],
            'max' => $character->spell_slots_max ?? [],
        ];
    }

    private function getMaxSpellLevel(Character $character): int
    {
        $maxSlots = $character->spell_slots_max ?? [];
        $max = 0;
        foreach ($maxSlots as $level => $count) {
            if ($count > 0) $max = max($max, (int) $level);
        }
        return $max;
    }
}
