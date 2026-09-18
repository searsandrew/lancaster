<?php

use App\Enums\QuestionAnswerType;
use App\Enums\QuizScoringMode;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizEntry;
use App\Models\Show;
use App\Models\User;
use Livewire\Livewire;

/** @return array{Quiz, Question, QuizEntry} */
function createAnswerFeedbackQuiz(int $extraAttempts = 0, bool $feedback = false, QuestionAnswerType $answerType = QuestionAnswerType::MultipleChoice): array
{
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create([
        'scoring_mode' => QuizScoringMode::QuestionAnswer,
        'show_answer_feedback' => $feedback,
        'second_chance_attempts' => $extraAttempts,
    ]);
    $question = Question::factory()->for($quiz)->create([
        'position' => 1,
        'answer_type' => $answerType,
        'correct_answer' => 'Aluminum',
        'answer_options' => ['Aluminum', 'Steel', 'Copper'],
    ]);
    $participant = Participant::factory()->for($show)->create();
    $entry = QuizEntry::factory()->for($quiz)->for($participant)->create([
        'current_question_position' => 1,
        'question_released_at' => now(),
    ]);
    session()->put("quiz_participant_{$show->id}", $participant->id);

    return [$quiz, $question, $entry];
}

test('multiple choice answer feedback follows the quiz setting', function (bool $feedback, string $answer, string $message) {
    [, $question] = createAnswerFeedbackQuiz(feedback: $feedback);

    $component = Livewire::test('pages::register')
        ->set('submittedAnswer', $answer)
        ->call('submitAnswer', $question->id)
        ->assertHasNoErrors()
        ->assertSee('Quiz complete!');

    if ($feedback) {
        $component->assertSee($message);
    } else {
        $component->assertDontSee('Correct answer!')->assertDontSee('Incorrect answer.');
    }
})->with([
    'correct feedback' => [true, 'Aluminum', 'Correct answer!'],
    'incorrect feedback' => [true, 'Steel', 'Incorrect answer.'],
    'hidden correct feedback' => [false, 'Aluminum', ''],
    'hidden incorrect feedback' => [false, 'Steel', ''],
]);

test('free text questions do not show multiple choice feedback', function () {
    [, $question] = createAnswerFeedbackQuiz(feedback: true, answerType: QuestionAnswerType::FreeText);

    Livewire::test('pages::register')
        ->set('submittedAnswer', 'Steel')
        ->call('submitAnswer', $question->id)
        ->assertHasNoErrors()
        ->assertDontSee('Incorrect answer.')
        ->assertSee('Quiz complete!');
});

test('an incorrect answer can be retried and total time is included in the published score', function (QuestionAnswerType $answerType) {
    $this->travelTo('2026-09-17 12:00:00');
    [, $question, $entry] = createAnswerFeedbackQuiz(extraAttempts: 2, answerType: $answerType);
    $this->travel(2)->seconds();

    Livewire::test('pages::register')
        ->set('submittedAnswer', 'Steel')
        ->call('submitAnswer', $question->id)
        ->assertHasNoErrors()
        ->assertSee('Try again. Extra attempts remaining: 2.');

    $this->travel(3)->seconds();

    Livewire::test('pages::register')
        ->assertSee('Try again. Extra attempts remaining: 2.')
        ->set('submittedAnswer', 'Aluminum')
        ->call('submitAnswer', $question->id, 2)
        ->assertHasNoErrors()
        ->assertDontSee('Try again.')
        ->assertSee('Quiz complete!');

    expect(QuizAnswer::query()->sole())
        ->attempt_count->toBe(2)
        ->is_correct->toBeTrue()
        ->submitted_answer->toBe('Aluminum')
        ->elapsed_ms->toBe(5000);

    expect($entry->refresh())->score->toBe(1)->elapsed_ms->toBe(5000)->completed_at->not->toBeNull();
})->with([QuestionAnswerType::MultipleChoice, QuestionAnswerType::FreeText]);

