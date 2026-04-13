<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('character_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('role', ['owner', 'player'])->default('player');
            $table->string('invite_code', 8)->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
            $table->index('invite_code');
        });

        // Add invite_code to campaigns for easy lookup
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('invite_code', 8)->nullable()->unique()->after('status');
        });

        // Migrate existing campaigns: create campaign_player records for existing owners
        $campaigns = DB::table('campaigns')->get();
        foreach ($campaigns as $campaign) {
            DB::table('campaign_players')->insert([
                'campaign_id' => $campaign->id,
                'user_id' => $campaign->user_id,
                'role' => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('invite_code');
        });
        Schema::dropIfExists('campaign_players');
    }
};
