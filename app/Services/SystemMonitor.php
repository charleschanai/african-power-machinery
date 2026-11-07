<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Health checks for the whole application.
 *
 * Each check returns ['status' => ok|degraded|down, ...detail]. The
 * DashboardController turns this into the monitoring screen; the
 * DiagnosingHealth listener turns it into the /up response; the
 * monitor:health command turns it into alerts.
 */
class SystemMonitor
{
    public const OK = 'ok';
    public const DEGRADED = 'degraded';
    public const DOWN = 'down';

    public function __construct(
        private readonly AlertService $alerts,
        private readonly MetricsRecorder $metrics,
    ) {
    }

    /**
     * Run every check.
     *
     * Each check is isolated. A monitoring tool that crashes when the thing it
     * monitors is broken is worse than useless — it takes the diagnostic away
     * at exactly the moment it is needed — so a check that throws is reported
     * as "down, and here is why" rather than allowed to escape.
     *
     * The list is deliberately short. A dashboard showing a dozen numbers stops
     * being read, so a check only earns a place here if it maps to a failure
     * this application actually suffers from:
     *
     *   database     nothing works without it
     *   queue        invitations and SMS go to named queues; a worker started
     *                without --queue leaves them waiting forever
     *   schedule     weekly reminders stop silently, with no error anywhere
     *   disk         card images and entry logs grow without bound
     *   performance  slow requests and a rising error rate
     *   providers    an unconfigured WhatsApp/SMS channel fails silently
     *
     * Dropped: "cache" (a dead cache degrades, it does not stop the gate) and
     * "logging" (log size is a subset of the disk check).
     *
     * @return array{status:string, checks:array<string, array<string, mixed>>, generated_at:string}
     */
    public function all(): array
    {
        $checks = [
            'database' => fn () => $this->database(),
            'queue' => fn () => $this->queue(),
            'disk' => fn () => $this->disk(),
            'performance' => fn () => $this->performance(),
            'schedule' => fn () => $this->scheduler(),
            'providers' => fn () => $this->providers(),
        ];

        $results = [];

        foreach ($checks as $name => $check) {
            $results[$name] = $this->guard($name, $check);
        }

        $status = self::OK;

        foreach ($results as $check) {
            if ($check['status'] === self::DOWN) {
                $status = self::DOWN;
                break;
            }

            if ($check['status'] === self::DEGRADED) {
                $status = self::DEGRADED;
            }
        }

        return [
            'status' => $status,
            'checks' => $results,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Run one check, converting an unexpected throw into a "down" result.
     */
    private function guard(string $name, callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            Log::channel('monitoring')->error("Health check [{$name}] threw", [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => self::DOWN,
                'reason' => 'Check could not run: '.$e->getMessage(),
            ];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Individual checks                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Connectivity, latency, size, and slow-query ratio.
     *
     * The size check matters for this app specifically: entry_logs,
     * whatsapp_messages and audit_logs all grow without bound during a
     * single event, and a wedding is exactly when nobody wants a full disk.
     */
    public function database(): array
    {
        $result = ['status' => self::OK];

        try {
            // getDriverName() itself resolves the connection, so it has to be
            // inside the try. Reading it first meant a missing or unreachable
            // connection threw before the guard was in place, and the health
            // endpoint answered 500 instead of 503 — which tells a load
            // balancer "this server is broken" rather than "the database is
            // down", and is the opposite of the distinction that matters.
            $result['connection'] = DB::getDriverName();

            $start = microtime(true);
            DB::select('select 1');
            $latency = (microtime(true) - $start) * 1000;

            $result['latency_ms'] = round($latency, 2);
            $limit = (float) config('monitoring.thresholds.db_connect_ms', 500);

            if ($latency > $limit) {
                $result['status'] = self::DEGRADED;
                $result['reason'] = "Database responds in {$result['latency_ms']}ms (limit {$limit}ms).";
            }

            $result['size_mb'] = $this->databaseSizeMb();
            $sizeLimit = (float) config('monitoring.thresholds.db_size_mb', 2048);

            if ($result['size_mb'] > $sizeLimit) {
                $result['status'] = self::DOWN;
                $result['reason'] = sprintf(
                    'Database is %s MB (limit %s MB). Archive old logs.',
                    number_format($result['size_mb'], 0),
                    number_format($sizeLimit, 0),
                );
            }

            // Slow-query ratio over the last hour.
            $ratio = $this->slowQueryRatio();

            $result['slow_query_ratio'] = $ratio;
            $ratioLimit = (float) config('monitoring.thresholds.db_slow_query_ratio', 0.1);

            if ($ratio > $ratioLimit) {
                $result['status'] = self::DEGRADED;
                $result['reason'] = sprintf(
                    '%.1f%% of queries are slow (limit %.1f%%). See storage/logs/sql.log.',
                    $ratio * 100,
                    $ratioLimit * 100,
                );

                $this->alerts->slowQueryRate($ratio, $ratioLimit);
            }
        } catch (Throwable $e) {
            $result['status'] = self::DOWN;
            $result['reason'] = 'Cannot reach the database: '.$e->getMessage();

            $this->alerts->health('database', self::DOWN, ['reason' => $result['reason']]);
        }

        return $result;
    }

    /**
     * Backlog per queue, plus failed jobs.
     *
     * This check exists because of a real failure mode in this codebase:
     * jobs are dispatched to the named `sms` and `whatsapp` queues, and if a
     * worker is started without `--queue` those jobs sit there forever while
     * the dashboard cheerfully reports them as "pending".
     */
    public function queue(): array
    {
        $result = [
            'status' => self::OK,
            'connection' => config('queue.default'),
            'queues' => [],
            'failed' => 0,
        ];

        if (config('queue.default') === 'sync') {
            $result['reason'] = 'Queue driver is "sync" — jobs run inline, no worker needed.';

            return $result;
        }

        foreach (['default', 'sms', 'whatsapp'] as $queue) {
            try {
                $size = DB::table('jobs')
                    ->where('queue', $queue)
                    ->count();
            } catch (Throwable) {
                $size = null;
            }

            $result['queues'][$queue] = $size;
        }

        $backlogLimit = (int) config('monitoring.thresholds.queue_backlog', 100);

        foreach ($result['queues'] as $queue => $size) {
            if ($size !== null && $size > $backlogLimit) {
                $result['status'] = self::DEGRADED;
                $result['reason'] = "Queue '{$queue}' has {$size} waiting jobs (limit {$backlogLimit}).";

                $this->alerts->queueBacklog((string) $queue, $size, $backlogLimit);
            }
        }

        try {
            $failed = DB::table('failed_jobs')->count();
            $result['failed'] = $failed;

            if ($failed > (int) config('monitoring.thresholds.failed_jobs', 0)) {
                $result['status'] = self::DOWN;
                $result['reason'] = "{$failed} failed job(s). Run: php artisan queue:failed";

                $this->alerts->failedJobs($failed);
            }
        } catch (Throwable) {
            // failed_jobs table may not exist yet.
        }

        return $result;
    }

    public function disk(string $path = null): array
    {
        $path ??= storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false) {
            return ['status' => self::DEGRADED, 'reason' => "Cannot read disk space for {$path}."];
        }

        $freeMb = $free / 1024 / 1024;
        $limit = (float) config('monitoring.thresholds.disk_free_mb', 500);

        $result = [
            'status' => self::OK,
            'path' => $path,
            'free_mb' => round($freeMb, 1),
            'total_mb' => $total ? round($total / 1024 / 1024, 1) : null,
        ];

        if ($freeMb < $limit) {
            $result['status'] = self::DOWN;
            $result['reason'] = sprintf(
                'Only %s MB free (limit %s MB).',
                number_format($freeMb, 0),
                number_format($limit, 0),
            );

            $this->alerts->diskSpace($path, $freeMb, $limit);
        }

        return $result;
    }

    /**
     * Request latency percentiles and error rate from the current window.
     */
    public function performance(): array
    {
        $requests = $this->metrics->counter('http.requests');
        $errors = $this->metrics->counter('http.errors');
        $rate = $requests > 0 ? round($errors / $requests, 4) : 0.0;

        $result = [
            'status' => self::OK,
            'requests' => $requests,
            'errors' => $errors,
            'error_rate' => $rate,
            'slow_requests' => $this->metrics->counter('http.slow'),
            'slow_queries' => $this->metrics->counter('metric.slow_query'),
        ];

        $limit = (float) config('monitoring.thresholds.error_rate', 0.05);

        if ($rate > $limit) {
            $result['status'] = self::DEGRADED;
            $result['reason'] = sprintf(
                'Error rate is %.1f%% (limit %.1f%%).',
                $rate * 100,
                $limit * 100,
            );
        }

        return $result;
    }

    /**
     * Is the scheduler running?
     *
     * Weekly reminders and scheduled SMS depend entirely on it, and a
     * stopped scheduler produces no error at all — it just silently stops
     * sending. The heartbeat is written by monitor:heartbeat.
     */
    public function scheduler(): array
    {
        $last = Cache::get('scheduler:last_heartbeat');

        $result = [
            'status' => self::OK,
            'last_run' => $last ? date('Y-m-d H:i:s', (int) $last) : null,
        ];

        if ($last === null) {
            $result['status'] = self::DEGRADED;
            $result['reason'] = 'Scheduler has never reported in. Add the monitor:heartbeat schedule entry.';

            return $result;
        }

        $minutes = (int) ((time() - (int) $last) / 60);

        $result['minutes_since_run'] = $minutes;

        if ($minutes > 10) {
            $result['status'] = self::DOWN;
            $result['reason'] = "Scheduler last ran {$minutes} minutes ago. It is not running.";
        }

        return $result;
    }

    /**
     * Are the outbound providers configured? Not "are they up" — that would
     * mean sending real money on every health check — just "would a send
     * silently do nothing".
     */
    public function providers(): array
    {
        $whatsapp = app(WhatsAppService::class)->isConfigured();
        $sms = app(SmsService::class)->isConfigured();
        $email = filled(config('mail.mailers.smtp.username'))
            || filled(config('services.mail.username'));
        $ai = app(AiAssistant::class)->isConfigured();

        $missing = array_keys(array_filter([
            'whatsapp' => $whatsapp,
            'sms' => $sms,
            'email' => $email,
        ], fn ($configured) => ! $configured));

        return [
            'status' => $missing === [] ? self::OK : self::DEGRADED,
            'whatsapp' => $whatsapp,
            'sms' => $sms,
            'email' => $email,
            'ai' => $ai,
            'missing' => $missing,
            'reason' => $missing === []
                ? null
                : 'Not configured: '.implode(', ', $missing).'. Sends to these will fail silently.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    public function databaseSizeMb(): float
    {
        try {
            $driver = DB::getDriverName();

            if ($driver === 'sqlite') {
                $path = config('database.connections.sqlite.database');

                return is_file($path) ? round(filesize($path) / 1024 / 1024, 2) : 0.0;
            }

            if ($driver === 'mysql') {
                $row = DB::selectOne(
                    'select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = database()'
                );

                return round(((int) ($row->bytes ?? 0)) / 1024 / 1024, 2);
            }

            if ($driver === 'pgsql') {
                $size = DB::selectOne('select pg_database_size(current_database()) as bytes');

                return round(((int) ($size->bytes ?? 0)) / 1024 / 1024, 2);
            }
        } catch (Throwable) {
            return 0.0;
        }

        return 0.0;
    }

    /**
     * Fraction of recorded metrics that were slow queries, last hour.
     */
    public function slowQueryRatio(): float
    {
        try {
            $total = DB::table('system_metrics')
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($total === 0) {
                return 0.0;
            }

            $slow = DB::table('system_metrics')
                ->where('created_at', '>=', now()->subHour())
                ->where('metric', 'slow_query')
                ->count();

            return round($slow / $total, 4);
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * Recent notable events, newest first.
     */
    public function recentEvents(int $limit = 25): array
    {
        try {
            return DB::table('system_metrics')
                ->select(['id', 'metric', 'level', 'method', 'route', 'status_code', 'duration_ms', 'context', 'created_at'])
                ->whereIn('level', ['warning', 'error', 'critical'])
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'metric' => $row->metric,
                    'level' => $row->level,
                    'method' => $row->method,
                    'route' => $row->route,
                    'status_code' => $row->status_code ? (int) $row->status_code : null,
                    'duration_ms' => (int) $row->duration_ms,
                    'context' => $row->context ? json_decode((string) $row->context, true) : null,
                    'created_at' => (string) $row->created_at,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
