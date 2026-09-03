<?php

namespace Database\Factories;

use App\Enums\ShowActivationMode;
use App\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Show>
 */
class ShowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Expo',
            'slug' => fake()->unique()->slug(3),
            'activation_mode' => ShowActivationMode::Manual,
            'is_active' => false,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'activation_mode' => ShowActivationMode::Manual,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    public function scheduled(string $startsAt = '2026-09-03 09:00:00', string $endsAt = '2026-09-03 17:00:00'): static
    {
        return $this->state(fn (array $attributes) => [
            'activation_mode' => ShowActivationMode::Scheduled,
            'is_active' => false,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
