<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Writes the scheduler heartbeat that SystemMonitor::scheduler() reads.
 *
 * A stopped scheduler produces no error at all — it just quietly stops
 * sending weekly reminders. This makes the silence detectable.
 */
class MonitorHeartbeat extends Command
{
    protected $signature = 'monitor:heartbeat';

    protected $description = 'Record that the scheduler is alive';

    public function handle(): int
    {
        Cache::put('scheduler:last_heartbeat', now()->timestamp, now()->addMinutes(30));

        return self::SUCCESS;
    }
}
