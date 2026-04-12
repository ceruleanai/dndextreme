<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Character extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'campaign_id', 'name', 'race', 'character_class',
        'level', 'stats', 'hp', 'max_hp', 'inventory', 'backstory',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'inventory' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
