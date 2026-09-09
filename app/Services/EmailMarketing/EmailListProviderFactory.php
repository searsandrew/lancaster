<?php

namespace App\Services\EmailMarketing;

use App\Enums\EmailMarketingProvider;
use App\Models\Customer;
use LogicException;

class EmailListProviderFactory
{
    public function for(Customer $customer): EmailListProvider
    {
        return match ($customer->email_marketing_provider) {
            EmailMarketingProvider::Mailchimp => new MailchimpEmailListProvider,
            EmailMarketingProvider::Klaviyo => new KlaviyoEmailListProvider,
            null => throw new LogicException('The customer does not have an email marketing provider configured.'),
        };
    }
}
