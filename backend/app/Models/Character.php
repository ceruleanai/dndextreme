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
        'xp', 'proficiency_bonus', 'gold', 'armor_class', 'speed',
        'hit_dice_total', 'hit_dice_remaining', 'temp_hp',
        'death_save_successes', 'death_save_failures',
        'spell_slots', 'spell_slots_max', 'proficiencies',
        'conditions', 'equipped', 'class_features',
        'known_spells', 'prepared_spells', 'cantrips', 'template_id',
        'portrait_path',
    ];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'inventory' => 'array',
            'spell_slots' => 'array',
            'spell_slots_max' => 'array',
            'proficiencies' => 'array',
            'conditions' => 'array',
            'equipped' => 'array',
            'class_features' => 'array',
            'known_spells' => 'array',
            'prepared_spells' => 'array',
            'cantrips' => 'array',
            'gold' => 'decimal:2',
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

    public function template(): BelongsTo
    {
        return $this->belongsTo(CharacterTemplate::class, 'template_id');
    }

    public function getAbilityModifier(string $ability): int
    {
        return (int) floor((($this->stats[$ability] ?? 10) - 10) / 2);
    }

    public function getSpellcastingAbility(): ?string
    {
        $map = [
            'Wizard' => 'int',
            'Cleric' => 'wis',
            'Druid' => 'wis',
            'Ranger' => 'wis',
            'Bard' => 'cha',
            'Paladin' => 'cha',
            'Sorcerer' => 'cha',
            'Warlock' => 'cha',
        ];
        return $map[$this->character_class] ?? null;
    }

    public function getSpellSaveDC(): ?int
    {
        $ability = $this->getSpellcastingAbility();
        if (!$ability) return null;
        return 8 + $this->proficiency_bonus + $this->getAbilityModifier($ability);
    }

    public function getSpellAttackMod(): ?int
    {
        $ability = $this->getSpellcastingAbility();
        if (!$ability) return null;
        return $this->proficiency_bonus + $this->getAbilityModifier($ability);
    }
}
