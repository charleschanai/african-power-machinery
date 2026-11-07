<?php

namespace App\Console\Commands;

use App\Services\SystemMonitor;
use Illuminate\Console\Command;
use Throwable;

class MonitorHealth extends Command
{
    protected $signature = 'monitor:health {--json : Print the raw payload instead of a table}';

    protected $description = 'Run the application health checks and alert on anything that is down';

    public function handle(SystemMonitor $monitor): int
    {
        try {
            $result = $monitor->all();
        } catch (Throwable $e) {
            $this->error('Health check could not run: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT));

            return $result['status'] === SystemMonitor::DOWN ? self::FAILURE : self::SUCCESS;
        }

        $this->render($result);

        return $result['status'] === SystemMonitor::DOWN ? self::FAILURE : self::SUCCESS;
    }

    private function render(array $result): void
    {
        $labels = [
            SystemMonitor::OK => '<fg=green>OK</>',
            SystemMonitor::DEGRADED => '<fg=yellow>DEGRADED</>',
            SystemMonitor::DOWN => '<fg=red>DOWN</>',
        ];

        $this->newLine();
        $this->line("  System health: {$labels[$result['status']]}");
        $this->newLine();

        $rows = [];

        foreach ($result['checks'] as $name => $check) {
            $rows[] = [
                str_replace('_', ' ', $name),
                $labels[$check['status'] ?? SystemMonitor::OK],
                $this->summarise($check),
            ];
        }

        $this->table(['Check', 'Status', 'Detail'], $rows);
        $this->newLine();
        $this->line('  '.$result['generated_at']);
        $this->newLine();
    }

    /** One short line per check, so the output is scannable in a cron log. */
    private function summarise(array $check): string
    {
        $parts = [];

        foreach ($check as $key => $value) {
            if ($key === 'status' || $key === 'queues' || $key === 'missing') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $parts[] = $key.'='.(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
            }
        }

        if (($check['reason'] ?? null) !== null) {
            $parts[] = (string) $check['reason'];
        }

        return implode('  ', array_slice($parts, 0, 5));
    }
}
