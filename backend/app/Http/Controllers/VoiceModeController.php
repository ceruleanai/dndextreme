<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\GameSession;
use App\Services\DungeonMasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceModeController extends Controller
{
    public function __construct(private DungeonMasterService $dm) {}

    public function getVoiceConfig(Request $request, Campaign $campaign, GameSession $session): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);
        abort_unless($session->status === 'active', 422, 'Session is not active');

        $systemPrompt = $this->dm->buildSystemPrompt($campaign);
        $recentMessages = $this->dm->getRecentMessages($session);

        return response()->json([
            'apiKey' => config('ai.providers.gemini.api_key'),
            'systemPrompt' => $systemPrompt,
            'recentMessages' => $recentMessages,
            'model' => config('ai.live_model', 'gemini-2.5-flash-native-audio-latest'),
        ]);
    }

    public function saveTranscript(Request $request, Campaign $campaign, GameSession $session): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);

        $validated = $request->validate([
            'exchanges' => 'required|array',
            'exchanges.*.role' => 'required|string|in:user,assistant',
            'exchanges.*.text' => 'required|string',
        ]);

        foreach ($validated['exchanges'] as $exchange) {
            $session->messages()->create([
                'role' => $exchange['role'],
                'type' => $exchange['role'] === 'user' ? 'voice_action' : 'voice_narrative',
                'content' => $exchange['text'],
            ]);
        }

        return response()->json(['saved' => count($validated['exchanges'])]);
    }
}
