<?php

namespace App\Enums;

enum EmailMarketingProvider: string
{
    case Mailchimp = 'mailchimp';
    case Klaviyo = 'klaviyo';

    public function label(): string
    {
        return match ($this) {
            self::Mailchimp => 'Mailchimp',
            self::Klaviyo => 'Klaviyo',
        };
    }
}
