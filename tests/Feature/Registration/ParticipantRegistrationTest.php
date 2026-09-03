<?php

use App\Models\Participant;
use App\Models\Quiz;
use App\Models\Show;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('registration is unavailable when there is no active show', function () {
    Show::factory()->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Registration is not currently available.');
});

test('registration is unavailable when active shows overlap', function () {
    Show::factory()->active()->count(2)->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Registration is not currently available.');
});

test('a participant can register for the active show', function () {
    $show = Show::factory()->active()->create(['name' => 'Manufacturing Expo']);

    Livewire::test('pages::register')
        ->assertSee('Manufacturing Expo')
        ->set('firstName', '  Ada ')
        ->set('lastName', ' Lovelace  ')
        ->set('email', 'ADA@EXAMPLE.COM ')
        ->set('marketingOptIn', false)
        ->call('register')
        ->assertHasNoErrors()
        ->assertSet('registered', true)
        ->assertSee('You’re in, Ada!');

    $this->assertDatabaseHas(Participant::class, [
        'show_id' => $show->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'marketing_opt_in' => false,
    ]);
});

test('a participant can register during a scheduled show', function () {
    $this->travelTo('2026-10-10 12:00:00');
    $show = Show::factory()->scheduled('2026-10-10 09:00:00', '2026-10-10 17:00:00')->create();

    Livewire::test('pages::register')
        ->set('firstName', 'Grace')
        ->set('lastName', 'Hopper')
        ->set('email', 'grace@example.com')
        ->call('register')
        ->assertHasNoErrors()
        ->assertSet('registered', true);

    $this->assertDatabaseHas(Participant::class, [
        'show_id' => $show->id,
        'email' => 'grace@example.com',
    ]);
});

test('participant details are required', function () {
    Show::factory()->active()->create();

    Livewire::test('pages::register')
        ->call('register')
        ->assertHasErrors(['firstName', 'lastName', 'email'])
        ->assertSee('The first name field is required.')
        ->assertSee('The last name field is required.')
        ->assertSee('The email field is required.');

    expect(Participant::query()->exists())->toBeFalse();
});

test('an email address can register only once per show', function () {
    $show = Show::factory()->active()->create();
    Participant::factory()->for($show)->create(['email' => 'ada@example.com']);

    Livewire::test('pages::register')
        ->set('firstName', 'Ada')
        ->set('lastName', 'Lovelace')
        ->set('email', 'ADA@EXAMPLE.COM')
        ->call('register')
        ->assertHasErrors(['email' => 'The email has already been taken.']);

    expect(Participant::query()->count())->toBe(1);
});

test('an email address may register at a different show', function () {
    $previousShow = Show::factory()->create();
    Participant::factory()->for($previousShow)->create(['email' => 'ada@example.com']);
    $activeShow = Show::factory()->active()->create();

    Livewire::test('pages::register')
        ->set('firstName', 'Ada')
        ->set('lastName', 'Lovelace')
        ->set('email', 'ada@example.com')
        ->call('register')
        ->assertHasNoErrors();

    $this->assertDatabaseHas(Participant::class, [
        'show_id' => $activeShow->id,
        'email' => 'ada@example.com',
    ]);
});

test('a show becoming inactive prevents a stale form submission', function () {
    $show = Show::factory()->active()->create();

    $component = Livewire::test('pages::register')
        ->set('firstName', 'Ada')
        ->set('lastName', 'Lovelace')
        ->set('email', 'ada@example.com');

    $show->update(['is_active' => false]);

    $component
        ->call('register')
        ->assertHasErrors(['show' => 'Registration is not currently available.'])
        ->assertSet('registered', false);

    expect(Participant::query()->exists())->toBeFalse();
});

test('participant names are escaped on the confirmation screen', function () {
    Show::factory()->active()->create();

    Livewire::test('pages::register')
        ->set('firstName', '<script>alert("quiz")</script>')
        ->set('lastName', 'Lovelace')
        ->set('email', 'ada@example.com')
        ->call('register')
        ->assertSee('<script>alert("quiz")</script>')
        ->assertDontSee('<script>alert("quiz")</script>', false);
});

test('quiz-specific information and artwork appear on registration', function () {
    Storage::fake('public');
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create([
        'registration_message' => 'Your email creates an account on example.com.',
        'registration_image_path' => 'registration-images/details.png',
    ]);

    Livewire::test('pages::register')
        ->assertSee('Your email creates an account on example.com.')
        ->assertSee(Storage::disk('public')->url('registration-images/details.png'), false);
});

test('quiz-specific registration information is escaped', function () {
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create([
        'registration_message' => '<script>alert("registration")</script>',
    ]);

    Livewire::test('pages::register')
        ->assertSee('<script>alert("registration")</script>')
        ->assertDontSee('<script>alert("registration")</script>', false);
});
