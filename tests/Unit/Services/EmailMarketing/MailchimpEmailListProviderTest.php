<?php

use App\Models\Customer;
use App\Models\Participant;
use App\Services\EmailMarketing\MailchimpEmailListProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('it subscribes a participant to the configured Mailchimp audience', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://us21.api.mailchimp.com/3.0/lists/audience-123/members/*' => Http::response([], 200),
    ]);
    $customer = Customer::factory()->mailchimp()->make([
        'email_marketing_api_key' => 'secret-key',
        'email_marketing_list_id' => 'audience-123',
        'mailchimp_server_prefix' => 'us21',
    ]);
    $participant = new Participant([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ADA@example.com',
    ]);

    (new MailchimpEmailListProvider)->subscribe($participant, $customer);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PUT'
            && $request->url() === 'https://us21.api.mailchimp.com/3.0/lists/audience-123/members/'.md5('ada@example.com')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('quiz-signup:secret-key'))
            && $request['status_if_new'] === 'subscribed'
            && $request['merge_fields'] === ['FNAME' => 'Ada', 'LNAME' => 'Lovelace'];
    });
});
