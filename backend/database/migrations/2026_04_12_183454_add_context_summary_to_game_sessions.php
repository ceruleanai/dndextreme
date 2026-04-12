<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->text('context_summary')->nullable()->after('summary');
            $table->unsignedInteger('summarized_up_to')->default(0)->after('context_summary');
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropColumn(['context_summary', 'summarized_up_to']);
        });
    }
};
