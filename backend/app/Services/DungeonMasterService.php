<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\GameSession;
use App\Models\GameState;
use App\Models\Message;
use App\Services\AI\AIManager;

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
PROMPT;

    public function __construct(private AIManager $aiManager) {}

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

        // Save and return the DM response
        return $session->messages()->create([
            'role' => 'assistant',
            'type' => 'narrative',
            'content' => $cleanContent,
            'metadata' => $mood ? ['mood' => $mood] : null,
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

        // Character sheets
        foreach ($campaign->characters as $character) {
            $stats = $character->stats;
            $inventory = $character->inventory ? implode(', ', $character->inventory) : 'None';
            $parts[] = implode("\n", [
                "PLAYER CHARACTER:",
                "Name: {$character->name}",
                "Race: {$character->race} | Class: {$character->character_class} | Level: {$character->level}",
                "Stats: STR {$stats['str']} DEX {$stats['dex']} CON {$stats['con']} INT {$stats['int']} WIS {$stats['wis']} CHA {$stats['cha']}",
                "HP: {$character->hp}/{$character->max_hp}",
                "Inventory: {$inventory}",
                $character->backstory ? "Backstory: {$character->backstory}" : '',
            ]);
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
