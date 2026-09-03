<?php

use App\Enums\LeaderboardDisplayMode;
use App\Models\Quiz;
use App\Models\Show;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSeeText($show->name);
});

test('the previous quiz URL redirects authenticated staff to the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/quiz')
        ->assertRedirect('/dashboard');
});

test('staff can switch the leaderboard to the QR code and back', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();
    $component = Livewire::actingAs($user)->test('pages::dashboard');

    $component
        ->call('setLeaderboardDisplayMode', 'qr_code')
        ->assertSet('leaderboardDisplayMode', 'qr_code');

    expect($quiz->refresh()->leaderboard_display_mode)->toBe(LeaderboardDisplayMode::QrCode);

    $component
        ->call('setLeaderboardDisplayMode', 'leaderboard')
        ->assertSet('leaderboardDisplayMode', 'leaderboard');

    expect($quiz->refresh()->leaderboard_display_mode)->toBe(LeaderboardDisplayMode::Leaderboard);
});

test('the advertisement display cannot be selected without an embed URL', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('setLeaderboardDisplayMode', 'advertisement')
        ->assertSet('leaderboardDisplayMode', 'leaderboard');

    expect($quiz->refresh()->leaderboard_display_mode)->toBe(LeaderboardDisplayMode::Leaderboard);
});

test('staff can select an advertisement configured for the active quiz', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create([
        'advertisement_embed_url' => 'https://www.youtube.com/embed/example?autoplay=1',
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('setLeaderboardDisplayMode', 'advertisement')
        ->assertSet('leaderboardDisplayMode', 'advertisement');

    expect($quiz->refresh()->leaderboard_display_mode)->toBe(LeaderboardDisplayMode::Advertisement);
});

test('staff can send celebration commands to the leaderboard', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('flashConfetti')
        ->call('flashPerfectScore');

    expect($quiz->refresh())
        ->confetti_flash_sequence->toBe(1)
        ->perfect_score_flash_sequence->toBe(1);
});
