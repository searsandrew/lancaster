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
            $table->boolean('show_answer_feedback')->default(false);
            $table->unsignedSmallInteger('second_chance_attempts')->default(0);
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempt_count')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropColumn('attempt_count');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['show_answer_feedback', 'second_chance_attempts']);
        });
    }
};
