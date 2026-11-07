<?php

namespace App\Console\Commands;

use App\Enums\InvitationChannel;
use App\Enums\InvitationStatus;
use App\Jobs\SendSmsInvitationJob;
use App\Models\InvitationSend;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends SMS invitations that were scheduled for a future time.
 *
 * Claiming matters here. This command used to select every row with
 * status = queued and scheduled_at <= now(), dispatch a job, and leave the
 * status alone. The next scheduler tick — one minute later — selected the
 * same rows again. With four schedulers running (cron, Task Scheduler, the
 * Docker loop and the /cron/tick endpoint) each guest could be messaged up
 * to four times.
 *
 * Rows are now claimed inside a transaction with SELECT ... FOR UPDATE, and a
 * claim older than self::CLAIM_TIMEOUT is treated as abandoned (the worker
 * that held it died) so a message is never lost either.
 */
class ProcessScheduledSms extends Command
{
    protected $signature = 'sms:process-scheduled {--dry-run : List what would be sent without sending}';

    protected $description = 'Send scheduled SMS invitations that are due';

    /** A claim older than this is assumed to belong to a dead worker. */
    private const CLAIM_TIMEOUT_MINUTES = 10;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $count = $this->dueQuery()->count();
            $this->info("{$count} scheduled SMS invitation(s) are due. Nothing sent (dry run).");

            return Command::SUCCESS;
        }

        try {
            $claimed = $this->claimDue();
        } catch (Throwable $e) {
            Log::channel('monitoring')->error('sms:process-scheduled failed: '.$e->getMessage());

            $this->error('Could not claim scheduled SMS: '.$e->getMessage());

            return Command::FAILURE;
        }

        foreach ($claimed as $send) {
            dispatch(new SendSmsInvitationJob($send->card_id))->onQueue('sms');
        }

        $this->info('Dispatched '.count($claimed).' scheduled SMS invitation(s).');

        return Command::SUCCESS;
    }

    /**
     * Rows that are due and not already claimed.
     */
    private function dueQuery()
    {
        $staleBefore = now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES);

        return InvitationSend::query()
            ->where('channel', InvitationChannel::Sms->value)
            ->where('status', InvitationStatus::Queued->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->where(function ($query) use ($staleBefore) {
                // Never claimed, or claimed long enough ago that the worker
                // holding it is presumed dead.
                $query->whereNull('queued_at')
                    ->orWhere('queued_at', '<=', $staleBefore);
            });
    }

    /**
     * Atomically mark rows as claimed and hand back the card ids.
     *
     * The lock is what makes two schedulers safe: the second one blocks,
     * then re-reads and finds the rows already stamped, so it skips them.
     *
     * @return array<int, \App\Models\InvitationSend>
     */
    private function claimDue(): array
    {
        return DB::transaction(function (): array {
            $rows = $this->dueQuery()
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $now = now();
            $ids = $rows->pluck('id');

            InvitationSend::query()
                ->whereIn('id', $ids)
                ->update(['queued_at' => $now]);

            return $rows->each(fn ($row) => $row->queued_at = $now)->all();
        });
    }
}
