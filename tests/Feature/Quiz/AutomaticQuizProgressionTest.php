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

test('staff start releases the first question and participant answers automatically finish the quiz', function () {
    $this->travelTo('2026-09-18 12:00:00');
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create([
        'scoring_mode' => QuizScoringMode::QuestionAnswer,
        'second_chance_attempts' => 1,
        'show_answer_feedback' => true,
    ]);
    $questions = Question::factory()->for($quiz)->count(2)->sequence(
        ['position' => 1, 'prompt' => 'First question'],
        ['position' => 2, 'prompt' => 'Second question'],
    )->create([
        'answer_type' => QuestionAnswerType::MultipleChoice,
        'correct_answer' => 'Yes',
        'answer_options' => ['Yes', 'No'],
    ]);
    $participant = Participant::factory()->for($show)->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $staff = User::factory()->create();
    session()->put("quiz_participant_{$show->id}", $participant->id);
    Livewire::test('pages::register')->assertDontSee('First question')->assertSee('Leave this window open');

    $dashboard = Livewire::actingAs($staff)->test('pages::dashboard')
        ->call('start', $participant->id)
        ->assertHasNoErrors()
        ->assertSee('Question 1 sent.')
        ->assertDontSee('Send first question')
        ->assertDontSee('Complete and publish');
    $entry = $participant->quizEntry()->sole();
    expect($entry)->current_question_position->toBe(1)->completed_at->toBeNull();
    $phone = Livewire::test('pages::register')->assertSee('First question');
    $this->travel(2)->seconds();
    $phone->set('submittedAnswer', 'No')->call('submitAnswer', $questions[0]->id)
        ->assertSee('Incorrect answer.')
        ->assertSee('Extra attempts remaining: 1.')
        ->assertDontSee('Second question');
    $this->travel(3)->seconds();
    $phone->set('submittedAnswer', 'Yes')->call('submitAnswer', $questions[0]->id, 2)
        ->assertHasNoErrors()
        ->assertSee('Correct answer!')
        ->assertSee('Second question')
        ->assertDontSee('Waiting for staff');
    expect($entry->refresh())->current_question_position->toBe(2)->completed_at->toBeNull();
    $this->travel(1)->seconds();
    $phone->set('submittedAnswer', 'No')->call('submitAnswer', $questions[1]->id)
        ->assertSee('Extra attempts remaining: 1.');
    $this->travel(2)->seconds();
    $phone->set('submittedAnswer', 'No')->call('submitAnswer', $questions[1]->id, 2)
        ->assertHasNoErrors()
        ->assertSee('Incorrect answer.')
        ->assertSee('Quiz complete! Your result is on the leaderboard.');

    expect($entry->refresh())
        ->score->toBe(1)->elapsed_ms->toBe(8000)
        ->completed_at->not->toBeNull()
        ->current_question_position->toBeNull()
        ->question_released_at->toBeNull()
        ->staff_user_id->toBe($staff->id);
    expect($entry->answers()->whereNotNull('reviewed_at')->count())->toBe(2);
    $dashboard->call('refreshProgress')
        ->assertSee('Quiz complete! 1 point in 8.000 seconds.')
        ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => $parameters['slots']['text'] === 'Ada Lovelace finished the quiz with 1 point.');
    $dashboard->call('refreshProgress')->assertNotDispatched('toast-show');
    Livewire::test('pages::leaderboard')->assertSee('Ada Lovelace')->assertSee('8.000s');
});

test('automatic progression follows the assigned random subset and cannot submit a different question', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer, 'questions_per_entry' => 2]);
    $questions = Question::factory()->for($quiz)->count(5)->sequence(fn ($sequence) => ['position' => $sequence->index + 1])->create(['correct_answer' => 'Yes']);
    $participant = Participant::factory()->for($show)->create();
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')->call('start', $participant->id);
    $entry = $participant->quizEntry()->sole();
    $firstId = $entry->question_ids[0];
    $secondId = $entry->question_ids[1];
    $unassignedId = $questions->first(fn (Question $question): bool => ! in_array($question->id, $entry->question_ids, true))->id;
    session()->put("quiz_participant_{$show->id}", $participant->id);

    Livewire::test('pages::register')->set('submittedAnswer', 'Yes')->call('submitAnswer', $unassignedId)->assertStatus(409);
    expect($entry->answers()->count())->toBe(0);
    Livewire::test('pages::register')->set('submittedAnswer', 'Yes')->call('submitAnswer', $firstId)->assertHasNoErrors();
    expect($entry->refresh()->current_question_position)->toBe($questions->find($secondId)->position);
    Livewire::test('pages::register')->set('submittedAnswer', 'Yes')->call('submitAnswer', $secondId)->assertSee('Quiz complete!');
    expect($entry->refresh())->score->toBe(2)->completed_at->not->toBeNull();
    expect($entry->answers()->pluck('question_id')->all())->toEqualCanonicalizing([$firstId, $secondId]);
});

