<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\GameSession;
use App\Models\GameState;
use App\Models\Message;
use App\Services\AI\AIManager;
use Illuminate\Support\Facades\Log;

class DungeonMasterService
{
    private const MAX_CONTEXT_MESSAGES = 20;

    private const DM_IDENTITY = <<<'PROMPT'
You are the Dungeon Master for a Dungeons & Dragons 5th Edition adventure. You narrate the world, control all NPCs, adjudicate rules, and create an immersive, responsive experience.

BEHAVIOR RULES:
- Never break character. You are the DM, not an AI assistant.
- Describe scenes vividly but concisely (2-4 paragraphs per narration).
- When the player attempts an action that requires a check, tell them what to roll (e.g., "Roll a Dexterity saving throw, DC 14") and wait for their result before narrating the outcome.
- Track combat loosely — describe what happens narratively rather than running a grid. Call for attack rolls and damage when needed.
- Present meaningful choices. Avoid railroading.
- If a player's action is unclear, ask a brief clarifying question.
- Introduce NPCs with personality. Give them names and motives.
- Maintain internal consistency with established facts.
- At natural stopping points, offer 2-3 suggested actions the player might take (but accept any action they describe).

RESPONSE FORMAT:
- Use markdown for emphasis. Use **bold** for NPC dialogue, *italics* for descriptive atmosphere.
- When calling for a roll, use the format: 🎲 [ROLL: Ability/Skill Check, DC N]
- End narration segments with a clear prompt for player action.
- On the very last line of every response, include a mood tag that reflects the current scene atmosphere. Use exactly this format: [MOOD:exploration], [MOOD:tavern], [MOOD:combat], [MOOD:dungeon], [MOOD:mystical], or [MOOD:camp]. Choose the one that best fits the scene: exploration for travel/outdoors, tavern for social/indoor warmth, combat for battle/tension, dungeon for dark/underground/danger, mystical for magic/wonder, camp for rest/calm.

GAME MECHANIC TAGS:
When narrative events trigger mechanical changes, include tags on their own line at the end of your response (before the MOOD tag). Use these formats:
- [XP:N] — Award N experience points (e.g., after defeating enemies, completing quests)
- [GOLD:N] — Award N gold pieces (e.g., loot, rewards)
- [COMBAT:START] — When combat begins. Follow with combatant details.
- [COMBAT:END] — When combat ends.
- [ITEM:slug] — When player finds/receives an item (use equipment slugs like "longsword", "chain-mail")
- [CONDITION:name:target] — Apply a condition (e.g., [CONDITION:poisoned:player])
- [CONDITION_REMOVE:name:target] — Remove a condition
- [HEAL:N] — Heal N hit points
- [DAMAGE:N:type] — Deal N damage of type (e.g., [DAMAGE:8:fire])
Only use these tags when the narrative warrants a mechanical change. Do not use them for hypothetical or conditional events.
PROMPT;

    public function __construct(
        private AIManager $aiManager,
        private ?CharacterProgressionService $progressionService = null,
        private ?CombatService $combatService = null,
    ) {}

    public function setProgressionService(CharacterProgressionService $service): void
    {
        $this->progressionService = $service;
    }

    public function setCombatService(CombatService $service): void
    {
        $this->combatService = $service;
    }

    public function startSession(Campaign $campaign): GameSession
    {
        $lastSession = $campaign->sessions()->latest()->first();
        $sessionNumber = $lastSession ? $lastSession->session_number + 1 : 1;

        $session = $campaign->sessions()->create([
            'session_number' => $sessionNumber,
            'status' => 'active',
        ]);

        $campaign->update(['status' => 'active']);

        // Ensure game state exists
        if (!$campaign->gameState) {
            GameState::create(['campaign_id' => $campaign->id]);
        }

        // Generate opening narration
        $systemPrompt = $this->buildSystemPrompt($campaign);
        $openingInstruction = $sessionNumber === 1
            ? 'Begin the adventure. Set the scene, introduce the world, and give the player their first hook. Make it compelling and immersive.'
            : 'Continue the adventure from where we left off. Briefly recap what happened and set the current scene.';

        $response = $this->callAI(
            $campaign,
            $systemPrompt,
            [['role' => 'user', 'content' => $openingInstruction]]
        );

        // Save the opening narration (but not the instruction)
        $mood = $this->extractMood($response);
        $cleanContent = $this->stripMoodTag($response);

        $session->messages()->create([
            'role' => 'assistant',
            'type' => 'narrative',
            'content' => $cleanContent,
            'metadata' => $mood ? ['mood' => $mood] : null,
        ]);

        return $session->load('messages');
    }

