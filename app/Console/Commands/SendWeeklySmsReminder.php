<?php

namespace App\Console\Commands;

use App\Services\WeeklyReminderService;
use Illuminate\Console\Command;

class SendWeeklySmsReminder extends Command
{
    protected $signature = 'sms:weekly-reminder';

    protected $description = 'Send weekly SMS reminders to all invited guests every Sunday';

    public function handle(WeeklyReminderService $service): int
    {
        $this->info('Starting weekly SMS reminder...');

        $result = $service->sendWeeklyReminders();

        $this->info("Weekly SMS reminder complete.");
        $this->info("  Dispatched: {$result['dispatched']}");
        $this->info("  Failed: {$result['failed']}");
        $this->info("  Skipped: {$result['skipped']}");
        $this->info("  Total eligible: {$result['total_eligible']}");
        $this->info("  Week: {$result['week_number']}/{$result['year']}");

        return Command::SUCCESS;
    }
}
