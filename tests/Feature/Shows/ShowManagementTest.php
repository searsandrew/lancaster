<?php

use App\Enums\QuizScoringMode;
use App\Enums\ShowActivationMode;
use App\Models\Show;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from show management to login', function () {
    $response = $this->get(route('shows.index'));

    $response->assertRedirect(route('login'));
});

test('staff can view show management', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('shows.index'));

    $response
        ->assertOk()
        ->assertSee('New show');
});

test('staff can create an active manual show with per-answer scoring', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::shows.index')
        ->set('name', 'Manufacturing Expo')
        ->set('isActive', true)
        ->call('createShow')
        ->assertHasNoErrors();

    $show = Show::query()->with('quiz')->sole();

    expect($show)
        ->name->toBe('Manufacturing Expo')
        ->slug->toBe('manufacturing-expo')
        ->activation_mode->toBe(ShowActivationMode::Manual)
        ->is_active->toBeTrue()
        ->starts_at->toBeNull()
        ->ends_at->toBeNull()
        ->and($show->quiz->scoring_mode)->toBe(QuizScoringMode::PerAnswer)
        ->and($show->quiz->maximum_score)->toBeNull();
});

test('staff can create a scheduled show with summary scoring', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::shows.index')
        ->set('name', 'Fall Conference')
        ->set('activationMode', 'scheduled')
        ->set('startDate', '2026-10-10')
        ->set('startTime', '09:30')
        ->set('endDate', '2026-10-10')
        ->set('endTime', '17:00')
        ->set('scoringMode', 'summary')
        ->set('maximumScore', 20)
        ->call('createShow')
        ->assertHasNoErrors();

    $show = Show::query()->with('quiz')->sole();

    expect($show)
        ->activation_mode->toBe(ShowActivationMode::Scheduled)
        ->is_active->toBeFalse()
        ->and($show->starts_at?->format('Y-m-d H:i'))->toBe('2026-10-10 09:30')
        ->and($show->ends_at?->format('Y-m-d H:i'))->toBe('2026-10-10 17:00')
        ->and($show->quiz->scoring_mode)->toBe(QuizScoringMode::Summary)
        ->and($show->quiz->maximum_score)->toBe(20);
});

test('scheduled shows require an end after their start', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::shows.index')
        ->set('name', 'Fall Conference')
        ->set('activationMode', 'scheduled')
        ->set('startDate', '2026-10-10')
        ->set('startTime', '17:00')
        ->set('endDate', '2026-10-10')
        ->set('endTime', '09:30')
        ->call('createShow')
        ->assertHasErrors(['endDate' => 'The show must end after it starts.']);

    expect(Show::query()->exists())->toBeFalse();
});

test('shows with duplicate names receive unique slugs', function () {
    $user = User::factory()->create();
    $existingShow = Show::factory()->create(['name' => 'Manufacturing Expo', 'slug' => 'manufacturing-expo']);
    $existingShow->quiz()->create(['scoring_mode' => QuizScoringMode::PerAnswer]);

    Livewire::actingAs($user)
        ->test('pages::shows.index')
        ->set('name', 'Manufacturing Expo')
        ->call('createShow')
        ->assertHasNoErrors();

    expect(Show::query()->where('slug', 'manufacturing-expo-2')->exists())->toBeTrue();
});