    public function chat(GameSession $session, string $playerMessage): Message
    {
        $campaign = $session->campaign;

        // Save the player's message
        $session->messages()->create([
            'role' => 'user',
            'type' => 'action',
            'content' => $playerMessage,
        ]);

        // Build context
        $systemPrompt = $this->buildSystemPrompt($campaign);
        $recentMessages = $this->getRecentMessages($session);

        // Get AI response
        $response = $this->callAI($campaign, $systemPrompt, $recentMessages);

        // Parse mood tag from response
        $mood = $this->extractMood($response);
        $cleanContent = $this->stripMoodTag($response);

        // Parse and apply game action tags
        $actions = $this->parseGameActions($cleanContent);
        $cleanContent = $this->stripActionTags($cleanContent);

        if (!empty($actions)) {
            $this->applyGameActions($campaign, $session, $actions);
        }

        // Save and return the DM response
        $metadata = [];
        if ($mood) $metadata['mood'] = $mood;
        if (!empty($actions)) $metadata['actions'] = $actions;

        return $session->messages()->create([
            'role' => 'assistant',
            'type' => 'narrative',
            'content' => $cleanContent,
            'metadata' => !empty($metadata) ? $metadata : null,
        ]);
    }

    public function endSession(GameSession $session): GameSession
    {
        $campaign = $session->campaign;
        $systemPrompt = $this->buildSystemPrompt($campaign);
        $recentMessages = $this->getRecentMessages($session);

        // Ask the AI to generate a session summary
        $summaryMessages = array_merge($recentMessages, [[
            'role' => 'user',
            'content' => 'The session is ending. Provide a brief narrative wrap-up (1-2 paragraphs), then generate a concise summary of key events, decisions, and current state for future reference. Format the summary section with the header "## Session Summary".',
        ]]);

        $response = $this->callAI($campaign, $systemPrompt, $summaryMessages);

        // Save the closing narration
        $session->messages()->create([
            'role' => 'assistant',
            'type' => 'narrative',
            'content' => $response,
        ]);

        $session->update([
            'status' => 'ended',
            'summary' => $response,
        ]);

        return $session;
    }

