<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'session_number', 'summary', 'status',
        'context_summary', 'summarized_up_to',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function combatEncounters(): HasMany
    {
        return $this->hasMany(CombatEncounter::class);
    }

    public function activeCombat()
    {
        return $this->hasOne(CombatEncounter::class)->where('status', 'active');
    }
}
