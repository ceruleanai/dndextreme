<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');
            $table->enum('map_type', ['world', 'region', 'location', 'battle']);
            $table->string('category', 50)->nullable();
            $table->string('location_name', 255)->nullable();
            $table->enum('image_source', ['bundled', 'uploaded', 'generated'])->default('bundled');
            $table->string('image_path', 500);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'location_name']);
            $table->index(['campaign_id', 'map_type']);
        });

        Schema::table('game_states', function (Blueprint $table) {
            $table->foreignId('current_map_id')->nullable()->after('world_facts')
                ->constrained('campaign_maps')->nullOnDelete();
        });

        Schema::table('combat_encounters', function (Blueprint $table) {
            $table->foreignId('map_id')->nullable()->after('combat_log')
                ->constrained('campaign_maps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('combat_encounters', function (Blueprint $table) {
            $table->dropForeign(['map_id']);
            $table->dropColumn('map_id');
        });

        Schema::table('game_states', function (Blueprint $table) {
            $table->dropForeign(['current_map_id']);
            $table->dropColumn('current_map_id');
        });

        Schema::dropIfExists('campaign_maps');
    }
};
