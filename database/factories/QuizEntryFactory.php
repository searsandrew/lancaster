<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Quiz;
use App\Models\QuizEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizEntry>
 */
class QuizEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'quiz_id' => Quiz::factory(),
            'staff_user_id' => User::factory(),
            'score' => null,
            'elapsed_ms' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function completed(int $score = 1, int $elapsedMs = 1000): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => $score,
            'elapsed_ms' => $elapsedMs,
            'completed_at' => now(),
        ]);
    }
}
