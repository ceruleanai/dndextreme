<?php

namespace App\Services;

use App\Models\Character;

class RestService
{
    public function __construct(private DndDataService $dnd) {}

    public function shortRest(Character $character, int $hitDiceToSpend = 0): array
    {
        $healed = 0;
        $classData = $this->dnd->getClassData($character->character_class);
        $hitDie = $classData['hit_die'] ?? 8;
        $conMod = $character->getAbilityModifier('con');

        $diceSpent = 0;
        for ($i = 0; $i < $hitDiceToSpend && $character->hit_dice_remaining > 0; $i++) {
            $roll = max(1, (int) ceil($hitDie / 2) + $conMod);
            $healed += $roll;
            $character->hit_dice_remaining--;
            $diceSpent++;
        }

        $character->hp = min($character->max_hp, $character->hp + $healed);

        // Warlock: restore spell slots on short rest
        if ($character->character_class === 'Warlock') {
            $character->spell_slots = $character->spell_slots_max;
        }

        $character->save();

        return [
            'type' => 'short',
            'hit_dice_spent' => $diceSpent,
            'hp_healed' => $healed,
            'hp' => $character->hp,
            'max_hp' => $character->max_hp,
            'hit_dice_remaining' => $character->hit_dice_remaining,
        ];
    }

    public function longRest(Character $character): array
    {
        $oldHp = $character->hp;

        // Restore all HP
        $character->hp = $character->max_hp;
        $character->temp_hp = 0;

        // Restore half hit dice (minimum 1)
        $regained = max(1, (int) floor($character->hit_dice_total / 2));
        $character->hit_dice_remaining = min(
            $character->hit_dice_total,
            $character->hit_dice_remaining + $regained
        );

        // Restore all spell slots
        $character->spell_slots = $character->spell_slots_max;

        // Reset death saves
        $character->death_save_successes = 0;
        $character->death_save_failures = 0;

        // Remove conditions that end on long rest
        $conditions = $character->conditions ?? [];
        $character->conditions = array_values(
            array_filter($conditions, fn($c) => ($c['persistent'] ?? false))
        );

        $character->save();

        return [
            'type' => 'long',
            'hp_restored' => $character->hp - $oldHp,
            'hp' => $character->hp,
            'max_hp' => $character->max_hp,
            'hit_dice_regained' => $regained,
            'hit_dice_remaining' => $character->hit_dice_remaining,
            'spell_slots_restored' => true,
        ];
    }
}
