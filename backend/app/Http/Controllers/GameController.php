<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\GameSession;
use App\Services\DungeonMasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function __construct(private DungeonMasterService $dm) {}

    public function startSession(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        // End any existing active session first
        $activeSession = $campaign->activeSession;
        if ($activeSession) {
            $this->dm->endSession($activeSession);
        }

        $session = $this->dm->startSession($campaign);

        return response()->json($session, 201);
    }

    public function showSession(Request $request, Campaign $campaign, GameSession $session): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $session->load('messages');

        return response()->json($session);
    }

    public function sendMessage(Request $request, Campaign $campaign, GameSession $session): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($session->status === 'active', 422, 'Session is not active');

        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $response = $this->dm->chat($session, $request->input('message'));

        return response()->json($response);
    }

    public function getMessages(Request $request, Campaign $campaign, GameSession $session): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $messages = $session->messages()
            ->orderBy('created_at')
            ->get();

        return response()->json($messages);
    }

    public function endSession(Request $request, Campaign $campaign, GameSession $session): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);
        abort_unless($session->status === 'active', 422, 'Session is already ended');

        $session = $this->dm->endSession($session);

        return response()->json($session);
    }
}
