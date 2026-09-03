<?php

use App\Models\Participant;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizEntry;
use App\Models\Show;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('guests are redirected from the quiz workflow', function () {
    $this->get(route('quiz.index'))->assertRedirect(route('login'));
});

test('staff can view and search participants from the active show', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create();
    Participant::factory()->for($show)->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
    ]);
    Participant::factory()->for($show)->create([
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
    ]);
    $inactiveShow = Show::factory()->create();
    Participant::factory()->for($inactiveShow)->create(['first_name' => 'Hidden']);

    Livewire::actingAs($user)
        ->test('pages::quiz')
        ->assertSee('Ada Lovelace')
        ->assertSee('Grace Hopper')
        ->assertDontSee('Hidden')
        ->set('search', 'ada@')
        ->assertSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper');
});

test('starting a quiz creates only one entry and records the staff member', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();
    $participant = Participant::factory()->for($show)->create();

    $component = Livewire::actingAs($user)
        ->test('pages::quiz')
        ->call('start', $participant->id)
        ->assertHasNoErrors();

    $entry = QuizEntry::query()->sole();

    expect($entry)
        ->participant_id->toBe($participant->id)
        ->quiz_id->toBe($quiz->id)
        ->staff_user_id->toBe($user->id)
        ->completed_at->toBeNull();

    $component->call('cancel')->call('start', $participant->id);

    expect(QuizEntry::query()->count())->toBe(1);
});

test('staff cannot start a participant from another show', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create();
    $otherParticipant = Participant::factory()->create();

    expect(fn () => Livewire::actingAs($user)
        ->test('pages::quiz')
        ->call('start', $otherParticipant->id))
        ->toThrow(ModelNotFoundException::class);

    expect(QuizEntry::query()->exists())->toBeFalse();
});

test('staff can complete a summary-scored quiz', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->summary(20)->create();
    $participant = Participant::factory()->for($show)->create();

    Livewire::actingAs($user)
        ->test('pages::quiz')
        ->call('start', $participant->id)
        ->set('summaryScore', 17)
        ->set('summarySeconds', '42.375')
        ->call('complete')
        ->assertHasNoErrors()
        ->assertSet('entryId', null);

    $entry = QuizEntry::query()->sole();

    expect($entry)
        ->score->toBe(17)
        ->elapsed_ms->toBe(42375)
        ->and($entry->completed_at?->timestamp)->toBe(now()->timestamp);
});

test('summary score cannot exceed the configured maximum', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->summary(10)->create();
    $participant = Participant::factory()->for($show)->create();

    Livewire::actingAs($user)
        ->test('pages::quiz')
        ->call('start', $participant->id)
        ->set('summaryScore', 11)
        ->set('summarySeconds', '10')
        ->call('complete')
        ->assertHasErrors(['summaryScore']);

    expect(QuizEntry::query()->sole()->completed_at)->toBeNull();
});

test('staff can complete a per-answer quiz with timing', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();
    $first = Question::factory()->for($quiz)->create(['prompt' => 'First question', 'position' => 1]);
    $second = Question::factory()->for($quiz)->create(['prompt' => 'Second question', 'position' => 2]);
    $participant = Participant::factory()->for($show)->create();

    Livewire::actingAs($user)
        ->test('pages::quiz')
        ->call('start', $participant->id)
        ->set("answerCorrect.{$first->id}", true)
        ->set("answerSeconds.{$first->id}", '3.125')
        ->set("answerCorrect.{$second->id}", false)
        ->set("answerSeconds.{$second->id}", '4.5')
        ->call('complete')
        ->assertHasNoErrors();

    $entry = QuizEntry::query()->sole();

    expect($entry)
        ->score->toBe(1)
        ->elapsed_ms->toBe(7625)
        ->completed_at->not->toBeNull();

    expect(QuizAnswer::query()->orderBy('position')->get())
        ->sequence(
            fn ($answer) => $answer
                ->question_id->toBe($first->id)
                ->question_prompt->toBe('First question')
                ->is_correct->toBeTrue()
                ->elapsed_ms->toBe(3125),
            fn ($answer) => $answer
                ->question_id->toBe($second->id)
                ->question_prompt->toBe('Second question')
                ->is_correct->toBeFalse()
                ->elapsed_ms->toBe(4500),
        );
});

test('every per-answer question requires a time', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();
    $question = Question::factory()->for($quiz)->create(['position' => 1]);
    $participant = Participant::factory()->for($show)->create();

    Livewire::actingAs($user)
        ->test('pages::quiz')
        ->call('start', $participant->id)
        ->set("answerCorrect.{$question->id}", true)
        ->call('complete')
        ->assertHasErrors(["answerSeconds.{$question->id}"]);

    expect(QuizEntry::query()->sole()->completed_at)->toBeNull();
    expect(QuizAnswer::query()->exists())->toBeFalse();
});

test('a completed quiz entry cannot be reopened', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $participant = Participant::factory()->for($show)->create();
    QuizEntry::factory()
        ->for($participant)
        ->for($quiz)
        ->for($user, 'staffUser')
        ->completed()
        ->create();

    Livewire::actingAs($user)
        ->test('pages::quiz')
        ->call('start', $participant->id)
        ->assertHasErrors(['entry' => 'This quiz entry has already been completed.'])
        ->assertSet('entryId', null);

    expect(QuizEntry::query()->count())->toBe(1);
});