    public function buildSystemPrompt(Campaign $campaign): string
    {
        $campaign->load(['characters', 'gameState']);

        $parts = [self::DM_IDENTITY];

        // Campaign setting
        $parts[] = "CAMPAIGN: {$campaign->title}\nSETTING: {$campaign->setting}";

        // Character sheets with full mechanical state
        foreach ($campaign->characters as $character) {
            $stats = $character->stats;
            $inventory = $character->inventory ? implode(', ', $character->inventory) : 'None';

            $charLines = [
                "PLAYER CHARACTER:",
                "Name: {$character->name}",
                "Race: {$character->race} | Class: {$character->character_class} | Level: {$character->level}",
                "XP: {$character->xp} | Proficiency Bonus: +{$character->proficiency_bonus}",
                "Stats: STR {$stats['str']}(" . $character->getAbilityModifier('str') . ") DEX {$stats['dex']}(" . $character->getAbilityModifier('dex') . ") CON {$stats['con']}(" . $character->getAbilityModifier('con') . ") INT {$stats['int']}(" . $character->getAbilityModifier('int') . ") WIS {$stats['wis']}(" . $character->getAbilityModifier('wis') . ") CHA {$stats['cha']}(" . $character->getAbilityModifier('cha') . ")",
                "HP: {$character->hp}/{$character->max_hp}" . ($character->temp_hp > 0 ? " (Temp: {$character->temp_hp})" : "") . " | AC: {$character->armor_class} | Speed: {$character->speed}ft",
                "Hit Dice: {$character->hit_dice_remaining}/{$character->hit_dice_total} | Gold: {$character->gold}",
            ];

            // Equipped items
            $equipped = $character->equipped ?? [];
            if (!empty($equipped)) {
                $eqParts = [];
                foreach ($equipped as $slot => $slug) {
                    if ($slug) $eqParts[] = "{$slot}: {$slug}";
                }
                $charLines[] = "Equipped: " . implode(', ', $eqParts);
            }

            // Spell info
            if ($character->getSpellcastingAbility()) {
                $charLines[] = "Spell Save DC: {$character->getSpellSaveDC()} | Spell Attack: +{$character->getSpellAttackMod()}";
                $slots = $character->spell_slots ?? [];
                $maxSlots = $character->spell_slots_max ?? [];
                if (!empty($maxSlots)) {
                    $slotParts = [];
                    foreach ($maxSlots as $lvl => $max) {
                        $current = $slots[$lvl] ?? 0;
                        $slotParts[] = "L{$lvl}: {$current}/{$max}";
                    }
                    $charLines[] = "Spell Slots: " . implode(', ', $slotParts);
                }
                $prepared = $character->prepared_spells ?? [];
                if (!empty($prepared)) {
                    $charLines[] = "Prepared Spells: " . implode(', ', $prepared);
                }
            }

            // Conditions
            $conditions = $character->conditions ?? [];
            if (!empty($conditions)) {
                $condNames = array_map(fn($c) => $c['name'], $conditions);
                $charLines[] = "Conditions: " . implode(', ', $condNames);
            }

            // Class features
            $features = $character->class_features ?? [];
            if (!empty($features)) {
                $charLines[] = "Features: " . implode(', ', $features);
            }

            $charLines[] = "Inventory: {$inventory}";
            if ($character->backstory) {
                $charLines[] = "Backstory: {$character->backstory}";
            }

            $parts[] = implode("\n", $charLines);
        }

        // Game state
        if ($state = $campaign->gameState) {
            $stateParts = ["CURRENT GAME STATE:"];
            if ($state->current_location) {
                $stateParts[] = "Location: {$state->current_location}";
            }
            if ($state->quest_log) {
                $stateParts[] = "Active Quests: " . json_encode($state->quest_log);
            }
            if ($state->npc_tracker) {
                $stateParts[] = "Known NPCs: " . json_encode($state->npc_tracker);
            }
            if ($state->world_facts) {
                $stateParts[] = "Key Facts: " . json_encode($state->world_facts);
            }
            $parts[] = implode("\n", $stateParts);
        }

        // Active combat state
        $activeSession = $campaign->sessions()->where('status', 'active')->latest()->first();
        if ($activeSession) {
            $activeCombat = $activeSession->combatEncounters()->where('status', 'active')->first();
            if ($activeCombat) {
                $combatParts = ["ACTIVE COMBAT (Round {$activeCombat->round}):"];
                foreach ($activeCombat->initiative_order as $i => $combatant) {
                    $marker = $i === $activeCombat->current_turn ? '>>>' : '   ';
                    $status = ($combatant['hp'] ?? 0) <= 0 ? 'DEAD' : "HP:{$combatant['hp']}/{$combatant['max_hp']}";
                    $combatParts[] = "{$marker} {$combatant['name']} (Init:{$combatant['initiative']}) {$status} AC:{$combatant['ac']}";
                }
                $parts[] = implode("\n", $combatParts);
            }
        }

        // Previous session recap
        $lastEndedSession = $campaign->sessions()
            ->where('status', 'ended')
            ->latest()
            ->first();

        if ($lastEndedSession?->summary) {
            $parts[] = "PREVIOUS SESSION RECAP:\n{$lastEndedSession->summary}";
        }

        return implode("\n\n", $parts);
    }

    public function getRecentMessages(GameSession $session): array
    {
        return $session->messages()
            ->orderBy('created_at', 'desc')
            ->limit(self::MAX_CONTEXT_MESSAGES)
            ->get()
            ->reverse()
            ->map(fn(Message $msg) => [
                'role' => $msg->role === 'system' ? 'user' : $msg->role,
                'content' => $msg->content,
            ])
            ->values()
            ->toArray();
    }

