<?php

namespace App\Jobs;

use App\Services\InvitationCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSmsInvitationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $cardId)
    {
    }

    public function handle(InvitationCampaignService $service): void
    {
        $service->sendSms($this->cardId);
    }
}
