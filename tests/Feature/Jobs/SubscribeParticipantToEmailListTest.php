<?php

use App\Exceptions\PermanentEmailProviderException;
use App\Jobs\SubscribeParticipantToEmailList;
use App\Models\Customer;
use App\Models\Participant;
use App\Models\Quiz;
use App\Models\Show;
use App\Services\EmailMarketing\EmailListProvider;
use App\Services\EmailMarketing\EmailListProviderFactory;

use function Pest\Laravel\mock;

test('it sends an opted-in participant through the customer provider', function () {
    $customer = Customer::factory()->klaviyo()->create();
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->for($customer)->create();
    $participant = Participant::factory()->for($show)->create(['marketing_opt_in' => true]);
    $provider = mock(EmailListProvider::class);
    $provider->shouldReceive('subscribe')
        ->once()
        ->withArgs(fn (Participant $sentParticipant, Customer $sentCustomer): bool => $sentParticipant->is($participant) && $sentCustomer->is($customer)
        );
    $factory = mock(EmailListProviderFactory::class);
    $factory->shouldReceive('for')->once()->withArgs(fn (Customer $sentCustomer): bool => $sentCustomer->is($customer))->andReturn($provider);

    (new SubscribeParticipantToEmailList($participant))->handle($factory);
});

test('it does nothing when the customer has no provider connection', function () {
    $customer = Customer::factory()->create();
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->for($customer)->create();
    $participant = Participant::factory()->for($show)->create(['marketing_opt_in' => true]);
    $factory = mock(EmailListProviderFactory::class);
    $factory->shouldNotReceive('for');

    (new SubscribeParticipantToEmailList($participant))->handle($factory);
});

test('it does not retry a provider request that was permanently rejected', function () {
    $customer = Customer::factory()->mailchimp()->create();
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->for($customer)->create();
    $participant = Participant::factory()->for($show)->create(['marketing_opt_in' => true]);
    $exception = new PermanentEmailProviderException('The provider rejected the request.');
    $provider = mock(EmailListProvider::class);
    $provider->shouldReceive('subscribe')->once()->andThrow($exception);
    $factory = mock(EmailListProviderFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($provider);
    $job = (new SubscribeParticipantToEmailList($participant))->withFakeQueueInteractions();

    $job->handle($factory);

    $job->assertFailedWith($exception);
});