    public function parseGameActions(string $text): array
    {
        $actions = [];

        if (preg_match('/\[XP:(\d+)\]/', $text, $m)) {
            $actions[] = ['type' => 'xp', 'amount' => (int) $m[1]];
        }
        if (preg_match('/\[GOLD:(\d+)\]/', $text, $m)) {
            $actions[] = ['type' => 'gold', 'amount' => (int) $m[1]];
        }
        if (preg_match('/\[COMBAT:START\]/', $text)) {
            $actions[] = ['type' => 'combat_start'];
        }
        if (preg_match('/\[COMBAT:END\]/', $text)) {
            $actions[] = ['type' => 'combat_end'];
        }
        if (preg_match_all('/\[ITEM:([\w-]+)\]/', $text, $matches)) {
            foreach ($matches[1] as $slug) {
                $actions[] = ['type' => 'item', 'slug' => $slug];
            }
        }
        if (preg_match_all('/\[CONDITION:([\w]+):([\w]+)\]/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $actions[] = ['type' => 'condition_add', 'condition' => $m[1], 'target' => $m[2]];
            }
        }
        if (preg_match_all('/\[CONDITION_REMOVE:([\w]+):([\w]+)\]/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $actions[] = ['type' => 'condition_remove', 'condition' => $m[1], 'target' => $m[2]];
            }
        }
        if (preg_match('/\[HEAL:(\d+)\]/', $text, $m)) {
            $actions[] = ['type' => 'heal', 'amount' => (int) $m[1]];
        }
        if (preg_match('/\[DAMAGE:(\d+):([\w]+)\]/', $text, $m)) {
            $actions[] = ['type' => 'damage', 'amount' => (int) $m[1], 'damage_type' => $m[2]];
        }

        return $actions;
    }

    private function stripActionTags(string $text): string
    {
        $patterns = [
            '/\s*\[XP:\d+\]/',
            '/\s*\[GOLD:\d+\]/',
            '/\s*\[COMBAT:(?:START|END)\]/',
            '/\s*\[ITEM:[\w-]+\]/',
            '/\s*\[CONDITION:[\w]+:[\w]+\]/',
            '/\s*\[CONDITION_REMOVE:[\w]+:[\w]+\]/',
            '/\s*\[HEAL:\d+\]/',
            '/\s*\[DAMAGE:\d+:[\w]+\]/',
        ];

        return trim(preg_replace($patterns, '', $text));
    }

    private function applyGameActions(Campaign $campaign, GameSession $session, array $actions): void
    {
        $character = $campaign->characters()->first();
        if (!$character) return;

        foreach ($actions as $action) {
            try {
                match ($action['type']) {
                    'xp' => $this->progressionService?->addXp($character, $action['amount']),
                    'gold' => $this->applyGold($character, $action['amount']),
                    'item' => $this->applyItem($character, $action['slug']),
                    'heal' => $this->applyHeal($character, $action['amount']),
                    'damage' => $this->combatService?->applyDamage($character, $action['amount'], $action['damage_type'] ?? ''),
                    'condition_add' => $this->combatService?->applyCondition($character, $action['condition']),
                    'condition_remove' => $this->combatService?->removeCondition($character, $action['condition']),
                    'combat_end' => $this->combatService?->getActiveCombat($session)?->update(['status' => 'ended']),
                    default => null,
                };
            } catch (\Throwable $e) {
                Log::warning("Failed to apply game action: " . json_encode($action), ['error' => $e->getMessage()]);
            }
        }
    }

    private function applyGold(Character $character, int $amount): void
    {
        $character->gold += $amount;
        $character->save();
    }

    private function applyItem(Character $character, string $slug): void
    {
        $inventory = $character->inventory ?? [];
        $inventory[] = $slug;
        $character->inventory = $inventory;
        $character->save();
    }

    private function applyHeal(Character $character, int $amount): void
    {
        $character->hp = min($character->max_hp, $character->hp + $amount);
        $character->save();
    }

    private function extractMood(string $text): ?string
    {
        $validMoods = ['exploration', 'tavern', 'combat', 'dungeon', 'mystical', 'camp'];
        if (preg_match('/\[MOOD:(\w+)\]/i', $text, $matches)) {
            $mood = strtolower($matches[1]);
            return in_array($mood, $validMoods) ? $mood : null;
        }
        return null;
    }

    private function stripMoodTag(string $text): string
    {
        return trim(preg_replace('/\s*\[MOOD:\w+\]\s*$/i', '', $text));
    }

    private function callAI(Campaign $campaign, string $systemPrompt, array $messages): string
    {
        $provider = $this->aiManager->provider($campaign->ai_provider);

        return $provider->chat($systemPrompt, $messages);
    }
}
