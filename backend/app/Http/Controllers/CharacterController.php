<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Character;
use App\Services\CharacterProgressionService;
use App\Services\CombatService;
use App\Services\RestService;
use App\Services\SpellcastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
    public function __construct(
        private CharacterProgressionService $progression,
        private SpellcastingService $spellcasting,
        private CombatService $combat,
        private RestService $rest,
    ) {}

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'race' => 'required|string|max:50',
            'character_class' => 'required|string|max:50',
            'level' => 'integer|min:1|max:20',
            'stats' => 'required|array',
            'stats.str' => 'required|integer|min:1|max:30',
            'stats.dex' => 'required|integer|min:1|max:30',
            'stats.con' => 'required|integer|min:1|max:30',
            'stats.int' => 'required|integer|min:1|max:30',
            'stats.wis' => 'required|integer|min:1|max:30',
            'stats.cha' => 'required|integer|min:1|max:30',
            'hp' => 'required|integer|min:1',
            'max_hp' => 'required|integer|min:1',
            'inventory' => 'nullable|array',
            'backstory' => 'nullable|string',
            'template_id' => 'nullable|exists:character_templates,id',
        ]);

        $character = $campaign->characters()->create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        $this->progression->initializeCharacter($character);

        return response()->json($character->fresh(), 201);
    }

    public function show(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        return response()->json($this->progression->getFullSheet($character));
    }

    public function update(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'race' => 'sometimes|string|max:50',
            'character_class' => 'sometimes|string|max:50',
            'level' => 'sometimes|integer|min:1|max:20',
            'stats' => 'sometimes|array',
            'hp' => 'sometimes|integer|min:0',
            'max_hp' => 'sometimes|integer|min:1',
            'inventory' => 'nullable|array',
            'backstory' => 'nullable|string',
        ]);

        $character->update($validated);

        return response()->json($character);
    }

    public function levelUp(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $result = $this->progression->levelUp($character);

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function addXp(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'xp' => 'required|integer|min:1',
        ]);

        return response()->json($this->progression->addXp($character, $validated['xp']));
    }

    public function applyAsi(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'increases' => 'required|array',
            'increases.*' => 'integer|min:0|max:2',
        ]);

        $result = $this->progression->applyAbilityScoreImprovement($character, $validated['increases']);

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    // Spellcasting

    public function castSpell(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'spell' => 'required|string',
            'slot_level' => 'nullable|integer|min:1|max:9',
        ]);

        $result = $this->spellcasting->castSpell($character, $validated['spell'], $validated['slot_level'] ?? null);

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function prepareSpells(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'spells' => 'required|array',
            'spells.*' => 'string',
        ]);

        $result = $this->spellcasting->prepareSpells($character, $validated['spells']);

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function learnSpell(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'spell' => 'required|string',
        ]);

        $result = $this->spellcasting->learnSpell($character, $validated['spell']);

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function spells(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        return response()->json([
            'known' => $character->known_spells ?? [],
            'prepared' => $character->prepared_spells ?? [],
            'cantrips' => $character->cantrips ?? [],
            'slots' => $this->spellcasting->getSpellSlotsStatus($character),
            'available' => $this->spellcasting->getAvailableSpells($character),
        ]);
    }

    // Rest

    public function shortRest(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'hit_dice' => 'integer|min:0|max:20',
        ]);

        return response()->json($this->rest->shortRest($character, $validated['hit_dice'] ?? 0));
    }

    public function longRest(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        return response()->json($this->rest->longRest($character));
    }

    // Damage & Conditions

    public function applyDamage(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'damage' => 'required|integer|min:1',
            'damage_type' => 'string',
        ]);

        return response()->json(
            $this->combat->applyDamage($character, $validated['damage'], $validated['damage_type'] ?? '')
        );
    }

    public function heal(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
        ]);

        $character->hp = min($character->max_hp, $character->hp + $validated['amount']);
        $character->save();

        return response()->json([
            'hp' => $character->hp,
            'max_hp' => $character->max_hp,
        ]);
    }

    public function deathSave(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'roll' => 'required|integer|min:1|max:20',
        ]);

        $result = $this->combat->deathSave($character, $validated['roll']);

        if (isset($result['error'])) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function equip(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'slot' => 'required|string|in:armor,weapon,shield,offhand',
            'item' => 'nullable|string',
        ]);

        $equipped = $character->equipped ?? [];
        if ($validated['item']) {
            $equipped[$validated['slot']] = $validated['item'];
        } else {
            unset($equipped[$validated['slot']]);
        }
        $character->equipped = $equipped;
        $character->armor_class = $this->progression->calculateAC($character);
        $character->save();

        return response()->json([
            'equipped' => $character->equipped,
            'armor_class' => $character->armor_class,
        ]);
    }
}
