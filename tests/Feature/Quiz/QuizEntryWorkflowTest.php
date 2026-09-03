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
    $this->get('/quiz')->assertRedirect(route('login'));
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
        ->test('pages::dashboard')
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
        ->test('pages::dashboard')
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
        ->test('pages::dashboard')
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
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->assertSee('Current contestant')
        ->assertSee('Quiz in progress')
        ->assertSee('Enter seconds. Decimals are supported, for example 42.375.')
        ->assertSee('Complete and publish')
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
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->set('summaryScore', 11)
        ->set('summarySeconds', '10')
        ->call('complete')
        ->assertHasErrors(['summaryScore'])
        ->assertSee('Nothing has been completed yet. Correct the fields below and try again.');

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
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->assertSee('Question 1 of 2')
        ->assertSee('Question 2 of 2')
        ->assertSee('Enter each time in seconds.')
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
        ->test('pages::dashboard')
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
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->assertHasErrors(['entry' => 'This quiz entry has already been completed.'])
        ->assertSet('entryId', null);

    expect(QuizEntry::query()->count())->toBe(1);
});

test('staff can edit contestant details', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create();
    $participant = Participant::factory()->for($show)->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'marketing_opt_in' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('editParticipant', $participant->id)
        ->set('participantFirstName', '  Grace ')
        ->set('participantLastName', ' Hopper  ')
        ->set('participantEmail', 'GRACE@EXAMPLE.COM ')
        ->set('participantMarketingOptIn', true)
        ->call('saveParticipant')
        ->assertHasNoErrors();

    expect($participant->refresh())
        ->first_name->toBe('Grace')
        ->last_name->toBe('Hopper')
        ->email->toBe('grace@example.com')
        ->marketing_opt_in->toBeTrue();
});

test('staff can see email signup consent in the participant queue', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create();
    Participant::factory()->for($show)->create([
        'first_name' => 'Ada',
        'marketing_opt_in' => true,
    ]);
    Participant::factory()->for($show)->create([
        'first_name' => 'Grace',
        'marketing_opt_in' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->assertSee('Email signup')
        ->assertSee('Accepted')
        ->assertSee('Declined');
});

test('staff can update declined email consent while running a quiz', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->summary()->create();
    $participant = Participant::factory()->for($show)->create([
        'marketing_opt_in' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('start', $participant->id)
        ->assertSee('Email signup declined')
        ->call('editParticipant', $participant->id)
        ->set('participantMarketingOptIn', true)
        ->call('saveParticipant')
        ->assertHasNoErrors()
        ->assertSee('Email signup accepted');

    expect($participant->refresh()->marketing_opt_in)->toBeTrue();
});

test('contestant email must remain unique within the show', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create();
    Participant::factory()->for($show)->create(['email' => 'existing@example.com']);
    $participant = Participant::factory()->for($show)->create(['email' => 'original@example.com']);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('editParticipant', $participant->id)
        ->set('participantEmail', 'existing@example.com')
        ->call('saveParticipant')
        ->assertHasErrors(['participantEmail' => 'The participant email has already been taken.']);

    expect($participant->refresh()->email)->toBe('original@example.com');
});

test('staff can edit a completed result without changing its completion time', function () {
    $this->travelTo('2026-09-03 10:00:00');
    $originalStaff = User::factory()->create();
    $editingStaff = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary(20)->create();
    $participant = Participant::factory()->for($show)->create();
    $entry = QuizEntry::factory()
        ->for($participant)
        ->for($quiz)
        ->for($originalStaff, 'staffUser')
        ->completed(12, 30000)
        ->create();
    $originalCompletedAt = $entry->completed_at;
    $this->travel(10)->minutes();

    Livewire::actingAs($editingStaff)
        ->test('pages::dashboard')
        ->call('editResult', $participant->id)
        ->assertSet('editingCompletedEntry', true)
        ->set('summaryScore', 18)
        ->set('summarySeconds', '25.125')
        ->call('complete')
        ->assertHasNoErrors();

    expect($entry->refresh())
        ->score->toBe(18)
        ->elapsed_ms->toBe(25125)
        ->staff_user_id->toBe($editingStaff->id)
        ->and($entry->completed_at?->timestamp)->toBe($originalCompletedAt?->timestamp);
});

test('deleting a result keeps the contestant available for another attempt', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $participant = Participant::factory()->for($show)->create();
    $entry = QuizEntry::factory()
        ->for($participant)
        ->for($quiz)
        ->for($user, 'staffUser')
        ->completed()
        ->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('deleteEntry', $participant->id)
        ->assertHasNoErrors();

    $this->assertModelExists($participant);
    $this->assertModelMissing($entry);
});

test('deleting a contestant also deletes their quiz data', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();
    $question = Question::factory()->for($quiz)->create(['position' => 1]);
    $participant = Participant::factory()->for($show)->create();
    $entry = QuizEntry::factory()
        ->for($participant)
        ->for($quiz)
        ->for($user, 'staffUser')
        ->completed()
        ->create();
    $answer = QuizAnswer::factory()->for($entry)->create([
        'question_id' => $question->id,
        'position' => 1,
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('deleteParticipant', $participant->id)
        ->assertHasNoErrors();

    $this->assertModelMissing($participant);
    $this->assertModelMissing($entry);
    $this->assertModelMissing($answer);
});

test('staff cannot manage contestants from another show', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create();
    $otherParticipant = Participant::factory()->create();

    expect(fn () => Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('editParticipant', $otherParticipant->id))
        ->toThrow(ModelNotFoundException::class);

    $this->assertModelExists($otherParticipant);
});
