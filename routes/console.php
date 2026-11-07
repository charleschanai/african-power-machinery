<?php

use App\Services\InvitationCampaignService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled jobs
|--------------------------------------------------------------------------
|
| everyMinute() entries carry ->withoutOverlapping() because this
| application can legitimately end up with more than one scheduler: the
| Laravel scheduler under cron or Task Scheduler, a loop inside the Docker
| container, and the /cron/tick HTTP endpoint used on shared hosting. Without
| the overlap lock, a task that runs for longer than its interval is started
| a second time while the first is still running, and the guest gets the same
| message twice. withoutOverlapping() is held for the full expiry period even
| if the process dies, so set the expiry generously.
|
*/

Schedule::command('sms:weekly-reminder')
    ->weeklyOn(0, '08:00')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->name('weekly-reminder');

// Picks up SMS that were scheduled for a future time. The command claims each
// row under a row lock before dispatching, so it is safe even if the overlap
// lock is ever bypassed — but the lock is still worth having.
Schedule::command('sms:process-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('process-scheduled-sms');

/*
|--------------------------------------------------------------------------
| Monitoring
|--------------------------------------------------------------------------
*/

// Polls NextSMS for delivery reports so the Invitations page can show
// whether each message actually reached the guest.
Schedule::call(function () {
    app(InvitationCampaignService::class)->syncSmsDeliveryStatus();
})->everyMinute()->name('sync-sms-delivery')->withoutOverlapping(2);

// Health checks + alerts. Every five minutes is frequent enough that an
// outage is noticed before the event starts, rare enough not to cost anything.
Schedule::command('monitor:health')
    ->everyFiveMinutes()
    ->withoutOverlapping(3)
    ->name('monitor-health');

// Proves the scheduler is alive. SystemMonitor::scheduler() treats a missing
// or stale heartbeat as "down", which is the only way a silently stopped
// scheduler — the cause of missing weekly reminders — becomes visible.
Schedule::command('monitor:heartbeat')
    ->everyMinute()
    ->name('monitor-heartbeat');

/*
|--------------------------------------------------------------------------
| Housekeeping
|--------------------------------------------------------------------------
*/

// Trims metrics, audit rows and old message history. Log and prune every day
// at 02:15 rather than midnight: the database is quietest then, and a long
// DELETE at midnight overlaps the start of the working day.
Schedule::command('data:prune')
    ->dailyAt('02:15')
    ->withoutOverlapping(30)
    ->name('prune-data');

Schedule::command('auth:clear-resets')
    ->daily()
    ->name('clear-password-resets');

// Queue housekeeping. queue:prune-batches exists so batch bookkeeping does
// not grow forever; queue:prune-failed keeps failed_jobs readable without
// deleting entries an operator has not yet had a chance to look at.
Schedule::command('queue:prune-batches --hours=48')->daily()->name('prune-batches');
Schedule::command('queue:prune-failed --hours=168')->daily()->name('prune-failed');
