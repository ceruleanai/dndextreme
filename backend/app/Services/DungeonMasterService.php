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
    private const SUMMARIZE_THRESHOLD = 30;

    private const DM_IDENTITY = <<<'PROMPT'
You are the Dungeon Master for a Dungeons & Dragons 5th Edition adventure. You narrate the world, control all NPCs, adjudicate rules, and create an immersive, responsive experience.

BEHAVIOR RULES:
- Never break character. You are the DM, not an AI assistant.
- Describe scenes vividly but concisely (2-4 paragraphs per narration).
- When the player attempts an action that requires a check, tell them what to roll (e.g., "Roll a Dexterity saving throw, DC 14") and wait for their result before narrating the outcome.
- Track combat loosely — describe what happens narratively rather than running a grid. Call for attack rolls and damage when needed.
- Present meaningful choices. Avoid railroarding.
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
- [SPELL:spell-slug:level] — When the player narratively casts a spell. Use the spell slug and slot level. (e.g., [SPELL:cure-wounds:1]). Only use when the player clearly describes casting a spell. This consumes the spell slot automatically.
- [DEATH_SAVE:roll] — When a downed player rolls a death save. Use the d20 result. (e.g., [DEATH_SAVE:14])
- [LOCATION:category:name] — When the party moves to a new distinct location. Categories: tavern, forest, dungeon, cave, town, castle, road, camp, temple, ruins, mountain, swamp, dock, sewer, ship. Use a specific location name. Example: [LOCATION:tavern:The Golden Goose Inn]

