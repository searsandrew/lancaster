<?php

use App\Models\Participant;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizEntry;
use App\Models\Show;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('the leaderboard is publicly accessible without an active show', function () {
    $this->get(route('leaderboard'))
        ->assertOk()
        ->assertSee('Leaderboard coming soon');
});

test('completed entries are ranked by score then fastest time', function () {
    $show = Show::factory()->active()->create(['name' => 'Manufacturing Expo']);
    $quiz = Quiz::factory()->for($show)->summary(20)->create();
    $staff = User::factory()->create();
    $slowerWinner = Participant::factory()->for($show)->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $fasterWinner = Participant::factory()->for($show)->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
    $lowerScore = Participant::factory()->for($show)->create(['first_name' => 'Katherine', 'last_name' => 'Johnson']);
    QuizEntry::factory()->for($slowerWinner)->for($quiz)->for($staff, 'staffUser')->completed(18, 45000)->create();
    QuizEntry::factory()->for($fasterWinner)->for($quiz)->for($staff, 'staffUser')->completed(18, 30000)->create();
    QuizEntry::factory()->for($lowerScore)->for($quiz)->for($staff, 'staffUser')->completed(17, 10000)->create();

    Livewire::test('pages::leaderboard')
        ->assertSee('Manufacturing Expo')
        ->assertSeeInOrder(['Grace Hopper', 'Ada Lovelace', 'Katherine Johnson'])
        ->assertSee('18')
        ->assertSee('/20')
        ->assertSee('30.000s');
});

test('incomplete and inactive-show entries are excluded', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $staff = User::factory()->create();
    $completed = Participant::factory()->for($show)->create(['first_name' => 'Visible']);
    $incomplete = Participant::factory()->for($show)->create(['first_name' => 'Waiting']);
    QuizEntry::factory()->for($completed)->for($quiz)->for($staff, 'staffUser')->completed()->create();
    QuizEntry::factory()->for($incomplete)->for($quiz)->for($staff, 'staffUser')->create();

    $pastShow = Show::factory()->create();
    $pastQuiz = Quiz::factory()->for($pastShow)->summary()->create();
    $pastParticipant = Participant::factory()->for($pastShow)->create(['first_name' => 'Historical']);
    QuizEntry::factory()->for($pastParticipant)->for($pastQuiz)->for($staff, 'staffUser')->completed()->create();

    Livewire::test('pages::leaderboard')
        ->assertSee('Visible')
        ->assertDontSee('Waiting')
        ->assertDontSee('Historical');
});

test('per-answer leaderboards use the question count as the maximum score', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->create();
    Question::factory()->for($quiz)->count(3)->sequence(
        ['position' => 1],
        ['position' => 2],
        ['position' => 3],
    )->create();
    $staff = User::factory()->create();
    $participant = Participant::factory()->for($show)->create();
    QuizEntry::factory()->for($participant)->for($quiz)->for($staff, 'staffUser')->completed(2)->create();

    Livewire::test('pages::leaderboard')
        ->assertSee('2')
        ->assertSee('/3');
});

test('the leaderboard switches when the active show changes', function () {
    $firstShow = Show::factory()->active()->create(['name' => 'First Expo']);
    Quiz::factory()->for($firstShow)->create();
    $secondShow = Show::factory()->create(['name' => 'Second Expo']);
    Quiz::factory()->for($secondShow)->create();

    $component = Livewire::test('pages::leaderboard')->assertSee('First Expo');

    $firstShow->update(['is_active' => false]);
    $secondShow->update(['is_active' => true]);

    $component
        ->call('refreshShow')
        ->assertSee('Second Expo')
        ->assertDontSee('First Expo');
});

test('the leaderboard formats times longer than one minute', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $staff = User::factory()->create();
    $participant = Participant::factory()->for($show)->create();
    QuizEntry::factory()->for($participant)->for($quiz)->for($staff, 'staffUser')->completed(1, 75432)->create();

    Livewire::test('pages::leaderboard')->assertSee('1:15.432');
});

test('participant names are escaped on the leaderboard', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $staff = User::factory()->create();
    $participant = Participant::factory()->for($show)->create([
        'first_name' => '<script>alert("leaderboard")</script>',
        'last_name' => 'Example',
    ]);
    QuizEntry::factory()->for($participant)->for($quiz)->for($staff, 'staffUser')->completed()->create();

    Livewire::test('pages::leaderboard')
        ->assertSee('<script>alert("leaderboard")</script>')
        ->assertDontSee('<script>alert("leaderboard")</script>', false);
});

test('the initial leaderboard load does not celebrate existing results', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary(10)->create();
    $staff = User::factory()->create();
    $participant = Participant::factory()->for($show)->create();
    QuizEntry::factory()->for($participant)->for($quiz)->for($staff, 'staffUser')->completed(10)->create();

    Livewire::test('pages::leaderboard')
        ->assertNotDispatched('leaderboard-confetti')
        ->assertNotDispatched('perfect-score');
});

test('a new number one triggers a confetti burst', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary(10)->create();
    $staff = User::factory()->create();
    $leader = Participant::factory()->for($show)->create();
    QuizEntry::factory()->for($leader)->for($quiz)->for($staff, 'staffUser')->completed(7, 20000)->create();
    $component = Livewire::test('pages::leaderboard');

    $newLeader = Participant::factory()->for($show)->create();
    QuizEntry::factory()->for($newLeader)->for($quiz)->for($staff, 'staffUser')->completed(8, 30000)->create();

    $component
        ->call('refreshShow')
        ->assertDispatched('leaderboard-confetti');
});

test('a result below first place does not trigger confetti', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary(10)->create();
    $staff = User::factory()->create();
    $leader = Participant::factory()->for($show)->create();
    QuizEntry::factory()->for($leader)->for($quiz)->for($staff, 'staffUser')->completed(9, 20000)->create();
    $component = Livewire::test('pages::leaderboard');

    $challenger = Participant::factory()->for($show)->create();
    QuizEntry::factory()->for($challenger)->for($quiz)->for($staff, 'staffUser')->completed(8, 10000)->create();

    $component
        ->call('refreshShow')
        ->assertNotDispatched('leaderboard-confetti');
});

test('a new perfect score triggers the celebration with uploaded artwork', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary(10)->create([
        'perfect_score_image_path' => 'perfect-score-images/sticker.png',
    ]);
    $staff = User::factory()->create();
    $component = Livewire::test('pages::leaderboard');

    $participant = Participant::factory()->for($show)->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);
    QuizEntry::factory()->for($participant)->for($quiz)->for($staff, 'staffUser')->completed(10, 25000)->create();

    $component
        ->call('refreshShow')
        ->assertDispatched(
            'perfect-score',
            name: 'Ada Lovelace',
            imageUrl: Storage::disk('public')->url('perfect-score-images/sticker.png'),
        );
});
