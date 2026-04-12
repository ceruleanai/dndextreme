<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterTemplate extends Model
{
    protected $fillable = [
        'user_id', 'name', 'race', 'character_class', 'base_stats', 'backstory',
    ];

    protected function casts(): array
    {
        return ['base_stats' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'template_id');
    }
}
