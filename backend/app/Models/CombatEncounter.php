<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CombatEncounter extends Model
{
    protected $fillable = [
        'game_session_id', 'status', 'round', 'current_turn',
        'initiative_order', 'combat_log', 'map_id',
    ];

    protected function casts(): array
    {
        return [
            'initiative_order' => 'array',
            'combat_log' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    public function getCurrentCombatant(): ?array
    {
        $order = $this->initiative_order ?? [];
        return $order[$this->current_turn] ?? null;
    }
}
