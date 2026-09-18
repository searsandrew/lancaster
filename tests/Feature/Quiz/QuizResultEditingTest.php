<?php

use App\Enums\AnswerMatchMethod;
use App\Enums\QuizScoringMode;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizEntry;
use App\Models\Show;
use App\Models\User;
use Livewire\Livewire;

/** @return array{Participant, QuizEntry, QuizAnswer} */
function createEditableQuizResult(): array
{
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create(['scoring_mode' => QuizScoringMode::QuestionAnswer, 'questions_per_entry' => 1]);
    $question = Question::factory()->for($quiz)->create(['position' => 2]);
    Question::factory()->for($quiz)->create(['position' => 1, 'prompt' => 'Unassigned question']);
    $participant = Participant::factory()->for($show)->create();
    $entry = QuizEntry::factory()->for($quiz)->for($participant)->completed(0, 5000)->create(['question_ids' => [$question->id]]);
    $answer = QuizAnswer::factory()->for($entry)->for($question)->create([
        'question_prompt' => 'Identify the product',
        'submitted_answer' => 'My product answer',
        'position' => 2,
        'is_correct' => false,
        'elapsed_ms' => 5000,
        'attempt_count' => 2,
        'automatic_match_method' => AnswerMatchMethod::None,
        'submitted_at' => now(),
        'reviewed_at' => now(),
    ]);

    return [$participant, $entry, $answer];
}

test('staff can review and correct completed question answer results', function () {
    $this->travelTo('2026-09-18 12:00:00');
    [$participant, $entry, $answer] = createEditableQuizResult();
    $completedAt = $entry->completed_at;
    $submittedAt = $answer->submitted_at;
    $this->travel(10)->minutes();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
        ->call('editResult', $participant->id)
        ->assertSee('Identify the product')
        ->assertSee('My product answer')
        ->assertSee('2 attempts')
        ->assertDontSee('Unassigned question')
        ->assertSet("resultAnswerCorrect.{$answer->id}", false)
        ->assertSet("resultAnswerSeconds.{$answer->id}", '5')
        ->set("resultAnswerCorrect.{$answer->id}", true)
        ->set("resultAnswerSeconds.{$answer->id}", '2.125')
        ->call('refreshProgress')
        ->assertSet("resultAnswerSeconds.{$answer->id}", '2.125')
        ->call('complete')->assertHasNoErrors()
        ->assertSet('resultParticipantId', null);

    expect($entry->refresh())->score->toBe(1)->elapsed_ms->toBe(2125);
    expect($entry->completed_at->equalTo($completedAt))->toBeTrue();
    expect($answer->refresh())->is_correct->toBeTrue()->elapsed_ms->toBe(2125)
        ->submitted_answer->toBe('My product answer')->attempt_count->toBe(2)
        ->automatic_match_method->toBe(AnswerMatchMethod::None);
    expect($answer->submitted_at->equalTo($submittedAt))->toBeTrue();
    expect($entry->answers()->count())->toBe(1);
    Livewire::test('pages::leaderboard')->assertSee('2.125s');
});

test('invalid result times leave answers and totals unchanged', function (string $time) {
    [$participant, $entry, $answer] = createEditableQuizResult();

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
        ->call('editResult', $participant->id)
        ->set("resultAnswerCorrect.{$answer->id}", true)
        ->set("resultAnswerSeconds.{$answer->id}", $time)
        ->call('complete')->assertHasErrors(["resultAnswerSeconds.{$answer->id}"])
        ->assertSee('Your changes have not been saved.');

    expect($answer->refresh())->is_correct->toBeFalse()->elapsed_ms->toBe(5000);
    expect($entry->refresh())->score->toBe(0)->elapsed_ms->toBe(5000);
})->with(['missing' => '', 'not numeric' => 'abc', 'zero' => '0', 'negative' => '-1', 'over maximum' => '86401']);

test('the result link opens the correct completed quiz and cancel clears it', function () {
    [$participant, , $answer] = createEditableQuizResult();
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('dashboard', ['result' => $participant->id]))
        ->assertOk()->assertSee('My product answer')->assertSee('Mark as correct');
    Livewire::withQueryParams(['result' => $participant->id])->actingAs($staff)->test('pages::dashboard')
        ->assertSet('editingCompletedEntry', true)
        ->assertSet("resultAnswerSeconds.{$answer->id}", '5')
        ->call('cancel')->assertSet('resultParticipantId', null)->assertSet('entryId', null);
});

test('result links require staff authentication', function () {
    [$participant] = createEditableQuizResult();

    $this->get(route('dashboard', ['result' => $participant->id]))->assertRedirect(route('login'));
});

test('result links cannot open an unfinished quiz or another show', function (string $state) {
    [$participant, $entry] = createEditableQuizResult();
    if ($state === 'unfinished') {
        $entry->update(['completed_at' => null]);
    } else {
        $participant->show->update(['is_active' => false]);
        Show::factory()->active()->has(Quiz::factory())->create();
    }

    $this->actingAs(User::factory()->create())->get(route('dashboard', ['result' => $participant->id]))->assertNotFound();
})->with(['unfinished', 'other show']);

test('completion toasts link to the completed contestant result', function () {
    [$participant, $entry] = createEditableQuizResult();
    session()->put('quiz_monitored_entries.'.$entry->staff_user_id, [$entry->id]);

    Livewire::actingAs($entry->staffUser)->test('pages::dashboard')->call('refreshProgress')
        ->assertDispatched('toast-show', fn (string $event, array $parameters): bool => $parameters['link'] === [
            'href' => route('dashboard', ['result' => $participant->id]),
            'text' => 'View results',
        ]);
});

test('saved result edits ignore answer ids outside the selected entry', function () {
    [$participant, $entry, $answer] = createEditableQuizResult();
    $otherAnswer = QuizAnswer::factory()->create(['is_correct' => false, 'elapsed_ms' => 9000]);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
        ->call('editResult', $participant->id)
        ->set("resultAnswerCorrect.{$answer->id}", true)
        ->set("resultAnswerCorrect.{$otherAnswer->id}", true)
        ->set("resultAnswerSeconds.{$otherAnswer->id}", '0.001')
        ->call('complete')->assertHasNoErrors();

    expect($entry->refresh())->score->toBe(1)->elapsed_ms->toBe(5000);
    expect($otherAnswer->refresh())->is_correct->toBeFalse()->elapsed_ms->toBe(9000);
});

test('result details remain editable after a question is deleted and escape submitted content', function () {
    [$participant, $entry, $answer] = createEditableQuizResult();
    $answer->question->delete();
    $answer->update(['submitted_answer' => '<script>alert(1)</script>']);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
        ->call('editResult', $participant->id)
        ->assertSee('Identify the product')
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->set("resultAnswerCorrect.{$answer->id}", true)
        ->call('complete')->assertHasNoErrors();

    expect($entry->refresh()->score)->toBe(1);
});

test('staff can mark a previously correct answer incorrect', function () {
    [$participant, $entry, $answer] = createEditableQuizResult();
    $answer->update(['is_correct' => true]);
    $entry->update(['score' => 1]);

    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
        ->call('editResult', $participant->id)
        ->set("resultAnswerCorrect.{$answer->id}", false)
        ->call('complete')->assertHasNoErrors();

    expect($answer->refresh()->is_correct)->toBeFalse();
    expect($entry->refresh())->score->toBe(0)->elapsed_ms->toBe(5000);
});
