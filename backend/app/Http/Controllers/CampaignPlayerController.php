<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignPlayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignPlayerController extends Controller
{
    public function players(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);

        $players = $campaign->players()
            ->with(['user:id,name,email', 'character:id,name,race,character_class,level,portrait_path'])
            ->get();

        return response()->json($players);
    }

    public function generateInvite(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isOwner($request->user()), 403);

        $code = $campaign->invite_code ?? $campaign->generateInviteCode();

        return response()->json(['invite_code' => $code]);
    }

    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invite_code' => 'required|string|size:6',
        ]);

        $campaign = Campaign::where('invite_code', strtoupper($validated['invite_code']))->first();

        if (!$campaign) {
            return response()->json(['error' => 'Invalid invite code'], 404);
        }

        $user = $request->user();

        // Already a member?
        if ($campaign->isMember($user)) {
            return response()->json([
                'campaign_id' => $campaign->id,
                'message' => 'Already a member',
            ]);
        }

        $campaign->players()->create([
            'user_id' => $user->id,
            'role' => 'player',
        ]);

        return response()->json([
            'campaign_id' => $campaign->id,
            'campaign_title' => $campaign->title,
            'message' => 'Joined successfully',
        ], 201);
    }

    public function selectCharacter(Request $request, Campaign $campaign): JsonResponse
    {
        $user = $request->user();
        abort_unless($campaign->isMember($user), 403);

        $validated = $request->validate([
            'character_id' => 'required|exists:characters,id',
        ]);

        $character = $campaign->characters()->findOrFail($validated['character_id']);

        // Make sure the character isn't claimed by another player
        $existingClaim = $campaign->players()
            ->where('character_id', $character->id)
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existingClaim) {
            return response()->json(['error' => 'This character is already claimed by another player'], 422);
        }

        $playerRecord = $campaign->getPlayerRecord($user);
        $playerRecord->update(['character_id' => $character->id]);

        // Also update the character's user_id to match
        $character->update(['user_id' => $user->id]);

        return response()->json([
            'character_id' => $character->id,
            'character_name' => $character->name,
        ]);
    }

    public function leave(Request $request, Campaign $campaign): JsonResponse
    {
        $user = $request->user();

        // Owner can't leave
        if ($campaign->isOwner($user)) {
            return response()->json(['error' => 'Campaign owner cannot leave'], 422);
        }

        $campaign->players()->where('user_id', $user->id)->delete();

        return response()->json(['message' => 'Left campaign']);
    }

    public function kick(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isOwner($request->user()), 403);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        if ($validated['user_id'] == $request->user()->id) {
            return response()->json(['error' => 'Cannot kick yourself'], 422);
        }

        $campaign->players()->where('user_id', $validated['user_id'])->delete();

        return response()->json(['message' => 'Player removed']);
    }
}
