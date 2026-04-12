<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterController extends Controller
{
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
        ]);

        $character = $campaign->characters()->create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        return response()->json($character, 201);
    }

    public function show(Request $request, Campaign $campaign, Character $character): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        return response()->json($character);
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
}
