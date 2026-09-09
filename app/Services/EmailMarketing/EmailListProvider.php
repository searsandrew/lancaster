<?php

namespace App\Services\EmailMarketing;

use App\Models\Customer;
use App\Models\Participant;

interface EmailListProvider
{
    public function subscribe(Participant $participant, Customer $customer): void;
}
