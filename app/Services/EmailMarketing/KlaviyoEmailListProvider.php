<?php

namespace App\Services\EmailMarketing;

use App\Exceptions\PermanentEmailProviderException;
use App\Models\Customer;
use App\Models\Participant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class KlaviyoEmailListProvider implements EmailListProvider
{
    public function subscribe(Participant $participant, Customer $customer): void
    {
        $profileResponse = $this->client($customer)
            ->post('https://a.klaviyo.com/api/profile-import', [
                'data' => [
                    'type' => 'profile',
                    'attributes' => [
                        'email' => $participant->email,
                        'first_name' => $participant->first_name,
                        'last_name' => $participant->last_name,
                    ],
                ],
            ]);

        $this->throwForFailure($profileResponse);

        $subscriptionResponse = $this->client($customer)
            ->post('https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs', [
                'data' => [
                    'type' => 'profile-subscription-bulk-create-job',
                    'attributes' => [
                        'custom_source' => 'Quiz registration',
                        'profiles' => [
                            'data' => [[
                                'type' => 'profile',
                                'attributes' => [
                                    'email' => $participant->email,
                                    'subscriptions' => [
                                        'email' => [
                                            'marketing' => ['consent' => 'SUBSCRIBED'],
                                        ],
                                    ],
                                ],
                            ]],
                        ],
                    ],
                    'relationships' => [
                        'list' => [
                            'data' => [
                                'type' => 'list',
                                'id' => $customer->email_marketing_list_id,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->throwForFailure($subscriptionResponse);
    }

    private function client(Customer $customer): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Klaviyo-API-Key {$customer->email_marketing_api_key}",
            'accept' => 'application/vnd.api+json',
            'content-type' => 'application/vnd.api+json',
            'revision' => '2026-07-15',
        ])->connectTimeout(3)
            ->timeout(10);
    }

    private function throwForFailure(Response $response): void
    {
        if ($response->clientError() && $response->status() !== 429) {
            throw new PermanentEmailProviderException('Klaviyo rejected the email subscription request.');
        }

        $response->throw();
    }
}
