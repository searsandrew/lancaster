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
        Schema::table('participants', function (Blueprint $table) {
            $table->string('recovery_code', 6)->nullable()->after('marketing_opt_in');
            $table->unique(['show_id', 'recovery_code']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->text('correct_answer')->nullable()->after('prompt');
        });

        Schema::table('quiz_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_question_position')->nullable()->after('completed_at');
            $table->timestamp('question_released_at')->nullable()->after('current_question_position');
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->text('submitted_answer')->nullable()->after('question_prompt');
            $table->timestamp('submitted_at')->nullable()->after('elapsed_ms');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropColumn(['submitted_answer', 'submitted_at', 'reviewed_at']);
        });

        Schema::table('quiz_entries', function (Blueprint $table) {
            $table->dropColumn(['current_question_position', 'question_released_at']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('correct_answer');
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->dropUnique(['show_id', 'recovery_code']);
            $table->dropColumn('recovery_code');
        });
    }
};
