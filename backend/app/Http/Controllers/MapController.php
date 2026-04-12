<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignMap;
use App\Services\MapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapController extends Controller
{
    public function __construct(private MapService $maps) {}

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $maps = $campaign->maps()
            ->when($request->query('type'), fn($q, $type) => $q->where('map_type', $type))
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($maps);
    }

    public function current(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $state = $campaign->gameState;
        if (!$state || !$state->current_map_id) {
            return response()->json(null);
        }

        return response()->json($state->currentMap);
    }

    public function upload(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $request->validate([
            'image' => 'required|image|max:10240',
            'map_type' => 'required|in:world,region,location,battle',
            'category' => 'nullable|string|max:50',
            'location_name' => 'nullable|string|max:255',
        ]);

        $path = $request->file('image')->store('maps', 'public');

        $map = $campaign->maps()->create([
            'map_type' => $request->input('map_type'),
            'category' => $request->input('category'),
            'location_name' => $request->input('location_name'),
            'image_source' => 'uploaded',
            'image_path' => '/storage/' . $path,
        ]);

        return response()->json($map, 201);
    }

    public function setActive(Request $request, Campaign $campaign, CampaignMap $map): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $state = $campaign->gameState;
        if ($state) {
            $state->current_map_id = $map->id;
            $state->save();
        }

        return response()->json($map);
    }

    public function destroy(Request $request, Campaign $campaign, CampaignMap $map): JsonResponse
    {
        abort_unless($campaign->user_id === $request->user()->id, 403);

        $map->delete();

        return response()->json(null, 204);
    }

    public function bundledCategories(): JsonResponse
    {
        return response()->json($this->maps->getManifest());
    }
}
