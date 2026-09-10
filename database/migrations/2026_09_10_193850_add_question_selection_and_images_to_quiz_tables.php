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
            $table->unsignedSmallInteger('questions_per_entry')->nullable()->after('maximum_score');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('prompt');
        });

        Schema::table('quiz_entries', function (Blueprint $table) {
            $table->json('question_ids')->nullable()->after('quiz_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quiz_entries', function (Blueprint $table) {
            $table->dropColumn('question_ids');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn('questions_per_entry');
        });
    }
};
