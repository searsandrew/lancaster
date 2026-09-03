<?php

namespace Database\Factories;

use App\Enums\QuizScoringMode;
use App\Models\Quiz;
use App\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'show_id' => Show::factory(),
            'scoring_mode' => QuizScoringMode::PerAnswer,
            'maximum_score' => null,
        ];
    }

    public function summary(int $maximumScore = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'scoring_mode' => QuizScoringMode::Summary,
            'maximum_score' => $maximumScore,
        ]);
    }
}
