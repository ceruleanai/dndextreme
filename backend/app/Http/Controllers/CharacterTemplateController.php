<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CharacterTemplate;
use App\Services\CharacterProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterTemplateController extends Controller
{
    public function __construct(private CharacterProgressionService $progression) {}

    public function index(Request $request): JsonResponse
    {
        $templates = $request->user()->characterTemplates()
            ->with('characters:id,template_id,campaign_id,level')
            ->get();

        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'race' => 'required|string|max:50',
            'character_class' => 'required|string|max:50',
            'base_stats' => 'required|array',
            'base_stats.str' => 'required|integer|min:1|max:30',
            'base_stats.dex' => 'required|integer|min:1|max:30',
            'base_stats.con' => 'required|integer|min:1|max:30',
            'base_stats.int' => 'required|integer|min:1|max:30',
            'base_stats.wis' => 'required|integer|min:1|max:30',
            'base_stats.cha' => 'required|integer|min:1|max:30',
            'backstory' => 'nullable|string',
        ]);

        $template = $request->user()->characterTemplates()->create($validated);

        return response()->json($template, 201);
    }

    public function show(Request $request, CharacterTemplate $template): JsonResponse
    {
        abort_unless($template->user_id === $request->user()->id, 403);

        return response()->json($template->load('characters:id,template_id,campaign_id,level,name'));
    }

    public function destroy(Request $request, CharacterTemplate $template): JsonResponse
    {
        abort_unless($template->user_id === $request->user()->id, 403);

        $template->delete();

        return response()->json(null, 204);
    }

    public function instantiate(Request $request, CharacterTemplate $template, Campaign $campaign): JsonResponse
    {
        abort_unless($template->user_id === $request->user()->id, 403);
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'hp' => 'required|integer|min:1',
            'max_hp' => 'required|integer|min:1',
        ]);

        $character = $campaign->characters()->create([
            'user_id' => $request->user()->id,
            'name' => $template->name,
            'race' => $template->race,
            'character_class' => $template->character_class,
            'stats' => $template->base_stats,
            'backstory' => $template->backstory,
            'hp' => $validated['hp'],
            'max_hp' => $validated['max_hp'],
            'template_id' => $template->id,
        ]);

        $this->progression->initializeCharacter($character);

        return response()->json($character->fresh(), 201);
    }
}
