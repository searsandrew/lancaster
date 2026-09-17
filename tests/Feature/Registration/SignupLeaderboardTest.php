<?php

use App\Models\Participant;
use App\Models\Quiz;
use App\Models\QuizEntry;
use App\Models\Show;
use Livewire\Livewire;

test('signup shows only the top three completed results using leaderboard ranking', function () {
    $this->travelTo('2026-09-17 12:00:00');
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary(20)->create();
    foreach ([
        ['Ada', 18, 45000],
        ['Grace', 18, 30000],
        ['Katherine', 17, 10000],
        ['Fourth', 16, 1000],
    ] as [$name, $score, $time]) {
        $participant = Participant::factory()->for($show)->create(['first_name' => $name, 'last_name' => 'Contestant']);
        QuizEntry::factory()->for($quiz)->for($participant)->completed($score, $time)->create();
    }

    Livewire::test('pages::register')
        ->assertSee('Join the quiz')
        ->assertSee('Top 3')
        ->assertSeeInOrder(['Grace Contestant', 'Ada Contestant', 'Katherine Contestant'])
        ->assertDontSee('Fourth Contestant')
        ->assertSee('18 points')
        ->assertSee('30.000s')
        ->assertSee('Higher score wins. Fastest time breaks ties.')
        ->assertSeeHtml('wire:poll.5s');
});

test('signup excludes unfinished results and results from other shows', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    foreach ([
        ['Unfinished', null, 10, 1000],
        ['NoScore', now(), null, 1000],
        ['NoTime', now(), 10, null],
    ] as [$name, $completedAt, $score, $time]) {
        $participant = Participant::factory()->for($show)->create(['first_name' => $name]);
        QuizEntry::factory()->for($quiz)->for($participant)->create([
            'completed_at' => $completedAt, 'score' => $score, 'elapsed_ms' => $time,
        ]);
    }
    $pastShow = Show::factory()->create();
    $pastQuiz = Quiz::factory()->for($pastShow)->summary()->create();
    $participant = Participant::factory()->for($pastShow)->create(['first_name' => 'Historical']);
    QuizEntry::factory()->for($pastQuiz)->for($participant)->completed()->create();

    Livewire::test('pages::register')
        ->assertDontSee('Unfinished')
        ->assertDontSee('NoScore')
        ->assertDontSee('NoTime')
        ->assertDontSee('Historical')
        ->assertSee('Be the first on the leaderboard!');
});

test('signup leaderboard refreshes as results arrive without clearing form input', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $component = Livewire::test('pages::register')
        ->set('firstName', 'New visitor')
        ->set('email', 'visitor@example.test')
        ->assertSee('Be the first on the leaderboard!');
    $participant = Participant::factory()->for($show)->create(['first_name' => 'First', 'last_name' => 'Finisher']);
    QuizEntry::factory()->for($quiz)->for($participant)->completed(1, 75432)->create();

    $component->call('$refresh')
        ->assertSee('First Finisher')
        ->assertSee('1 point')
        ->assertSee('75.432s')
        ->assertDontSee('Be the first on the leaderboard!')
        ->assertSet('firstName', 'New visitor')
        ->assertSet('email', 'visitor@example.test');
});

test('signup leaderboard resolves tied results by completion time then entry order', function () {
    $this->travelTo('2026-09-17 12:00:00');
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    foreach ([['Later', now()], ['Earlier', now()->subMinute()], ['SameTime', now()->subMinute()]] as [$name, $completedAt]) {
        $participant = Participant::factory()->for($show)->create(['first_name' => $name, 'last_name' => 'Contestant']);
        QuizEntry::factory()->for($quiz)->for($participant)->completed(10, 1000)->create(['completed_at' => $completedAt]);
    }

    Livewire::test('pages::register')
        ->assertSeeInOrder(['Earlier Contestant', 'SameTime Contestant', 'Later Contestant']);
});

test('signup leaderboard escapes names and does not display contact details', function () {
    $show = Show::factory()->active()->create();
    $quiz = Quiz::factory()->for($show)->summary()->create();
    $participant = Participant::factory()->for($show)->create([
        'first_name' => '<script>alert(1)</script>',
        'email' => 'private@example.test',
        'phone_number' => '717-555-0123',
    ]);
    QuizEntry::factory()->for($quiz)->for($participant)->completed()->create();

    Livewire::test('pages::register')
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('private@example.test')
        ->assertDontSee('717-555-0123');
});

test('signup leaderboard is hidden when registration is unavailable', function (int $activeShows) {
    Show::factory()->active()->count($activeShows)->has(Quiz::factory())->create();

    Livewire::test('pages::register')
        ->assertSee('Registration is not currently available.')
        ->assertDontSee('Top 3');
})->with([0, 2]);

test('signup leaderboard does not remain on the registration confirmation', function () {
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->summary()->create();

    Livewire::test('pages::register')
        ->assertSee('Top 3')
        ->set('firstName', 'Ada')
        ->set('lastName', 'Lovelace')
        ->set('email', 'ada@example.test')
        ->call('register')
        ->assertHasNoErrors()
        ->assertSee('Leave this window open')
        ->assertDontSee('Top 3');
});
