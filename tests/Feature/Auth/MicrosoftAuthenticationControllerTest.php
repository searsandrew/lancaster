<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('guests are redirected to Microsoft for authentication', function () {
    Socialite::fake('microsoft');

    $response = $this->get(route('microsoft.redirect'));

    $response->assertRedirect('https://socialite.fake/microsoft/authorize');
});

test('Microsoft creates and authenticates a new user with a personal team', function () {
    Socialite::fake('microsoft', SocialiteUser::fake([
        'id' => 'microsoft-user-123',
        'name' => 'Alex Morgan',
        'email' => 'Alex.Morgan@example.com',
    ]));

    $response = $this->get(route('microsoft.callback'));

    $user = User::query()->where('microsoft_id', 'microsoft-user-123')->firstOrFail();

    $response->assertRedirect('/alex-morgans-team/dashboard');
    $this->assertAuthenticatedAs($user);
    expect($user)
        ->email->toBe('alex.morgan@example.com')
        ->email_verified_at->not->toBeNull()
        ->and($user->personalTeam())->not->toBeNull()
        ->and($user->current_team_id)->toBe($user->personalTeam()?->id);
});

test('Microsoft links and authenticates an existing user by email', function () {
    $user = User::factory()->create([
        'name' => 'Previous Name',
        'email' => 'alex.morgan@example.com',
        'microsoft_id' => null,
    ]);
    Socialite::fake('microsoft', SocialiteUser::fake([
        'id' => 'microsoft-user-123',
        'name' => 'Alex Morgan',
        'email' => 'alex.morgan@example.com',
    ]));

    $response = $this->get(route('microsoft.callback'));

    $response->assertRedirect('/'.$user->currentTeam->slug.'/dashboard');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh())
        ->microsoft_id->toBe('microsoft-user-123')
        ->name->toBe('Alex Morgan')
        ->and(User::query()->count())->toBe(1);
});

test('Microsoft authenticates an already linked user by provider id', function () {
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'microsoft_id' => 'microsoft-user-123',
    ]);
    Socialite::fake('microsoft', SocialiteUser::fake([
        'id' => 'microsoft-user-123',
        'name' => 'Alex Morgan',
        'email' => 'new@example.com',
    ]));

    $response = $this->get(route('microsoft.callback'));

    $response->assertRedirect('/'.$user->currentTeam->slug.'/dashboard');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->email)->toBe('old@example.com');
});

test('Microsoft rejects an email already linked to another provider account', function () {
    $user = User::factory()->create([
        'email' => 'alex.morgan@example.com',
        'microsoft_id' => 'microsoft-user-456',
    ]);
    Socialite::fake('microsoft', SocialiteUser::fake([
        'id' => 'microsoft-user-123',
        'email' => 'alex.morgan@example.com',
    ]));

    $response = $this->get(route('microsoft.callback'));

    $response->assertSessionHasErrors([
        'email' => 'This email address is already linked to another Microsoft account.',
    ]);
    $this->assertGuest();
    expect($user->fresh()->microsoft_id)
        ->toBe('microsoft-user-456')
        ->and(User::query()->count())->toBe(1);
});

test('Microsoft rejects a response without an email address', function () {
    Socialite::fake('microsoft', SocialiteUser::fake([
        'id' => 'microsoft-user-123',
        'email' => null,
    ]));

    $response = $this->get(route('microsoft.callback'));

    $response->assertSessionHasErrors([
        'email' => 'Microsoft did not provide the account information required to log in.',
    ]);
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('Microsoft redirects cancelled authentication back to login', function () {
    Socialite::fake('microsoft', fn () => throw new RuntimeException('The provider should not be called.'));

    $response = $this->get(route('microsoft.callback', ['error' => 'access_denied']));

    $response
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => 'Microsoft login was cancelled or could not be completed.',
        ]);
    $this->assertGuest();
});
