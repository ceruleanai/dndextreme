<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'setting', 'ai_provider', 'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(GameSession::class);
    }

    public function gameState(): HasOne
    {
        return $this->hasOne(GameState::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(GameSession::class)->where('status', 'active')->latestOfMany();
    }
}
