<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\SystemMetric;
use App\Models\WeeklyReminderLog;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Keeps the append-heavy tables from filling the disk.
 *
 * Three tables grow without bound in this application:
 *   entry_logs         one row per scan, forever
 *   whatsapp_messages  two rows per conversation turn
 *   audit_logs         one row per authenticated action
 *
 * During an event all three spike at once, and entry_logs is queried by the
 * dashboard for an all-time chart. Deleting in bounded chunks matters: a
 * single unbounded DELETE on a large InnoDB table holds locks long enough to
 * stall the gate.
 *
 * Gate entry records are NOT deleted by default. They are the core business
 * record of the event and losing them is worse than running out of disk.
 * Pass --force to include them.
 */
class PruneData extends Command
{
    protected $signature = 'data:prune
        {--days= : Override the metrics retention in days}
        {--include-entry-logs : Also delete entry logs older than the retention window}
        {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete old metrics, audit rows and message history';

    /** Rows deleted per query. Keeps each statement short. */
    private const CHUNK = 2000;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $metricDays = (int) ($this->option('days') ?: config('monitoring.retention.metrics_days', 30));
        $auditDays = (int) config('monitoring.retention.audit_days', 180);
        $messageDays = (int) config('monitoring.retention.whatsapp_messages_days', 365);
        $entryLogDays = (int) config('monitoring.retention.entry_logs_days', 0);

        $plan = [
            ['system_metrics', 'created_at', $metricDays],
            ['audit_logs', 'created_at', $auditDays],
            ['whatsapp_messages', 'created_at', $messageDays],
        ];

        if ($this->option('include-entry-logs') && $entryLogDays > 0) {
            $plan[] = ['entry_logs', 'scanned_at', $entryLogDays];
        }

        $total = 0;

        foreach ($plan as [$table, $column, $days]) {
            $total += $this->pruneTable($table, $column, $days, $dryRun);
        }

        if (! $dryRun && $total > 0) {
            Log::channel('monitoring')->info("data:prune removed {$total} row(s)");
        }

        if (! $this->option('include-entry-logs') && $entryLogDays === 0) {
            $this->line('  Entry logs were kept. Re-run with --include-entry-logs to remove them too.');
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would delete ' : 'Deleted ')."{$total} row(s).");

        return self::SUCCESS;
    }

    private function pruneTable(string $table, string $column, int $days, bool $dryRun): int
    {
        if ($days <= 0) {
            $this->line(sprintf('  %-20s kept (retention disabled)', $table));

            return 0;
        }

        if (! Schema::hasTable($table)) {
            return 0;
        }

        $cutoff = now()->subDays($days);

        try {
            $query = DB::table($table)->where($column, '<', $cutoff);

            if ($dryRun) {
                $count = (clone $query)->count();
                $this->line(sprintf('  %-20s %s row(s) older than %s', $table, number_format($count), $cutoff->toDateString()));

                return 0;
            }

            $deleted = 0;

            // delete() with a limit looped until it stops deleting. One
            // unbounded DELETE on a big table can hold row locks long enough
            // to block the entry gate, which is unacceptable during an event.
            do {
                $chunk = DB::table($table)
                    ->where($column, '<', $cutoff)
                    ->limit(self::CHUNK)
                    ->delete();

                $deleted += $chunk;
            } while ($chunk === self::CHUNK);

            $this->line(sprintf('  %-20s deleted %s row(s) older than %s', $table, number_format($deleted), $cutoff->toDateString()));

            return $deleted;
        } catch (Throwable $e) {
            $this->warn("  {$table}: ".$e->getMessage());

            return 0;
        }
    }
}
