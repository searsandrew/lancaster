<?php

use App\Models\Participant;
use App\Models\Quiz;
use App\Models\QuizEntry;
use App\Models\Show;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the participant directory', function () {
    $this->get(route('participants.index'))
        ->assertRedirect(route('login'));
});

test('the active quiz view only lists participants from the active show', function () {
    $user = User::factory()->create();
    $activeShow = Show::factory()->active()->create(['name' => 'Current Expo']);
    Quiz::factory()->for($activeShow)->create();
    $activeParticipant = Participant::factory()->for($activeShow)->create([
        'first_name' => 'Active',
        'last_name' => 'Person',
    ]);
    $pastShow = Show::factory()->create();
    $pastParticipant = Participant::factory()->for($pastShow)->create([
        'first_name' => 'Past',
        'last_name' => 'Person',
    ]);

    $this->actingAs($user)
        ->get(route('participants.index'))
        ->assertSeeText($activeParticipant->first_name.' '.$activeParticipant->last_name)
        ->assertDontSeeText($pastParticipant->first_name.' '.$pastParticipant->last_name);
});

test('the historical view groups returning participants by email and lists every show', function () {
    $user = User::factory()->create();
    $firstShow = Show::factory()->create(['name' => 'Expo 2025']);
    $secondShow = Show::factory()->create(['name' => 'Expo 2026']);
    $firstVisit = Participant::factory()->for($firstShow)->create([
        'first_name' => 'Casey',
        'last_name' => 'Visitor',
        'email' => 'casey@example.test',
    ]);
    $secondVisit = Participant::factory()->for($secondShow)->create([
        'first_name' => 'Casey',
        'last_name' => 'Visitor',
        'email' => 'casey@example.test',
    ]);
    $firstQuiz = Quiz::factory()->for($firstShow)->create();
    $secondQuiz = Quiz::factory()->for($secondShow)->create();
    QuizEntry::factory()->for($firstVisit)->for($firstQuiz)->completed(3)->create();
    QuizEntry::factory()->for($secondVisit)->for($secondQuiz)->create();

    Livewire::actingAs($user)
        ->test('pages::participants')
        ->set('view', 'all')
        ->assertSee('casey@example.test')
        ->assertSee('Expo 2025')
        ->assertSee('3 points')
        ->assertSee('Expo 2026')
        ->assertSee('In progress');
});

test('staff can search the historical participant directory', function () {
    $user = User::factory()->create();
    Participant::factory()->create([
        'first_name' => 'Findable',
        'last_name' => 'Guest',
        'email' => 'findable@example.test',
    ]);
    Participant::factory()->create([
        'first_name' => 'Hidden',
        'last_name' => 'Guest',
        'email' => 'hidden@example.test',
    ]);

    Livewire::actingAs($user)
        ->test('pages::participants')
        ->set('view', 'all')
        ->set('search', 'findable')
        ->assertSee('findable@example.test')
        ->assertDontSee('hidden@example.test');
});
