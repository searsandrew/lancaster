<?php

namespace Database\Factories;

use App\Models\QuizAnswer;
use App\Models\QuizEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAnswer>
 */
class QuizAnswerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quiz_entry_id' => QuizEntry::factory(),
            'question_id' => null,
            'question_prompt' => fake()->sentence(),
            'position' => fake()->numberBetween(1, 1000),
            'is_correct' => fake()->boolean(),
            'elapsed_ms' => fake()->numberBetween(100, 60000),
        ];
    }
}
