<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignPlayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Show campaigns where user is owner OR a player
        $userId = $request->user()->id;
        $campaignIds = CampaignPlayer::where('user_id', $userId)->pluck('campaign_id');

        $campaigns = Campaign::whereIn('id', $campaignIds)
            ->withCount(['characters', 'sessions', 'players'])
            ->latest()
            ->get();

        return response()->json($campaigns);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'setting' => 'required|string',
            'ai_provider' => 'nullable|string|in:claude,gemini',
        ]);

        $campaign = $request->user()->campaigns()->create($validated);

        // Add creator as owner in campaign_players
        $campaign->players()->create([
            'user_id' => $request->user()->id,
            'role' => 'owner',
        ]);

        // Generate invite code
        $campaign->generateInviteCode();

        return response()->json($campaign->fresh(), 201);
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);

        $campaign->load([
            'characters',
            'gameState',
            'activeSession',
            'players.user:id,name,email',
            'players.character:id,name,race,character_class,level,portrait_path',
        ]);

        return response()->json($campaign);
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isOwner($request->user()), 403);

        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted']);
    }
}
