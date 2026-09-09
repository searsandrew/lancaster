<?php

use App\Models\Customer;
use App\Models\Participant;
use App\Services\EmailMarketing\KlaviyoEmailListProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('it subscribes a participant to the configured Klaviyo list', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://a.klaviyo.com/api/profile-import' => Http::response([], 201),
        'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs' => Http::response([], 202),
    ]);
    $customer = Customer::factory()->klaviyo()->make([
        'email_marketing_api_key' => 'secret-key',
        'email_marketing_list_id' => 'list-123',
    ]);
    $participant = new Participant([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
    ]);

    (new KlaviyoEmailListProvider)->subscribe($participant, $customer);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://a.klaviyo.com/api/profile-import'
        && $request['data']['attributes']['email'] === 'ada@example.com'
        && $request['data']['attributes']['first_name'] === 'Ada'
        && $request['data']['attributes']['last_name'] === 'Lovelace');
    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Klaviyo-API-Key secret-key')
            && $request->hasHeader('revision', '2026-07-15')
            && $request['data']['relationships']['list']['data']['id'] === 'list-123'
            && $request['data']['attributes']['profiles']['data'][0]['attributes']['email'] === 'ada@example.com'
            && $request['data']['attributes']['profiles']['data'][0]['attributes']['subscriptions']['email']['marketing']['consent'] === 'SUBSCRIBED';
    });
});