test('a stale submission cannot answer the next question', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    $questions = Question::factory()->for($quiz)->count(2)->sequence(['position' => 1], ['position' => 2])->create(['correct_answer' => 'Yes']);
    $participant = Participant::factory()->for($show)->create();
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')->call('start', $participant->id);
    session()->put("quiz_participant_{$show->id}", $participant->id);

    Livewire::test('pages::register')->set('submittedAnswer', 'Yes')->call('submitAnswer', $questions[0]->id)
        ->set('submittedAnswer', 'Yes')->call('submitAnswer', $questions[0]->id)->assertStatus(409);

    expect(QuizAnswer::query()->count())->toBe(1);
    expect($participant->quizEntry()->sole())->current_question_position->toBe(2)->completed_at->toBeNull();
});

test('opening an in progress quiz does not restart the question clock or change its staff member', function () {
    $this->travelTo('2026-09-18 12:00:00');
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    Question::factory()->for($quiz)->create(['position' => 1]);
    $participant = Participant::factory()->for($show)->create();
    $staff = User::factory()->create();
    Livewire::actingAs($staff)->test('pages::dashboard')->call('start', $participant->id);
    $this->travel(10)->seconds();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')->call('start', $participant->id)->assertHasNoErrors();

    $entry = $participant->quizEntry()->sole();
    expect($entry->question_released_at->format('H:i:s'))->toBe('12:00:00');
    expect($entry->staff_user_id)->toBe($staff->id);
});

test('completion notifications survive returning to the queue and reloading the dashboard', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    $question = Question::factory()->for($quiz)->create(['position' => 1, 'correct_answer' => 'Yes']);
    $participant = Participant::factory()->for($show)->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
    $staff = User::factory()->create();
    Livewire::actingAs($staff)->test('pages::dashboard')->call('start', $participant->id)->call('cancel');
    session()->put("quiz_participant_{$show->id}", $participant->id);
    Livewire::test('pages::register')->set('submittedAnswer', 'Yes')->call('submitAnswer', $question->id)->assertSee('Quiz complete!');

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')->call('refreshProgress')->assertNotDispatched('toast-show');
    Livewire::actingAs($staff)->test('pages::dashboard')->call('refreshProgress')
        ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => str_contains($parameters['slots']['text'], 'Grace Hopper'));
    Livewire::test('pages::dashboard')->call('refreshProgress')->assertNotDispatched('toast-show');
});

test('participants cannot begin answering before staff start their quiz', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    $question = Question::factory()->for($quiz)->create(['position' => 1]);
    $participant = Participant::factory()->for($show)->create();
    session()->put("quiz_participant_{$show->id}", $participant->id);

    Livewire::test('pages::register')->set('submittedAnswer', 'Yes')->call('submitAnswer', $question->id)->assertNotFound();

    expect(QuizEntry::query()->exists())->toBeFalse();
    expect(QuizAnswer::query()->exists())->toBeFalse();
});

test('staff cannot start a question answer quiz with no questions', function () {
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    $participant = Participant::factory()->for($show)->create();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
        ->call('start', $participant->id)->assertHasErrors(['entry' => 'This quiz does not have any questions configured.']);

    expect(QuizEntry::query()->exists())->toBeFalse();
});

test('participant polling resumes an already started quiz waiting for staff review', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer]);
    $question = Question::factory()->for($quiz)->create(['position' => 1]);
    $participant = Participant::factory()->for($show)->create();
    $entry = QuizEntry::factory()->for($quiz)->for($participant)->create([
        'current_question_position' => 1,
        'question_released_at' => now()->subSeconds(10),
    ]);
    QuizAnswer::factory()->for($entry)->for($question)->create([
        'position' => 1, 'is_correct' => true, 'reviewed_at' => null, 'elapsed_ms' => 5000,
    ]);
    session()->put("quiz_participant_{$show->id}", $participant->id);

    Livewire::test('pages::register')->call('refreshQuiz')->assertSee('Quiz complete!');

    expect($entry->refresh())->score->toBe(1)->elapsed_ms->toBe(5000)->completed_at->not->toBeNull();
});
