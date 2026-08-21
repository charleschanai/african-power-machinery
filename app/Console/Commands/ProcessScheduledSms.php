<?php

namespace App\Console\Commands;

use App\Enums\InvitationChannel;
use App\Enums\InvitationStatus;
use App\Jobs\SendSmsInvitationJob;
use App\Models\InvitationSend;
use Illuminate\Console\Command;

class ProcessScheduledSms extends Command
{
    protected $signature = 'sms:process-scheduled';

    protected $description = 'Process and send scheduled SMS invitations';

    public function handle(): int
    {
        $sends = InvitationSend::query()
            ->where('channel', InvitationChannel::Sms->value)
            ->where('status', InvitationStatus::Queued->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->with('card')
            ->get();

        $count = 0;

        foreach ($sends as $send) {
            if ($send->card) {
                dispatch(new SendSmsInvitationJob($send->card_id))->onQueue('sms');
                $count++;
            }
        }

        $this->info("Processed {$count} scheduled SMS invitation(s).");

        return Command::SUCCESS;
    }
}