WORLD STATE TAGS:
Use these tags to track important narrative state changes. This helps you remember key facts across the adventure.
- [QUEST_ADD:title|description] — When a new quest or objective is established (e.g., [QUEST_ADD:The Missing Merchant|Find the merchant who disappeared on the road to Silverton])
- [QUEST_COMPLETE:title] — When a quest is completed
- [NPC:name|disposition|notes] — When a significant NPC is introduced or their status changes (e.g., [NPC:Gundren Rockseeker|friendly|Dwarf merchant who hired the party, currently kidnapped])
- [WORLD_FACT:fact] — When an important world fact is established or discovered (e.g., [WORLD_FACT:The orcs in the Redridge Mountains serve a dragon named Venomfang])
Only use these tags when the narrative warrants a mechanical change. Do not use them for hypothetical or conditional events.
PROMPT;

    public function __construct(
        private AIManager $aiManager,
        private ?CharacterProgressionService $progressionService = null,
        private ?CombatService $combatService = null,
        private ?MapService $mapService = null,
        private ?SpellcastingService $spellcastingService = null,
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
        $systemPrompt = $this->buildSystemPrompt($campaign, $session);
        $openingInstruction = $sessionNumber === 1
            ? 'Begin the adventure. Set the scene, introduce the world, and give the player their first hook. Make it compelling and immersive.'
            : 'Continue the adventure from where we left off. Briefly recap what happened and set the current scene.';

        $response = $this->callAI(
            $campaign,
            $systemPrompt,
            [['role' => 'user', 'content' => $openingInstruction]]
        );

        // Parse and apply all tags from the opening narration
        $mood = $this->extractMood($response);
        $cleanContent = $this->stripMoodTag($response);
        $actions = $this->parseGameActions($cleanContent);
        $worldUpdates = $this->parseWorldStateTags($cleanContent);
        $cleanContent = $this->stripActionTags($cleanContent);
        $cleanContent = $this->stripWorldStateTags($cleanContent);

        if (!empty($actions)) {
            $this->applyGameActions($campaign, $session, $actions);
        }
        if (!empty($worldUpdates)) {
            $this->applyWorldStateUpdates($campaign, $worldUpdates);
        }

        $metadata = [];
        if ($mood) $metadata['mood'] = $mood;

        $session->messages()->create([
            'role' => 'assistant',
            'type' => 'narrative',
            'content' => $cleanContent,
            'metadata' => !empty($metadata) ? $metadata : null,
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

        // Check if we need to summarize older messages
        $this->maybeSummarizeContext($campaign, $session);

        // Build context
        $systemPrompt = $this->buildSystemPrompt($campaign, $session);
        $recentMessages = $this->getRecentMessages($session);

        // Get AI response
        $response = $this->callAI($campaign, $systemPrompt, $recentMessages);

        // Parse mood tag from response
        $mood = $this->extractMood($response);
        $cleanContent = $this->stripMoodTag($response);

        // Parse and apply game action tags
        $actions = $this->parseGameActions($cleanContent);
        $cleanContent = $this->stripActionTags($cleanContent);

        // Parse and apply world state tags
        $worldUpdates = $this->parseWorldStateTags($cleanContent);
        $cleanContent = $this->stripWorldStateTags($cleanContent);

        if (!empty($actions)) {
            $this->applyGameActions($campaign, $session, $actions);
        }
        if (!empty($worldUpdates)) {
            $this->applyWorldStateUpdates($campaign, $worldUpdates);
        }

        // Save and return the DM response
        $metadata = [];
        if ($mood) $metadata['mood'] = $mood;
        if (!empty($actions)) $metadata['actions'] = $actions;

        // Include current map in metadata if it changed
        $locationAction = collect($actions)->firstWhere('type', 'location_change');
        if ($locationAction) {
            $campaign->load('gameState.currentMap');
            $currentMap = $campaign->gameState?->currentMap;
            if ($currentMap) {
                $metadata['map'] = [
                    'id' => $currentMap->id,
                    'image_path' => $currentMap->image_path,
                    'location_name' => $currentMap->location_name,
                    'category' => $currentMap->category,
                    'map_type' => $currentMap->map_type,
                ];
            }
        }

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
        $systemPrompt = $this->buildSystemPrompt($campaign, $session);
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

    // =========================================================
    // Context Summarization
    // =========================================================

    private function maybeSummarizeContext(Campaign $campaign, GameSession $session): void
    {
        $totalMessages = $session->messages()->count();
        $summarizedUpTo = $session->summarized_up_to ?? 0;
        $unsummarized = $totalMessages - $summarizedUpTo;

        // Only summarize when we have significantly more messages than our context window
        if ($unsummarized < self::SUMMARIZE_THRESHOLD) {
            return;
        }

        // Get the messages that need to be summarized (older ones beyond our context window)
        $messagesToSummarize = $session->messages()
            ->orderBy('created_at')
            ->skip($summarizedUpTo)
            ->take($unsummarized - self::MAX_CONTEXT_MESSAGES)
            ->get();

        if ($messagesToSummarize->count() < 10) {
            return;
        }

        $existingSummary = $session->context_summary ?? '';
        $transcript = $messagesToSummarize->map(function ($msg) {
            $speaker = $msg->role === 'assistant' ? 'DM' : 'Player';
            return "{$speaker}: " . mb_substr($msg->content, 0, 300);
        })->implode("\n");

        $summaryPrompt = "You are summarizing a D&D session transcript for context preservation. "
            . "Extract and preserve ALL of the following:\n"
            . "- Key plot events and decisions\n"
            . "- NPC names, dispositions, and what happened with them\n"
            . "- Locations visited and important details about them\n"
            . "- Items found, gold earned, combat outcomes\n"
            . "- Promises made, quests accepted, clues discovered\n"
            . "- Any unresolved threads or ongoing situations\n\n"
            . "Be concise but complete. Do not omit any narrative-relevant detail.\n\n";

        if ($existingSummary) {
            $summaryPrompt .= "EXISTING SUMMARY (incorporate and update):\n{$existingSummary}\n\n";
        }

        $summaryPrompt .= "NEW TRANSCRIPT TO SUMMARIZE:\n{$transcript}";

        try {
            $summary = $this->callAI($campaign, $summaryPrompt, [
                ['role' => 'user', 'content' => 'Summarize this transcript. Output only the summary, no preamble.'],
            ]);

            $session->update([
                'context_summary' => $summary,
                'summarized_up_to' => $summarizedUpTo + $messagesToSummarize->count(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Context summarization failed", ['error' => $e->getMessage()]);
        }
    }

    // =========================================================
    // System Prompt Building
    // =========================================================

    public function buildSystemPrompt(Campaign $campaign, ?GameSession $session = null): string
    {
        $campaign->load(['characters', 'gameState']);

        $parts = [self::DM_IDENTITY];

        // Campaign setting
        $parts[] = "CAMPAIGN: {$campaign->title}\nSETTING: {$campaign->setting}";

        // Character sheets with full mechanical state
        foreach ($campaign->characters as $character) {
            $parts[] = $this->buildCharacterBlock($character);
        }

        // Game state
        if ($state = $campaign->gameState) {
            $parts[] = $this->buildGameStateBlock($state);
        }

        // Active combat state
        $activeSession = $session ?? $campaign->sessions()->where('status', 'active')->latest()->first();
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

        // In-session context summary (rolling summary of older messages)
        if ($activeSession && $activeSession->context_summary) {
            $parts[] = "EARLIER IN THIS SESSION (summarized):\n{$activeSession->context_summary}";
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

    private function buildCharacterBlock(Character $character): string
    {
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
            $cantrips = $character->cantrips ?? [];
            if (!empty($cantrips)) {
                $charLines[] = "Cantrips: " . implode(', ', $cantrips);
            }
        }

        // Conditions
        $conditions = $character->conditions ?? [];
        if (!empty($conditions)) {
            $condNames = array_map(fn($c) => $c['name'], $conditions);
            $charLines[] = "Conditions: " . implode(', ', $condNames);
        }

        // Death saves (if at 0 HP)
        if ($character->hp <= 0) {
            $charLines[] = "UNCONSCIOUS - Death Saves: {$character->death_save_successes} successes, {$character->death_save_failures} failures";
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

        return implode("\n", $charLines);
    }

    private function buildGameStateBlock(GameState $state): string
    {
        $stateParts = ["CURRENT GAME STATE:"];
        if ($state->current_location) {
            $stateParts[] = "Location: {$state->current_location}";
        }

        // Quests
        $quests = $state->quest_log ?? [];
        if (!empty($quests)) {
            $stateParts[] = "Active Quests:";
            foreach ($quests as $q) {
                $status = $q['status'] ?? 'active';
                $stateParts[] = "  - [{$status}] {$q['title']}: {$q['description']}";
            }
        }

        // NPCs
        $npcs = $state->npc_tracker ?? [];
        if (!empty($npcs)) {
            $stateParts[] = "Known NPCs:";
            foreach ($npcs as $npc) {
                $stateParts[] = "  - {$npc['name']} ({$npc['disposition']}): {$npc['notes']}";
            }
        }

        // World facts
        $facts = $state->world_facts ?? [];
        if (!empty($facts)) {
            $stateParts[] = "Established World Facts:";
            foreach ($facts as $fact) {
                $factText = is_string($fact) ? $fact : ($fact['fact'] ?? json_encode($fact));
                $stateParts[] = "  - {$factText}";
            }
        }

        return implode("\n", $stateParts);
    }

    // =========================================================
    // Message History
    // =========================================================

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

    // =========================================================
    // Tag Parsing
    // =========================================================

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
        if (preg_match('/\[LOCATION:([\w]+):([^\]]+)\]/', $text, $m)) {
            $actions[] = ['type' => 'location_change', 'category' => $m[1], 'name' => trim($m[2])];
        }
        // Spell casting tag
        if (preg_match_all('/\[SPELL:([\w-]+):(\d+)\]/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $actions[] = ['type' => 'spell_cast', 'spell' => $m[1], 'level' => (int) $m[2]];
            }
        }
        // Death save tag
        if (preg_match('/\[DEATH_SAVE:(\d+)\]/', $text, $m)) {
            $actions[] = ['type' => 'death_save', 'roll' => (int) $m[1]];
        }

        return $actions;
    }

    public function parseWorldStateTags(string $text): array
    {
        $updates = [];

        // Quest add: [QUEST_ADD:title|description]
        if (preg_match_all('/\[QUEST_ADD:([^|]+)\|([^\]]+)\]/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $updates[] = ['type' => 'quest_add', 'title' => trim($m[1]), 'description' => trim($m[2])];
            }
        }

        // Quest complete: [QUEST_COMPLETE:title]
        if (preg_match_all('/\[QUEST_COMPLETE:([^\]]+)\]/', $text, $matches)) {
            foreach ($matches[1] as $title) {
                $updates[] = ['type' => 'quest_complete', 'title' => trim($title)];
            }
        }

        // NPC: [NPC:name|disposition|notes]
        if (preg_match_all('/\[NPC:([^|]+)\|([^|]+)\|([^\]]+)\]/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $updates[] = ['type' => 'npc', 'name' => trim($m[1]), 'disposition' => trim($m[2]), 'notes' => trim($m[3])];
            }
        }

        // World fact: [WORLD_FACT:fact]
        if (preg_match_all('/\[WORLD_FACT:([^\]]+)\]/', $text, $matches)) {
            foreach ($matches[1] as $fact) {
                $updates[] = ['type' => 'world_fact', 'fact' => trim($fact)];
            }
        }

        return $updates;
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
            '/\s*\[LOCATION:[\w]+:[^\]]+\]/',
            '/\s*\[SPELL:[\w-]+:\d+\]/',
            '/\s*\[DEATH_SAVE:\d+\]/',
        ];

        return trim(preg_replace($patterns, '', $text));
    }

    private function stripWorldStateTags(string $text): string
    {
        $patterns = [
            '/\s*\[QUEST_ADD:[^\]]+\]/',
            '/\s*\[QUEST_COMPLETE:[^\]]+\]/',
            '/\s*\[NPC:[^\]]+\]/',
            '/\s*\[WORLD_FACT:[^\]]+\]/',
        ];

        return trim(preg_replace($patterns, '', $text));
    }

    // =========================================================
    // Action Application
    // =========================================================

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
                    'location_change' => $this->applyLocationChange($campaign, $action['category'], $action['name']),
                    'spell_cast' => $this->applySpellCast($character, $action['spell'], $action['level']),
                    'death_save' => $this->applyDeathSave($character, $action['roll']),
                    default => null,
                };
            } catch (\Throwable $e) {
                Log::warning("Failed to apply game action: " . json_encode($action), ['error' => $e->getMessage()]);
            }
        }
    }

    private function applyWorldStateUpdates(Campaign $campaign, array $updates): void
    {
        $state = $campaign->gameState;
        if (!$state) return;

        $dirty = false;

        foreach ($updates as $update) {
            try {
                match ($update['type']) {
                    'quest_add' => (function () use ($state, $update, &$dirty) {
                        $quests = $state->quest_log ?? [];
                        // Don't add duplicates
                        foreach ($quests as $q) {
                            if (strcasecmp($q['title'], $update['title']) === 0) return;
                        }
                        $quests[] = [
                            'title' => $update['title'],
                            'description' => $update['description'],
                            'status' => 'active',
                        ];
                        $state->quest_log = $quests;
                        $dirty = true;
                    })(),
                    'quest_complete' => (function () use ($state, $update, &$dirty) {
                        $quests = $state->quest_log ?? [];
                        foreach ($quests as &$q) {
                            if (strcasecmp($q['title'], $update['title']) === 0) {
                                $q['status'] = 'completed';
                                $dirty = true;
                            }
                        }
                        $state->quest_log = $quests;
                    })(),
                    'npc' => (function () use ($state, $update, &$dirty) {
                        $npcs = $state->npc_tracker ?? [];
                        $found = false;
                        foreach ($npcs as &$npc) {
                            if (strcasecmp($npc['name'], $update['name']) === 0) {
                                $npc['disposition'] = $update['disposition'];
                                $npc['notes'] = $update['notes'];
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $npcs[] = [
                                'name' => $update['name'],
                                'disposition' => $update['disposition'],
                                'notes' => $update['notes'],
                            ];
                        }
                        $state->npc_tracker = $npcs;
                        $dirty = true;
                    })(),
                    'world_fact' => (function () use ($state, $update, &$dirty) {
                        $facts = $state->world_facts ?? [];
                        // Don't add near-duplicates
                        foreach ($facts as $f) {
                            $existing = is_string($f) ? $f : ($f['fact'] ?? '');
                            if (strcasecmp($existing, $update['fact']) === 0) return;
                        }
                        $facts[] = $update['fact'];
                        $state->world_facts = $facts;
                        $dirty = true;
                    })(),
                    default => null,
                };
            } catch (\Throwable $e) {
                Log::warning("Failed to apply world state update: " . json_encode($update), ['error' => $e->getMessage()]);
            }
        }

        if ($dirty) {
            $state->save();
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

    private function applySpellCast(Character $character, string $spellSlug, int $level): void
    {
        if (!$this->spellcastingService) return;

        $result = $this->spellcastingService->castSpell($character, $spellSlug, $level);
        if (isset($result['error'])) {
            Log::info("Spell cast via DM tag failed: {$result['error']}", [
                'spell' => $spellSlug, 'level' => $level,
            ]);
        }
    }

    private function applyDeathSave(Character $character, int $roll): void
    {
        if (!$this->combatService) return;

        $this->combatService->deathSave($character, $roll);
    }

    private function applyLocationChange(Campaign $campaign, string $category, string $name): void
    {
        if (!$this->mapService) return;

        $map = $this->mapService->assignMapForLocation($campaign, $category, $name);
        if ($map) {
            $this->mapService->setActiveMap($campaign, $map);

            // Update game state location
            $state = $campaign->gameState;
            if ($state) {
                $state->current_location = $name;
                $state->save();
            }
        }
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
