<?php

namespace App\Jobs;

use App\Exceptions\PermanentEmailProviderException;
use App\Models\Participant;
use App\Services\EmailMarketing\EmailListProviderFactory;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubscribeParticipantToEmailList implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public Participant $participant) {}

    public function handle(EmailListProviderFactory $providers): void
    {
        $customer = $this->participant->show->quiz?->customer;

        if (! $this->participant->marketing_opt_in || ! $customer?->hasEmailMarketingConnection()) {
            return;
        }

        try {
            $providers->for($customer)->subscribe($this->participant, $customer);
        } catch (PermanentEmailProviderException $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
