<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CombatEncounter;
use App\Models\GameSession;

class CombatService
{
    public function initiateCombat(GameSession $session, array $combatants): CombatEncounter
    {
        // End any active combat first
        $session->combatEncounters()->where('status', 'active')->update(['status' => 'ended']);

        // Sort combatants by initiative (desc)
        usort($combatants, fn($a, $b) => ($b['initiative'] ?? 0) <=> ($a['initiative'] ?? 0));

        return CombatEncounter::create([
            'game_session_id' => $session->id,
            'initiative_order' => $combatants,
            'combat_log' => [],
        ]);
    }

    public function nextTurn(CombatEncounter $encounter): array
    {
        $order = $encounter->initiative_order;
        $totalCombatants = count($order);

        if ($totalCombatants === 0) {
            return ['error' => 'No combatants'];
        }

        // Advance to next living combatant
        $startTurn = $encounter->current_turn;
        $newTurn = ($startTurn + 1) % $totalCombatants;
        $newRound = $encounter->round;

        if ($newTurn <= $startTurn || $newTurn === 0) {
            $newRound++;
        }

        // Skip dead combatants
        $checked = 0;
        while ($checked < $totalCombatants) {
            $combatant = $order[$newTurn];
            if (($combatant['hp'] ?? 1) > 0) break;
            $newTurn = ($newTurn + 1) % $totalCombatants;
            if ($newTurn === 0) $newRound++;
            $checked++;
        }

        $encounter->current_turn = $newTurn;
        $encounter->round = $newRound;
        $encounter->save();

        return [
            'round' => $newRound,
            'current_turn' => $newTurn,
            'current_combatant' => $order[$newTurn] ?? null,
        ];
    }

    public function resolveAttack(
        CombatEncounter $encounter,
        int $attackerIndex,
        int $targetIndex,
        int $attackRoll,
        ?int $damage = null,
        string $damageType = 'slashing',
    ): array {
        $order = $encounter->initiative_order;
        $attacker = $order[$attackerIndex] ?? null;
        $target = $order[$targetIndex] ?? null;

        if (!$attacker || !$target) {
            return ['error' => 'Invalid combatant index'];
        }

        $targetAC = $target['ac'] ?? 10;
        $hit = $attackRoll >= $targetAC;
        $critical = $attackRoll === 20 + ($attacker['attack_modifier'] ?? 0); // natural 20 approximation

        $result = [
            'attacker' => $attacker['name'],
            'target' => $target['name'],
            'roll' => $attackRoll,
            'target_ac' => $targetAC,
            'hit' => $hit,
            'damage' => 0,
            'damage_type' => $damageType,
        ];

        if ($hit && $damage !== null) {
            $order[$targetIndex]['hp'] = max(0, ($target['hp'] ?? 0) - $damage);
            $result['damage'] = $damage;
            $result['target_hp'] = $order[$targetIndex]['hp'];
            $result['target_max_hp'] = $target['max_hp'] ?? $target['hp'];
        }

        // Add to combat log
        $log = $encounter->combat_log ?? [];
        $log[] = array_merge($result, [
            'round' => $encounter->round,
            'timestamp' => now()->toIso8601String(),
        ]);

        $encounter->initiative_order = $order;
        $encounter->combat_log = $log;
        $encounter->save();

        return $result;
    }

    public function applyDamage(Character $character, int $damage, string $damageType = ''): array
    {
        // Apply to temp HP first
        if ($character->temp_hp > 0) {
            $absorbed = min($character->temp_hp, $damage);
            $character->temp_hp -= $absorbed;
            $damage -= $absorbed;
        }

        $character->hp = max(0, $character->hp - $damage);

        $unconscious = $character->hp <= 0;
        if ($unconscious) {
            $character->hp = 0;
            $character->death_save_successes = 0;
            $character->death_save_failures = 0;
        }

        $character->save();

        return [
            'hp' => $character->hp,
            'max_hp' => $character->max_hp,
            'temp_hp' => $character->temp_hp,
            'unconscious' => $unconscious,
        ];
    }

    public function deathSave(Character $character, int $roll): array
    {
        if ($character->hp > 0) {
            return ['error' => 'Character is not unconscious'];
        }

        if ($roll === 20) {
            // Nat 20: regain 1 HP
            $character->hp = 1;
            $character->death_save_successes = 0;
            $character->death_save_failures = 0;
            $character->save();
            return ['result' => 'revived', 'hp' => 1];
        }

        if ($roll === 1) {
            // Nat 1: two failures
            $character->death_save_failures += 2;
        } elseif ($roll >= 10) {
            $character->death_save_successes++;
        } else {
            $character->death_save_failures++;
        }

        $stabilized = $character->death_save_successes >= 3;
        $dead = $character->death_save_failures >= 3;

        if ($stabilized) {
            $character->death_save_successes = 0;
            $character->death_save_failures = 0;
        }

        $character->save();

        return [
            'roll' => $roll,
            'successes' => $character->death_save_successes,
            'failures' => $character->death_save_failures,
            'stabilized' => $stabilized,
            'dead' => $dead,
        ];
    }

    public function applyCondition(Character $character, string $condition, array $params = []): void
    {
        $conditions = $character->conditions ?? [];
        // Remove existing instance of same condition
        $conditions = array_values(array_filter($conditions, fn($c) => $c['name'] !== $condition));
        $conditions[] = array_merge(['name' => $condition], $params);
        $character->conditions = $conditions;
        $character->save();
    }

    public function removeCondition(Character $character, string $condition): void
    {
        $conditions = $character->conditions ?? [];
        $character->conditions = array_values(
            array_filter($conditions, fn($c) => $c['name'] !== $condition)
        );
        $character->save();
    }

    public function endCombat(CombatEncounter $encounter): void
    {
        $encounter->status = 'ended';
        $encounter->save();
    }

    public function getActiveCombat(GameSession $session): ?CombatEncounter
    {
        return $session->combatEncounters()->where('status', 'active')->first();
    }
}
