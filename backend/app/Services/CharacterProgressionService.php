<?php

namespace App\Services;

use App\Models\Character;

class CharacterProgressionService
{
    public function __construct(private DndDataService $dnd) {}

    public function addXp(Character $character, int $xp): array
    {
        $character->xp += $xp;
        $newLevel = $this->dnd->getLevelForXp($character->xp);
        $leveledUp = $newLevel > $character->level;

        $character->save();

        return [
            'xp_gained' => $xp,
            'total_xp' => $character->xp,
            'leveled_up' => $leveledUp,
            'new_level' => $newLevel,
            'xp_to_next' => $this->getXpToNextLevel($character),
        ];
    }

    public function levelUp(Character $character): array
    {
        $newLevel = $this->dnd->getLevelForXp($character->xp);
        if ($newLevel <= $character->level) {
            return ['error' => 'Not enough XP to level up'];
        }

        $classData = $this->dnd->getClassData($character->character_class);
        if (!$classData) {
            return ['error' => 'Unknown class'];
        }

        $oldLevel = $character->level;
        $character->level = $newLevel;
        $character->proficiency_bonus = $this->dnd->getProficiencyBonus($newLevel);
        $character->hit_dice_total = $newLevel;
        $character->hit_dice_remaining = min(
            $character->hit_dice_remaining + ($newLevel - $oldLevel),
            $newLevel
        );

        // HP increase: average hit die + CON modifier per level gained
        $hitDie = $classData['hit_die'] ?? 8;
        $conMod = $character->getAbilityModifier('con');
        $hpGain = 0;
        for ($lvl = $oldLevel + 1; $lvl <= $newLevel; $lvl++) {
            $hpGain += max(1, (int) ceil($hitDie / 2) + 1 + $conMod);
        }
        $character->max_hp += $hpGain;
        $character->hp += $hpGain;

        // Update spell slots if caster
        if ($classData['spellcasting'] && isset($classData['spellcasting']['slot_table'])) {
            $slotRow = $classData['spellcasting']['slot_table'][(string) $newLevel] ?? null;
            if ($slotRow) {
                $maxSlots = [];
                foreach ($slotRow as $i => $count) {
                    if ($count > 0) {
                        $maxSlots[(string) ($i + 1)] = $count;
                    }
                }
                $character->spell_slots_max = $maxSlots;
                $character->spell_slots = $maxSlots; // Restore on level up
            }
        }

        // Gather new features
        $newFeatures = $character->class_features ?? [];
        for ($lvl = $oldLevel + 1; $lvl <= $newLevel; $lvl++) {
            $features = $classData['features_by_level'][(string) $lvl] ?? [];
            foreach ($features as $feature) {
                $newFeatures[] = $feature['name'];
            }
        }
        $character->class_features = array_values(array_unique($newFeatures));

        // Recalculate AC
        $character->armor_class = $this->calculateAC($character);

        $character->save();

        // Check for ASI levels
        $asiLevels = [4, 8, 12, 16, 19];
        $hasAsi = false;
        for ($lvl = $oldLevel + 1; $lvl <= $newLevel; $lvl++) {
            if (in_array($lvl, $asiLevels)) {
                $hasAsi = true;
            }
        }

        return [
            'old_level' => $oldLevel,
            'new_level' => $newLevel,
            'hp_gained' => $hpGain,
            'new_max_hp' => $character->max_hp,
            'proficiency_bonus' => $character->proficiency_bonus,
            'new_features' => array_slice($newFeatures, count($newFeatures) - count($newFeatures)),
            'has_asi' => $hasAsi,
            'spell_slots_max' => $character->spell_slots_max,
        ];
    }

    public function applyAbilityScoreImprovement(Character $character, array $increases): array
    {
        // Validate: total increase <= 2, each ability increase <= 2, no score > 20
        $total = array_sum($increases);
        if ($total > 2 || $total < 1) {
            return ['error' => 'ASI must increase ability scores by exactly 1 or 2 points total'];
        }

        $stats = $character->stats;
        foreach ($increases as $ability => $amount) {
            if (!isset($stats[$ability])) {
                return ['error' => "Invalid ability: {$ability}"];
            }
            if ($amount < 0 || $amount > 2) {
                return ['error' => 'Each ability can increase by at most 2'];
            }
            if ($stats[$ability] + $amount > 20) {
                return ['error' => "{$ability} cannot exceed 20"];
            }
            $stats[$ability] += $amount;
        }

        $character->stats = $stats;

        // Recalculate derived values
        $character->armor_class = $this->calculateAC($character);

        // If CON increased, recalculate max HP
        if (isset($increases['con'])) {
            $hpPerLevel = $increases['con'] > 0 ? (int) floor($increases['con'] / 2) : 0;
            $character->max_hp += $hpPerLevel * $character->level;
            $character->hp += $hpPerLevel * $character->level;
        }

        $character->save();

        return ['stats' => $character->stats, 'armor_class' => $character->armor_class];
    }