test('extra attempts stop at the configured limit', function () {
    [, $question] = createAnswerFeedbackQuiz(extraAttempts: 2);

    $component = Livewire::test('pages::register')
        ->set('submittedAnswer', 'Steel')->call('submitAnswer', $question->id)
        ->set('submittedAnswer', 'Copper')->call('submitAnswer', $question->id, 2)
        ->assertSee('Extra attempts remaining: 1.')
        ->set('submittedAnswer', 'Steel')->call('submitAnswer', $question->id, 3)
        ->assertHasNoErrors()
        ->assertDontSee('Try again.')
        ->assertSee('Quiz complete!');

    $component->set('submittedAnswer', 'Aluminum')->call('submitAnswer', $question->id, 4)->assertNotFound();

    expect(QuizAnswer::query()->sole())->attempt_count->toBe(3)->is_correct->toBeFalse();
});

test('correct answers and disabled second chances reject additional submissions', function (int $extraAttempts, string $answer) {
    [, $question] = createAnswerFeedbackQuiz(extraAttempts: $extraAttempts);

    Livewire::test('pages::register')
        ->set('submittedAnswer', $answer)->call('submitAnswer', $question->id)
        ->assertDontSee('Try again.')
        ->set('submittedAnswer', 'Copper')->call('submitAnswer', $question->id, 2)
        ->assertNotFound();

    expect(QuizAnswer::query()->sole())->attempt_count->toBe(1)->submitted_answer->toBe($answer);
})->with([
    'correct answer' => [2, 'Aluminum'],
    'second chances disabled' => [0, 'Steel'],
]);

test('a stale submission cannot consume a second chance', function () {
    [, $question] = createAnswerFeedbackQuiz(extraAttempts: 2);

    Livewire::test('pages::register')
        ->set('submittedAnswer', 'Steel')->call('submitAnswer', $question->id)
        ->set('submittedAnswer', 'Copper')->call('submitAnswer', $question->id)
        ->assertStatus(409);

    expect(QuizAnswer::query()->sole())->attempt_count->toBe(1)->submitted_answer->toBe('Steel');
});

test('staff wait for second chances and can explicitly override the result', function () {
    [, $question, $entry] = createAnswerFeedbackQuiz(extraAttempts: 1);
    Livewire::test('pages::register')->set('submittedAnswer', 'Steel')->call('submitAnswer', $question->id);

    $component = Livewire::actingAs($entry->staffUser)
        ->test('pages::dashboard')
        ->call('start', $entry->participant_id)
        ->assertSee('Waiting for the contestant to try again.')
        ->call('reviewAnswer')
        ->assertHasErrors(['entry']);

    expect(QuizAnswer::query()->sole()->reviewed_at)->toBeNull();

    $component->call('reviewAnswer', true)->assertHasNoErrors();
    expect(QuizAnswer::query()->sole())->is_correct->toBeTrue()->reviewed_at->not->toBeNull();

    Livewire::test('pages::register')->set('submittedAnswer', 'Aluminum')->call('submitAnswer', $question->id, 2)->assertNotFound();
});

test('staff can save feedback and second chance settings', function () {
    [$quiz] = createAnswerFeedbackQuiz();
    $staff = User::factory()->create();

    Livewire::actingAs($staff)->test('pages::shows.edit', ['show' => $quiz->show])
        ->assertSee('Show answer results')
        ->assertDontSee('Extra attempts per question')
        ->set('showAnswerFeedback', true)
        ->set('secondChanceEnabled', true)
        ->assertSee('Extra attempts per question')
        ->set('secondChanceAttempts', 3)
        ->call('save')->assertHasNoErrors();

    expect($quiz->refresh())->show_answer_feedback->toBeTrue()->second_chance_attempts->toBe(3);

    Livewire::test('pages::shows.edit', ['show' => $quiz->show])
        ->assertSet('showAnswerFeedback', true)
        ->assertSet('secondChanceEnabled', true)
        ->assertSet('secondChanceAttempts', 3)
        ->set('secondChanceEnabled', false)
        ->set('secondChanceAttempts', null)
        ->call('save')->assertHasNoErrors();

    expect($quiz->refresh()->second_chance_attempts)->toBe(0);
});

