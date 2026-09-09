<?php

use App\Enums\QuizScoringMode;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\Show;
use App\Models\User;
use Livewire\Livewire;

test('question answer mode requires canonical answers for every question', function () {
    $user = User::factory()->create();
    $show = Show::factory()->create();
    $quiz = Quiz::factory()->for($show)->create();
    Question::factory()->for($quiz)->create(['correct_answer' => null, 'position' => 1]);

    Livewire::actingAs($user)
        ->test('pages::shows.edit', ['show' => $show])
        ->set('scoringMode', 'question_answer')
        ->call('save')
        ->assertHasErrors(['newCorrectAnswer']);

    expect($quiz->refresh()->scoring_mode)->toBe(QuizScoringMode::PerAnswer);
});

test('registration remembers a phone session and provides recovery by code', function () {
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);

    Livewire::test('pages::register')
        ->set('firstName', 'Ada')
        ->set('lastName', 'Lovelace')
        ->set('email', 'ada@example.test')
        ->call('register')
        ->assertHasNoErrors()
        ->assertSee('Waiting for the quiz to start');

    $participant = Participant::query()->sole();

    expect($participant->recovery_code)->toHaveLength(6)
        ->and(session("quiz_participant_{$show->id}"))->toBe($participant->id);

    session()->forget("quiz_participant_{$show->id}");

    Livewire::test('pages::register')
        ->set('recovering', true)
        ->set('recoveryCode', $participant->recovery_code)
        ->call('recover')
        ->assertHasNoErrors()
        ->assertSet('registered', true)
        ->assertSee('Waiting for the quiz to start');
});

test('a released question accepts a timed phone answer and automatically checks it', function () {
    $this->travelTo('2026-09-08 10:00:00');
    $staff = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    $question = Question::factory()->for($quiz)->create([
        'prompt' => 'What metal is shown?',
        'correct_answer' => 'Aluminum',
        'sales_notes' => 'Explain that aluminum is lightweight and corrosion resistant.',
        'position' => 1,
    ]);
    $participant = Participant::factory()->for($show)->create(['recovery_code' => '123456']);

    Livewire::actingAs($staff)
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->call('sendQuestion')
        ->assertHasNoErrors()
        ->assertSee('Sales notes')
        ->assertSee('Explain that aluminum is lightweight and corrosion resistant.');

    $this->travel(2500)->milliseconds();
    session()->put("quiz_participant_{$show->id}", $participant->id);

    Livewire::test('pages::register')
        ->assertSee('What metal is shown?')
        ->assertDontSee('Aluminum')
        ->assertDontSee('Explain that aluminum is lightweight and corrosion resistant.')
        ->set('submittedAnswer', ' aluminum ')
        ->call('submitAnswer')
        ->assertHasNoErrors()
        ->assertSee('Answer received');

    $answer = QuizAnswer::query()->sole();

    expect($answer)
        ->question_id->toBe($question->id)
        ->submitted_answer->toBe('aluminum')
        ->is_correct->toBeTrue()
        ->elapsed_ms->toBe(2500)
        ->and($answer->reviewed_at)->toBeNull();
});

test('staff can override answers and publish only after every answer is accepted', function () {
    $staff = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    $question = Question::factory()->for($quiz)->create(['position' => 1]);
    $participant = Participant::factory()->for($show)->create();
    $entry = $participant->quizEntry()->create([
        'quiz_id' => $quiz->id,
        'staff_user_id' => $staff->id,
        'started_at' => now(),
        'current_question_position' => 1,
        'question_released_at' => now(),
    ]);
    $answer = QuizAnswer::factory()->for($entry)->create([
        'question_id' => $question->id,
        'question_prompt' => $question->prompt,
        'submitted_answer' => 'Close enough',
        'position' => 1,
        'is_correct' => false,
        'reviewed_at' => null,
    ]);

    $component = Livewire::actingAs($staff)
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->call('complete')
        ->assertHasErrors(['entry']);

    $component
        ->call('reviewAnswer', true)
        ->call('complete')
        ->assertHasNoErrors();

    expect($answer->refresh())
        ->is_correct->toBeTrue()
        ->reviewed_at->not->toBeNull()
        ->and($entry->refresh())
        ->score->toBe(1)
        ->elapsed_ms->toBe($answer->elapsed_ms)
        ->completed_at->not->toBeNull();
});

test('staff can generate a new contestant recovery code', function () {
    $staff = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    $participant = Participant::factory()->for($show)->create(['recovery_code' => null]);

    Livewire::actingAs($staff)
        ->test('pages::dashboard')
        ->call('generateRecoveryCode', $participant->id)
        ->assertHasNoErrors();

    expect($participant->refresh()->recovery_code)->toHaveLength(6);
});
