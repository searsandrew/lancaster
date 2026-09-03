<?php

use App\Enums\QuizScoringMode;
use App\Enums\ShowActivationMode;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Show;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('guests are redirected from show configuration to login', function () {
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->create();

    $this->get(route('shows.edit', $show))->assertRedirect(route('login'));
});

test('staff can open show configuration by slug', function () {
    $user = User::factory()->create();
    $show = Show::factory()->create(['slug' => 'manufacturing-expo']);
    Quiz::factory()->for($show)->create();

    $this->actingAs($user)
        ->get('/shows/manufacturing-expo/edit')
        ->assertOk()
        ->assertSee($show->name);
});

test('staff can update show and summary scoring configuration', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();
    $question = Question::factory()->for($quiz)->create(['position' => 1]);

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->set('name', 'Updated Conference')
        ->set('activationMode', 'scheduled')
        ->set('startDate', '2026-10-10')
        ->set('startTime', '09:30')
        ->set('endDate', '2026-10-10')
        ->set('endTime', '17:00')
        ->set('scoringMode', 'summary')
        ->set('maximumScore', 25)
        ->set('registrationMessage', 'Your email creates an account on example.com.')
        ->set('leaderboardMessage', 'Sticker pickup at booth 412')
        ->call('save')
        ->assertHasNoErrors();

    $show->refresh();
    $quiz->refresh();

    expect($show)
        ->name->toBe('Updated Conference')
        ->activation_mode->toBe(ShowActivationMode::Scheduled)
        ->is_active->toBeFalse()
        ->and($show->starts_at?->format('Y-m-d H:i'))->toBe('2026-10-10 09:30')
        ->and($show->ends_at?->format('Y-m-d H:i'))->toBe('2026-10-10 17:00')
        ->and($quiz->scoring_mode)->toBe(QuizScoringMode::Summary)
        ->and($quiz->maximum_score)->toBe(25)
        ->and($quiz->registration_message)->toBe('Your email creates an account on example.com.')
        ->and($quiz->leaderboard_message)->toBe('Sticker pickup at booth 412')
        ->and($question->fresh())->not->toBeNull();
});

test('leaderboard messages are limited to the available display space', function () {
    $user = User::factory()->create();
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->summary()->create();

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->set('leaderboardMessage', str_repeat('a', 161))
        ->call('save')
        ->assertHasErrors(['leaderboardMessage']);
});

test('per-answer scoring requires at least one question', function () {
    $user = User::factory()->create();
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->create();

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->call('save')
        ->assertHasErrors(['newQuestion' => 'Add at least one question for per-answer scoring.']);
});

test('staff can add update and remove quiz questions', function () {
    $user = User::factory()->create();
    $show = Show::factory()->create();
    $quiz = Quiz::factory()->for($show)->create();

    $component = Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->set('newQuestion', 'What material is this part made from?')
        ->call('addQuestion')
        ->assertHasNoErrors();

    $question = $quiz->questions()->sole();

    $component
        ->set("questionPrompts.{$question->id}", 'What alloy is this part made from?')
        ->call('updateQuestion', $question->id)
        ->assertHasNoErrors();

    expect($question->fresh()->prompt)->toBe('What alloy is this part made from?');

    $component->call('removeQuestion', $question->id);

    expect($question->fresh())->toBeNull();
});

test('staff can reorder quiz questions', function () {
    $user = User::factory()->create();
    $show = Show::factory()->create();
    $quiz = Quiz::factory()->for($show)->create();
    $first = Question::factory()->for($quiz)->create(['prompt' => 'First', 'position' => 1]);
    $second = Question::factory()->for($quiz)->create(['prompt' => 'Second', 'position' => 2]);

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->call('moveQuestion', $second->id, 'up');

    expect($quiz->questions()->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('staff can upload perfect score artwork for a quiz', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $show = Show::factory()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $image = UploadedFile::fake()->image('perfect-score.png', 800, 800);

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->set('perfectScoreImage', $image)
        ->call('save')
        ->assertHasNoErrors();

    $imagePath = $quiz->fresh()->perfect_score_image_path;

    expect($imagePath)->toStartWith('perfect-score-images/');
    Storage::disk('public')->assertExists($imagePath);
});

test('staff can upload registration artwork for a quiz', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $show = Show::factory()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $image = UploadedFile::fake()->image('registration.png', 1200, 800);

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->set('registrationImage', $image)
        ->call('save')
        ->assertHasNoErrors();

    $imagePath = $quiz->fresh()->registration_image_path;

    expect($imagePath)->toStartWith('registration-images/');
    Storage::disk('public')->assertExists($imagePath);
});

test('registration artwork must be a supported image', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->summary()->create();
    $file = UploadedFile::fake()->create('details.txt', 20, 'text/plain');

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->set('registrationImage', $file)
        ->call('save')
        ->assertHasErrors(['registrationImage']);

    Storage::disk('public')->assertDirectoryEmpty('registration-images');
});

test('perfect score artwork must be a supported image', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->summary()->create();
    $file = UploadedFile::fake()->create('notes.txt', 20, 'text/plain');

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->set('perfectScoreImage', $file)
        ->call('save')
        ->assertHasErrors(['perfectScoreImage']);

    Storage::disk('public')->assertDirectoryEmpty('perfect-score-images');
});
