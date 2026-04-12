<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CombatEncounter;
use App\Models\GameSession;
use App\Services\CombatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CombatController extends Controller
{
    public function __construct(private CombatService $combat) {}

    public function start(Request $request, Campaign $campaign, GameSession $session): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'combatants' => 'required|array|min:2',
            'combatants.*.name' => 'required|string',
            'combatants.*.hp' => 'required|integer|min:1',
            'combatants.*.max_hp' => 'required|integer|min:1',
            'combatants.*.ac' => 'required|integer|min:1',
            'combatants.*.initiative' => 'required|integer',
            'combatants.*.is_player' => 'boolean',
            'combatants.*.character_id' => 'nullable|integer',
            'combatants.*.attack_modifier' => 'integer',
        ]);

        $encounter = $this->combat->initiateCombat($session, $validated['combatants']);

        return response()->json($encounter, 201);
    }

    public function show(Request $request, Campaign $campaign, GameSession $session): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $encounter = $this->combat->getActiveCombat($session);

        if (!$encounter) {
            return response()->json(['error' => 'No active combat'], 404);
        }

        return response()->json($encounter);
    }

    public function nextTurn(Request $request, Campaign $campaign, GameSession $session, CombatEncounter $encounter): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        return response()->json($this->combat->nextTurn($encounter));
    }

    public function attack(Request $request, Campaign $campaign, GameSession $session, CombatEncounter $encounter): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'attacker_index' => 'required|integer|min:0',
            'target_index' => 'required|integer|min:0',
            'attack_roll' => 'required|integer|min:1',
            'damage' => 'nullable|integer|min:0',
            'damage_type' => 'string',
        ]);

        $result = $this->combat->resolveAttack(
            $encounter,
            $validated['attacker_index'],
            $validated['target_index'],
            $validated['attack_roll'],
            $validated['damage'] ?? null,
            $validated['damage_type'] ?? 'slashing',
        );

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function end(Request $request, Campaign $campaign, GameSession $session, CombatEncounter $encounter): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $this->combat->endCombat($encounter);

        return response()->json(['status' => 'ended']);
    }
}
