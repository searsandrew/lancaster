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
        Schema::table('questions', function (Blueprint $table) {
            $table->string('answer_type')->default('free_text')->after('correct_answer');
            $table->json('accepted_answers')->nullable()->after('answer_type');
            $table->json('answer_options')->nullable()->after('accepted_answers');
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->string('automatic_match_method')->nullable()->after('is_correct');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropColumn('automatic_match_method');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['answer_type', 'accepted_answers', 'answer_options']);
        });
    }
};
