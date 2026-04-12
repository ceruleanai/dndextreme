<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('game_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('current_location')->nullable();
            $table->json('quest_log')->nullable();
            $table->json('npc_tracker')->nullable();
            $table->json('world_facts')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_states');
    }
};
