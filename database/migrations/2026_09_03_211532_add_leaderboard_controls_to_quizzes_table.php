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
        Schema::table('quizzes', function (Blueprint $table) {
            $table->string('leaderboard_display_mode')->default('leaderboard')->after('leaderboard_message');
            $table->string('advertisement_embed_url', 2048)->nullable()->after('leaderboard_display_mode');
            $table->unsignedBigInteger('confetti_flash_sequence')->default(0)->after('advertisement_embed_url');
            $table->unsignedBigInteger('perfect_score_flash_sequence')->default(0)->after('confetti_flash_sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn([
                'leaderboard_display_mode',
                'advertisement_embed_url',
                'confetti_flash_sequence',
                'perfect_score_flash_sequence',
            ]);
        });
    }
};