    public function calculateAC(Character $character): int
    {
        $dexMod = $character->getAbilityModifier('dex');
        $equipped = $character->equipped ?? [];

        $armorSlug = $equipped['armor'] ?? null;
        $hasShield = $equipped['shield'] ?? false;

        $ac = 10 + $dexMod; // Default unarmored

        if ($armorSlug) {
            $armor = $this->dnd->getEquipment($armorSlug);
            if ($armor && $armor['type'] === 'armor') {
                $maxDex = $armor['max_dex_bonus'] ?? null;
                $ac = $armor['ac_base'] + ($maxDex !== null ? min($dexMod, $maxDex) : $dexMod);
            }
        }

        // Barbarian unarmored defense
        if ($character->character_class === 'Barbarian' && !$armorSlug) {
            $ac = 10 + $dexMod + $character->getAbilityModifier('con');
        }

        // Monk unarmored defense
        if ($character->character_class === 'Monk' && !$armorSlug) {
            $ac = 10 + $dexMod + $character->getAbilityModifier('wis');
        }

        if ($hasShield) {
            $ac += 2;
        }

        return $ac;
    }

    public function getXpToNextLevel(Character $character): int
    {
        if ($character->level >= 20) return 0;
        $nextLevelXp = $this->dnd->getXpForLevel($character->level + 1);
        return max(0, $nextLevelXp - $character->xp);
    }

    public function getFullSheet(Character $character): array
    {
        $classData = $this->dnd->getClassData($character->character_class);

        $sheet = $character->toArray();
        $sheet['ability_modifiers'] = [];
        foreach (['str', 'dex', 'con', 'int', 'wis', 'cha'] as $ability) {
            $sheet['ability_modifiers'][$ability] = $character->getAbilityModifier($ability);
        }

        $sheet['spell_save_dc'] = $character->getSpellSaveDC();
        $sheet['spell_attack_mod'] = $character->getSpellAttackMod();
        $sheet['spellcasting_ability'] = $character->getSpellcastingAbility();
        $sheet['xp_to_next_level'] = $this->getXpToNextLevel($character);
        $sheet['xp_next_level_total'] = $character->level < 20
            ? $this->dnd->getXpForLevel($character->level + 1)
            : null;
        $sheet['can_level_up'] = $this->dnd->getLevelForXp($character->xp) > $character->level;
        $sheet['hit_die'] = $classData['hit_die'] ?? 8;

        // Resolve equipped item names
        $equipped = $character->equipped ?? [];
        $sheet['equipped_details'] = [];
        foreach ($equipped as $slot => $slug) {
            if ($slug && $slot !== 'shield') {
                $item = $this->dnd->getEquipment($slug);
                if ($item) $sheet['equipped_details'][$slot] = $item;
            }
        }

        return $sheet;
    }

    public function initializeCharacter(Character $character): void
    {
        $classData = $this->dnd->getClassData($character->character_class);
        if (!$classData) return;

        $character->proficiency_bonus = 2;
        $character->hit_dice_total = 1;
        $character->hit_dice_remaining = 1;
        $character->armor_class = $this->calculateAC($character);

        // Set saving throw and base proficiencies
        $character->proficiencies = [
            'saving_throws' => $classData['saving_throw_proficiencies'] ?? [],
            'armor' => $classData['armor_proficiencies'] ?? [],
            'weapons' => $classData['weapon_proficiencies'] ?? [],
            'tools' => [],
            'skills' => $character->proficiencies['skills'] ?? [],
        ];

        // Level 1 features
        $features = [];
        foreach (($classData['features_by_level']['1'] ?? []) as $feature) {
            $features[] = $feature['name'];
        }
        $character->class_features = $features;

        // Spell slots for level 1 casters
        if ($classData['spellcasting'] && isset($classData['spellcasting']['slot_table']['1'])) {
            $slotRow = $classData['spellcasting']['slot_table']['1'];
            $maxSlots = [];
            foreach ($slotRow as $i => $count) {
                if ($count > 0) {
                    $maxSlots[(string) ($i + 1)] = $count;
                }
            }
            $character->spell_slots_max = $maxSlots;
            $character->spell_slots = $maxSlots;
        }

        $character->save();
    }
}
