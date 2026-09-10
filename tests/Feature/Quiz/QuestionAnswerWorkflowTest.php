<?php

use App\Enums\AnswerMatchMethod;
use App\Enums\QuestionAnswerType;
use App\Enums\QuizScoringMode;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\Show;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
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
        ->assertSeeHtml('wire:target="submitAnswer"')
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
        ->automatic_match_method->toBe(AnswerMatchMethod::Exact)
        ->elapsed_ms->toBe(2500)
        ->and($answer->reviewed_at)->toBeNull();
});

test('a question image is sent to the contestant device', function () {
    Storage::fake('public');
    $staff = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    Question::factory()->for($quiz)->create([
        'prompt' => 'Identify this part',
        'image_path' => 'question-images/reference.png',
        'position' => 1,
    ]);
    Storage::disk('public')->put('question-images/reference.png', 'image contents');
    $participant = Participant::factory()->for($show)->create();

    Livewire::actingAs($staff)
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->call('sendQuestion');
    session()->put("quiz_participant_{$show->id}", $participant->id);

    Livewire::test('pages::register')
        ->assertSee('Identify this part')
        ->assertSee('question-images/reference.png');
});

test('each contestant keeps a random subset of the configured question pool', function () {
    $staff = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create([
        'scoring_mode' => QuizScoringMode::QuestionAnswer,
        'questions_per_entry' => 5,
    ]);
    $questions = Question::factory()->count(15)->for($quiz)->sequence(
        fn ($sequence) => ['position' => $sequence->index + 1],
    )->create();
    $participant = Participant::factory()->for($show)->create();

    $component = Livewire::actingAs($staff)
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->assertHasNoErrors();

    $assignedQuestionIds = $participant->quizEntry->fresh()->question_ids;

    expect($assignedQuestionIds)
        ->toHaveCount(5)
        ->each->toBeIn($questions->modelKeys())
        ->and(array_unique($assignedQuestionIds))->toHaveCount(5);

    $component->call('cancel')->call('start', $participant->id);

    expect($participant->quizEntry->fresh()->question_ids)->toBe($assignedQuestionIds);
});

test('approved phrases and conservative spelling are accepted automatically', function (string $submittedAnswer, AnswerMatchMethod $expectedMethod) {
    $staff = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    Question::factory()->for($quiz)->create([
        'correct_answer' => 'Sold as a four pack',
        'accepted_answers' => ['four pack'],
        'position' => 1,
    ]);
    $participant = Participant::factory()->for($show)->create();

    Livewire::actingAs($staff)
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->call('sendQuestion');
    session()->put("quiz_participant_{$show->id}", $participant->id);

    Livewire::test('pages::register')
        ->set('submittedAnswer', $submittedAnswer)
        ->call('submitAnswer')
        ->assertHasNoErrors();

    expect(QuizAnswer::query()->sole())
        ->is_correct->toBeTrue()
        ->automatic_match_method->toBe($expectedMethod);
})->with([
    'accepted phrase and number normalization' => ["It's a 4 pack", AnswerMatchMethod::AcceptedPhrase],
    'spelling tolerance' => ['four pac', AnswerMatchMethod::Spelling],
]);

test('a multiple choice question renders its options and accepts only a configured choice', function () {
    $staff = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    Question::factory()->for($quiz)->create([
        'prompt' => 'How is this product packaged?',
        'answer_type' => QuestionAnswerType::MultipleChoice,
        'correct_answer' => 'Four pack',
        'answer_options' => ['Single item', 'Two pack', 'Four pack'],
        'position' => 1,
    ]);
    $participant = Participant::factory()->for($show)->create();

    Livewire::actingAs($staff)
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->call('sendQuestion');
    session()->put("quiz_participant_{$show->id}", $participant->id);

    $component = Livewire::test('pages::register')
        ->assertSee('Single item')
        ->assertSee('Two pack')
        ->assertSee('Four pack')
        ->set('submittedAnswer', 'An option that was not sent')
        ->call('submitAnswer')
        ->assertHasErrors(['submittedAnswer']);

    expect(QuizAnswer::query()->exists())->toBeFalse();

    $component
        ->set('submittedAnswer', 'Four pack')
        ->call('submitAnswer')
        ->assertHasNoErrors();

    expect(QuizAnswer::query()->sole())
        ->submitted_answer->toBe('Four pack')
        ->is_correct->toBeTrue()
        ->automatic_match_method->toBe(AnswerMatchMethod::Exact);
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