test('feedback cannot be enabled for a quiz without multiple choice questions', function () {
    [$quiz] = createAnswerFeedbackQuiz(answerType: QuestionAnswerType::FreeText);

    Livewire::actingAs(User::factory()->create())->test('pages::shows.edit', ['show' => $quiz->show])
        ->assertDontSee('Show answer results')
        ->set('showAnswerFeedback', true)
        ->call('save')->assertHasNoErrors();

    expect($quiz->refresh()->show_answer_feedback)->toBeFalse();
});

test('manual scoring modes hide and disable participant answer settings', function (QuizScoringMode $mode) {
    [$quiz] = createAnswerFeedbackQuiz(extraAttempts: 2, feedback: true);

    Livewire::actingAs(User::factory()->create())->test('pages::shows.edit', ['show' => $quiz->show])
        ->set('scoringMode', $mode->value)
        ->set('maximumScore', 10)
        ->assertDontSee('Show answer results')
        ->assertDontSee('Second chance')
        ->call('save')->assertHasNoErrors();

    expect($quiz->refresh())->show_answer_feedback->toBeFalse()->second_chance_attempts->toBe(0);
})->with([QuizScoringMode::PerAnswer, QuizScoringMode::Summary]);

test('second chances require a bounded positive number of extra attempts', function (?int $attempts, string $message) {
    [$quiz] = createAnswerFeedbackQuiz();

    Livewire::actingAs(User::factory()->create())->test('pages::shows.edit', ['show' => $quiz->show])
        ->set('secondChanceEnabled', true)
        ->set('secondChanceAttempts', $attempts)
        ->call('save')
        ->assertHasErrors(['secondChanceAttempts'])
        ->assertSee($message);

    expect($quiz->refresh()->second_chance_attempts)->toBe(0);
})->with([
    'required' => [null, 'The second chance attempts field is required.'],
    'positive' => [0, 'The second chance attempts field must be at least 1.'],
    'bounded' => [101, 'The second chance attempts field must not be greater than 100.'],
]);

test('an incorrect answer automatically completes the quiz after extra attempts are exhausted', function () {
    [, $question, $entry] = createAnswerFeedbackQuiz(extraAttempts: 1);

    Livewire::test('pages::register')
        ->set('submittedAnswer', 'Steel')->call('submitAnswer', $question->id)
        ->set('submittedAnswer', 'Copper')->call('submitAnswer', $question->id, 2)
        ->assertHasNoErrors();

    Livewire::actingAs($entry->staffUser)->test('pages::dashboard')
        ->call('editResult', $entry->participant_id)
        ->assertDontSee('Waiting for the contestant to try again.')
        ->assertSee('Quiz complete!')
        ->assertHasNoErrors();

    expect($entry->refresh())->score->toBe(0)->completed_at->not->toBeNull();
    expect(QuizAnswer::query()->sole())->attempt_count->toBe(2)->reviewed_at->not->toBeNull();
});

test('an invalid retry choice does not use an extra attempt', function () {
    [, $question] = createAnswerFeedbackQuiz(extraAttempts: 1);

    Livewire::test('pages::register')
        ->set('submittedAnswer', 'Steel')->call('submitAnswer', $question->id)
        ->set('submittedAnswer', 'Not an option')->call('submitAnswer', $question->id, 2)
        ->assertHasErrors(['submittedAnswer'])
        ->assertSee('The selected submitted answer is invalid.')
        ->assertSee('Extra attempts remaining: 1.');

    expect(QuizAnswer::query()->sole())->attempt_count->toBe(1)->submitted_answer->toBe('Steel');
});
