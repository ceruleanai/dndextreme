<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->unsignedInteger('xp')->default(0)->after('backstory');
            $table->unsignedTinyInteger('proficiency_bonus')->default(2)->after('xp');
            $table->decimal('gold', 10, 2)->default(0)->after('proficiency_bonus');
            $table->unsignedTinyInteger('armor_class')->default(10)->after('gold');
            $table->unsignedInteger('speed')->default(30)->after('armor_class');
            $table->unsignedTinyInteger('hit_dice_total')->default(1)->after('speed');
            $table->unsignedTinyInteger('hit_dice_remaining')->default(1)->after('hit_dice_total');
            $table->unsignedInteger('temp_hp')->default(0)->after('hit_dice_remaining');
            $table->unsignedTinyInteger('death_save_successes')->default(0)->after('temp_hp');
            $table->unsignedTinyInteger('death_save_failures')->default(0)->after('death_save_successes');
            $table->json('spell_slots')->nullable()->after('death_save_failures');
            $table->json('spell_slots_max')->nullable()->after('spell_slots');
            $table->json('proficiencies')->nullable()->after('spell_slots_max');
            $table->json('conditions')->nullable()->after('proficiencies');
            $table->json('equipped')->nullable()->after('conditions');
            $table->json('class_features')->nullable()->after('equipped');
            $table->json('known_spells')->nullable()->after('class_features');
            $table->json('prepared_spells')->nullable()->after('known_spells');
            $table->json('cantrips')->nullable()->after('prepared_spells');
            $table->foreignId('template_id')->nullable()->after('cantrips')->constrained('character_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn([
                'xp', 'proficiency_bonus', 'gold', 'armor_class', 'speed',
                'hit_dice_total', 'hit_dice_remaining', 'temp_hp',
                'death_save_successes', 'death_save_failures',
                'spell_slots', 'spell_slots_max', 'proficiencies',
                'conditions', 'equipped', 'class_features',
                'known_spells', 'prepared_spells', 'cantrips', 'template_id',
            ]);
        });
    }
};
