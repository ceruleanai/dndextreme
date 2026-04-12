<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combat_encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_session_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->unsignedInteger('round')->default(1);
            $table->unsignedInteger('current_turn')->default(0);
            $table->json('initiative_order');
            $table->json('combat_log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combat_encounters');
    }
};
