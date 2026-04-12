<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $campaigns = $request->user()
            ->campaigns()
            ->withCount(['characters', 'sessions'])
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

        return response()->json($campaign, 201);
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign);

        $campaign->load(['characters', 'gameState', 'activeSession']);

        return response()->json($campaign);
    }

    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign);

        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted']);
    }

    private function authorizeCampaign(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
    }
}
