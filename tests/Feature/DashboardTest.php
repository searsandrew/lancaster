<?php

use App\Models\Quiz;
use App\Models\Show;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $show = Show::factory()->active()->create();
    Quiz::factory()->for($show)->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response
        ->assertOk()
        ->assertSee('Staff dashboard')
        ->assertSee($show->name);
});

test('the previous quiz URL redirects authenticated staff to the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/quiz')
        ->assertRedirect('/dashboard');
});
