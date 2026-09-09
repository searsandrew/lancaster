<?php

namespace App\Services\EmailMarketing;

use App\Exceptions\PermanentEmailProviderException;
use App\Models\Customer;
use App\Models\Participant;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MailchimpEmailListProvider implements EmailListProvider
{
    public function subscribe(Participant $participant, Customer $customer): void
    {
        $subscriberHash = md5(mb_strtolower($participant->email));
        $response = Http::withBasicAuth('quiz-signup', $customer->email_marketing_api_key)
            ->connectTimeout(3)
            ->timeout(10)
            ->put("https://{$customer->mailchimp_server_prefix}.api.mailchimp.com/3.0/lists/{$customer->email_marketing_list_id}/members/{$subscriberHash}", [
                'email_address' => $participant->email,
                'status_if_new' => 'subscribed',
                'status' => 'subscribed',
                'merge_fields' => [
                    'FNAME' => $participant->first_name,
                    'LNAME' => $participant->last_name,
                ],
            ]);

        $this->throwForFailure($response);
    }

    private function throwForFailure(Response $response): void
    {
        if ($response->clientError() && $response->status() !== 429) {
            throw new PermanentEmailProviderException('Mailchimp rejected the email subscription request.');
        }

        $response->throw();
    }
}
