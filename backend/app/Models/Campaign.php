<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'setting', 'ai_provider', 'status', 'invite_code',
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

    public function maps(): HasMany
    {
        return $this->hasMany(CampaignMap::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(CampaignPlayer::class);
    }

    public function isOwner(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function isMember(User $user): bool
    {
        return $this->players()->where('user_id', $user->id)->exists();
    }

    public function getPlayerRecord(User $user): ?CampaignPlayer
    {
        return $this->players()->where('user_id', $user->id)->first();
    }

    public function generateInviteCode(): string
    {
        $code = strtoupper(Str::random(6));
        $this->update(['invite_code' => $code]);
        return $code;
    }
}
