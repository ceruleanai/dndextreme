<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameState extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'campaign_id', 'current_location', 'quest_log', 'npc_tracker', 'world_facts',
        'current_map_id',
    ];

    protected function casts(): array
    {
        return [
            'quest_log' => 'array',
            'npc_tracker' => 'array',
            'world_facts' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function currentMap(): BelongsTo
    {
        return $this->belongsTo(CampaignMap::class, 'current_map_id');
    }
}
