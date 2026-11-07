<?php

namespace App\Services;

use App\Models\SystemMetric;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Central place for writing metrics.
 *
 * Two rules this class exists to enforce:
 *
 *  1. Monitoring must never be able to take the application down. Every write
 *     is wrapped, because a full disk or a locked metrics table should degrade
 *     observability, not the wedding gate.
 *  2. Aggregates go to the cache (cheap, always there, what the dashboard
 *     reads); raw events go to the database only when they are worth keeping.
 *     This is what stops an observability layer becoming the load problem it
 *     was built to detect.
 */
class MetricsRecorder
{
    /** Cache key holding the rolling counters. */
    private const CACHE_KEY = 'metrics:counters';

    /** Counter window. */
    private const WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly AlertService $alerts,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('monitoring.enabled', true);
    }

    /* ------------------------------------------------------------------ */
    /*  Aggregates — always cache, never database                          */
    /* ------------------------------------------------------------------ */

    /**
     * Add to a named counter inside the current window.
     *
     * Counter names are fixed strings, never user input, so they cannot
     * explode the cache keyspace.
     */
    public function increment(string $name, int $amount = 1): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $key = self::CACHE_KEY.':'.$name;
            $current = (int) Cache::get($key, 0);
            Cache::put($key, $current + $amount, self::WINDOW_SECONDS);
        } catch (Throwable $e) {
            // Never let a cache hiccup surface to the user.
        }
    }

    public function counter(string $name): int
    {
        try {
            return (int) Cache::get(self::CACHE_KEY.':'.$name, 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** Observe a duration in milliseconds and return it, for easy inlining. */
    public function observeDuration(string $name, float $milliseconds): float
    {
        $this->increment($name.'.count');
        $this->increment($name.'.ms', (int) round($milliseconds));

        return $milliseconds;
    }

    /**
     * Rolling latency list for a route, used to compute p95/p99.
     *
     * Bounded to 200 samples so a very busy endpoint cannot grow the cache
     * value without limit.
     */
    public function observeLatency(string $route, float $milliseconds): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $key = self::CACHE_KEY.':latency:'.sha1($route);
            $samples = Cache::get($key, []);

            if (! is_array($samples)) {
                $samples = [];
            }

            $samples[] = $milliseconds;

            if (count($samples) > 200) {
                $samples = array_slice($samples, -200);
            }

            Cache::put($key, $samples, self::WINDOW_SECONDS);
        } catch (Throwable) {
            // Non-fatal.
        }
    }

    /** @return array{count:int, avg:float, p50:float, p95:float, p99:float, max:float} */
    public function latencyStats(string $route): array
    {
        try {
            $samples = Cache::get(self::CACHE_KEY.':latency:'.sha1($route), []);
        } catch (Throwable) {
            $samples = [];
        }

        if (! is_array($samples) || $samples === []) {
            return ['count' => 0, 'avg' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0, 'max' => 0.0];
        }

        sort($samples);
        $count = count($samples);

        return [
            'count' => $count,
            'avg' => round(array_sum($samples) / $count, 2),
            'p50' => $samples[(int) floor($count * 0.50)],
            'p95' => $samples[(int) floor($count * 0.95)],
            'p99' => $samples[min($count - 1, (int) floor($count * 0.99))],
            'max' => $samples[$count - 1],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Events — database, rate limited                                    */
    /* ------------------------------------------------------------------ */

    public function record(
        string $metric,
        string $level = 'info',
        array $attributes = [],
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        $this->increment('metric.'.$metric);
        $this->increment('level.'.$level);

        if (! config('monitoring.persist', true)) {
            return;
        }

        try {
            SystemMetric::query()->create([
                'metric' => $metric,
                'level' => $level,
                'method' => $attributes['method'] ?? null,
                'route' => isset($attributes['route']) ? substr((string) $attributes['route'], 0, 191) : null,
                'status_code' => $attributes['status_code'] ?? null,
                'duration_ms' => (int) round($attributes['duration_ms'] ?? 0),
                'memory_bytes' => (int) ($attributes['memory_bytes'] ?? 0),
                'user_id' => $attributes['user_id'] ?? null,
                'ip_address' => $attributes['ip_address'] ?? null,
                'context' => $attributes['context'] ?? null,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Almost always "table missing" because migrations have not run yet.
            Log::debug('Metric not persisted: '.$e->getMessage());
        } catch (Throwable $e) {
            Log::debug('Metric not persisted: '.$e->getMessage());
        }
    }

    /**
     * Log and record a slow query.
     *
     * Goes to the dedicated `sql` channel so that a slow-query storm does not
     * bury real application errors in laravel.log.
     */
    public function recordSlowQuery(string $sql, array $bindings, float $milliseconds, ?string $connection = null): void
    {
        $threshold = (float) config('monitoring.slow_query_ms', 250);

        if ($milliseconds < $threshold) {
            return;
        }

        // Bindings routinely contain guest names and phone numbers, so they
        // are only rendered when the sql channel is at debug level. The
        // metrics row never stores them.
        $includeBindings = (string) config('logging.channels.sql.level', 'info') === 'debug';

        Log::channel('sql')->warning('Slow query', [
            'duration_ms' => round($milliseconds, 2),
            'threshold_ms' => $threshold,
            'connection' => $connection,
            'sql' => $sql,
            'bindings' => $includeBindings
                ? array_map(fn ($b) => is_scalar($b) ? $b : get_debug_type($b), $bindings)
                : '[omitted]',
        ]);

        $this->record(SystemMetric::METRIC_SLOW_QUERY, 'warning', [
            'duration_ms' => $milliseconds,
            'context' => [
                'sql' => $sql,
                'connection' => $connection,
            ],
        ]);
    }

    /**
     * Record an application error, dedupe it, and alert if it is serious.
     *
     * Dedupe is by fingerprint (class + first stack frame). Without it a
     * retry loop produces thousands of identical rows and thousands of
     * identical alerts.
     */
    public function recordError(Throwable $e, array $context = []): void
    {
        $fingerprint = $this->fingerprint($e);

        $this->increment('errors.total');
        $this->increment('errors.fingerprint.'.$fingerprint);

        Log::channel('errorlog_daily')->error($e->getMessage(), array_merge($context, [
            'exception' => $e,
            'fingerprint' => $fingerprint,
        ]));

        $this->record(SystemMetric::METRIC_ERROR, $this->levelFor($e), [
            'context' => array_merge($context, [
                'exception' => $e::class,
                'message' => \Illuminate\Support\Str::limit($e->getMessage(), 500),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'fingerprint' => $fingerprint,
            ]),
        ]);

        $this->alerts->error($e, $context);
    }

    public function fingerprint(Throwable $e): string
    {
        $frame = $e->getFile().':'.$e->getLine();

        return substr(hash('sha256', $e::class.'|'.$frame), 0, 16);
    }

    /**
     * External provider call (WhatsApp / SMS / email / AI).
     *
     * Kept separate from request metrics because a slow provider is an
     * operational problem, not a code problem, and the fix is different.
     */
    public function recordExternalCall(string $provider, bool $success, float $milliseconds, array $context = []): void
    {
        $this->increment('external.'.$provider.'.'.($success ? 'ok' : 'fail'));
        $this->observeDuration('external.'.$provider, $milliseconds);

        $threshold = (float) config('monitoring.provider_timeout_ms', 10000);

        if ($milliseconds > $threshold || ! $success) {
            Log::channel('external')->notice('External call', [
                'provider' => $provider,
                'success' => $success,
                'duration_ms' => round($milliseconds, 2),
            ] + $context);

            $this->record(SystemMetric::METRIC_EXTERNAL, $success ? 'warning' : 'error', [
                'duration_ms' => $milliseconds,
                'context' => ['provider' => $provider] + $context,
            ]);
        }
    }

    public function levelFor(Throwable $e): string
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            $status = $e->getStatusCode();

            if ($status < 500) {
                return 'info';
            }
        }

        if (app()->isLocal()) {
            return 'debug';
        }

        return 'error';
    }

    /* ------------------------------------------------------------------ */
    /*  Summary                                                            */
    /* ------------------------------------------------------------------ */

    /** @return array<string, int> */
    public function snapshot(): array
    {
        return [
            'requests' => $this->counter('http.requests'),
            'errors' => $this->counter('http.errors'),
            'server_errors' => $this->counter('http.server_errors'),
            'slow_requests' => $this->counter('http.slow'),
            'slow_queries' => $this->counter('metric.slow_query'),
            'errors_total' => $this->counter('errors.total'),
            'queue_jobs' => $this->counter('metric.queue_job'),
            'logins' => $this->counter('metric.auth_login'),
        ];
    }
}
