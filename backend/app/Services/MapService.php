<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignMap;
use Illuminate\Support\Facades\Cache;

class MapService
{
    private const VALID_CATEGORIES = [
        'tavern', 'forest', 'dungeon', 'cave', 'town', 'castle',
        'road', 'camp', 'temple', 'ruins', 'mountain', 'swamp',
        'dock', 'sewer', 'ship',
    ];

    public function getManifest(): array
    {
        return Cache::rememberForever('maps.manifest', function () {
            $path = public_path('maps/manifest.json');
            if (!file_exists($path)) {
                return [];
            }
            return json_decode(file_get_contents($path), true) ?? [];
        });
    }

    public function assignMapForLocation(Campaign $campaign, string $category, string $locationName): ?CampaignMap
    {
        // Check if we already have a map for this location
        $existing = $campaign->maps()
            ->where('location_name', $locationName)
            ->where('map_type', 'location')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Normalize category
        $category = strtolower(trim($category));
        if (!in_array($category, self::VALID_CATEGORIES)) {
            $category = $this->inferCategory($category);
        }

        // Pick a random bundled map for this category
        $imagePath = $this->pickBundledMap($campaign, $category);
        if (!$imagePath) {
            return null;
        }

        return $campaign->maps()->create([
            'map_type' => 'location',
            'category' => $category,
            'location_name' => $locationName,
            'image_source' => 'bundled',
            'image_path' => $imagePath,
        ]);
    }

    public function assignBattleMap(Campaign $campaign, ?string $category = null): ?CampaignMap
    {
        // Use current location category if not specified
        if (!$category) {
            $state = $campaign->gameState;
            $currentMap = $state?->currentMap;
            $category = $currentMap?->category ?? 'dungeon';
        }

        $imagePath = $this->pickBundledMap($campaign, $category);
        if (!$imagePath) {
            // Fallback to dungeon
            $imagePath = $this->pickBundledMap($campaign, 'dungeon');
        }
        if (!$imagePath) {
            return null;
        }

        return $campaign->maps()->create([
            'map_type' => 'battle',
            'category' => $category,
            'image_source' => 'bundled',
            'image_path' => $imagePath,
        ]);
    }

    private function pickBundledMap(Campaign $campaign, string $category): ?string
    {
        $manifest = $this->getManifest();
        $available = $manifest[$category] ?? [];

        if (empty($available)) {
            return null;
        }

        // Get already-used maps for this campaign/category to avoid repeats
        $usedPaths = $campaign->maps()
            ->where('category', $category)
            ->where('image_source', 'bundled')
            ->pluck('image_path')
            ->toArray();

        // Filter out used maps
        $unused = array_filter($available, function ($file) use ($category, $usedPaths) {
            return !in_array("/maps/{$category}/{$file}", $usedPaths);
        });

        // If all used, allow repeats
        if (empty($unused)) {
            $unused = $available;
        }

        $picked = $unused[array_rand($unused)];
        return "/maps/{$category}/{$picked}";
    }

    private function inferCategory(string $input): string
    {
        $mapping = [
            'inn' => 'tavern', 'pub' => 'tavern', 'bar' => 'tavern',
            'woods' => 'forest', 'jungle' => 'forest', 'grove' => 'forest',
            'crypt' => 'dungeon', 'tomb' => 'dungeon', 'lair' => 'dungeon', 'mine' => 'dungeon',
            'cavern' => 'cave', 'grotto' => 'cave',
            'city' => 'town', 'village' => 'town', 'market' => 'town', 'square' => 'town',
            'fortress' => 'castle', 'keep' => 'castle', 'palace' => 'castle', 'tower' => 'castle',
            'trail' => 'road', 'path' => 'road', 'bridge' => 'road',
            'fire' => 'camp', 'rest' => 'camp', 'clearing' => 'camp',
            'shrine' => 'temple', 'church' => 'temple', 'chapel' => 'temple', 'altar' => 'temple',
            'ruin' => 'ruins', 'abandoned' => 'ruins', 'ancient' => 'ruins',
            'cliff' => 'mountain', 'peak' => 'mountain', 'hill' => 'mountain',
            'marsh' => 'swamp', 'bog' => 'swamp', 'wetland' => 'swamp',
            'port' => 'dock', 'harbor' => 'dock', 'pier' => 'dock', 'wharf' => 'dock',
            'boat' => 'ship', 'vessel' => 'ship', 'deck' => 'ship',
            'underground' => 'sewer', 'drain' => 'sewer', 'tunnel' => 'sewer',
        ];

        foreach ($mapping as $keyword => $cat) {
            if (str_contains($input, $keyword)) {
                return $cat;
            }
        }

        return 'forest'; // safe default
    }

    public function setActiveMap(Campaign $campaign, CampaignMap $map): void
    {
        $state = $campaign->gameState;
        if ($state) {
            $state->current_map_id = $map->id;
            $state->save();
        }
    }
}
