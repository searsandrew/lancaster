<?php

use App\Enums\EmailMarketingProvider;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from customer management to login', function () {
    $this->get(route('customers.index'))->assertRedirect(route('login'));
});

test('staff can create a customer with a Mailchimp connection', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::customers.index')
        ->set('name', 'Lancaster Tools')
        ->set('provider', 'mailchimp')
        ->set('apiKey', 'secret-mailchimp-key')
        ->set('listId', 'audience-123')
        ->set('mailchimpServerPrefix', 'US21')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    $customer = Customer::query()->sole();

    expect($customer)
        ->name->toBe('Lancaster Tools')
        ->email_marketing_provider->toBe(EmailMarketingProvider::Mailchimp)
        ->email_marketing_api_key->toBe('secret-mailchimp-key')
        ->email_marketing_list_id->toBe('audience-123')
        ->mailchimp_server_prefix->toBe('us21')
        ->and($customer->getRawOriginal('email_marketing_api_key'))->not->toContain('secret-mailchimp-key');
});

test('staff can create a customer without an email connection', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::customers.index')
        ->set('name', 'Future Customer')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    expect(Customer::query()->sole()->hasEmailMarketingConnection())->toBeFalse();
});

test('editing a customer keeps its stored API key when the field is blank', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->klaviyo()->create();

    Livewire::actingAs($user)
        ->test('pages::customers.index')
        ->call('editCustomer', $customer->id)
        ->set('name', 'Updated Customer')
        ->set('apiKey', '')
        ->call('saveCustomer')
        ->assertHasNoErrors();

    expect($customer->fresh())
        ->name->toBe('Updated Customer')
        ->email_marketing_api_key->toBe('klaviyo-test-key');
});

test('provider details are required when connecting a customer', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::customers.index')
        ->set('name', 'Incomplete Customer')
        ->set('provider', 'mailchimp')
        ->call('saveCustomer')
        ->assertHasErrors(['apiKey', 'listId', 'mailchimpServerPrefix']);

    expect(Customer::query()->exists())->toBeFalse();
});
