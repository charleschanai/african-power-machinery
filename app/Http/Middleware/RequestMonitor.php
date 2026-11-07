<?php

namespace App\Http\Middleware;

use App\Services\MetricsRecorder;
use App\Services\QueryMonitor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Times every request, records it, and flags the slow ones.
 *
 * The response still passes through untouched even if monitoring fails —
 * a metrics bug must never turn into a 500 for a guest at the gate.
 */
class RequestMonitor
{
    public function __construct(
        private readonly MetricsRecorder $metrics,
        private readonly QueryMonitor $queries,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->metrics->isEnabled()) {
            return $next($request);
        }

        $start = microtime(true);
        $memoryStart = memory_get_usage();

        $this->queries->reset();

        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;
        $memoryBytes = max(0, memory_get_peak_usage() - $memoryStart);

        try {
            $this->record($request, $response, $durationMs, $memoryBytes);
        } catch (Throwable $e) {
            Log::channel('monitoring')->warning('Request monitoring failed: '.$e->getMessage());
        }

        // Adds a Server-Timing header so the browser devtools can show where
        // the time went without a server round trip.
        try {
            $response->headers->set(
                'Server-Timing',
                sprintf('app;dur=%.1f', $durationMs),
            );
        } catch (Throwable) {
            // Some response types (e.g. streamed) are already sent.
        }

        return $response;
    }

    private function record(Request $request, Response $response, float $durationMs, int $memoryBytes): void
    {
        $status = $response->getStatusCode();
        $route = $request->route()?->uri() ?? $request->path();
        $userId = $request->user()?->id;

        $this->metrics->increment('http.requests');
        $this->metrics->observeDuration('http', $durationMs);
        $this->metrics->observeLatency((string) $route, $durationMs);

        if ($status >= 500) {
            $this->metrics->increment('http.server_errors');
        } elseif ($status >= 400) {
            $this->metrics->increment('http.client_errors');
        }

        $slowThreshold = (float) config('monitoring.slow_request_ms', 1000);
        $isSlow = $durationMs >= $slowThreshold;

        if ($isSlow) {
            $this->metrics->increment('http.slow');

            Log::channel('performance')->warning('Slow request', [
                'method' => $request->method(),
                'route' => $route,
                'status' => $status,
                'duration_ms' => round($durationMs, 2),
                'threshold_ms' => $slowThreshold,
                'queries' => $this->queries->queryCount(),
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'user_id' => $userId,
            ]);
        }

        // Only persist rows that are worth keeping. A dashboard of every
        // successful request is a dashboard nobody reads and a table that
        // needs pruning every day.
        $shouldPersist = config('monitoring.persist', true)
            && ($isSlow || $status >= 400 || $this->queries->queryCount() > (int) config('monitoring.query_budget_per_request', 60));

        if ($shouldPersist) {
            $this->metrics->record(
                \App\Models\SystemMetric::METRIC_REQUEST,
                $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : ($isSlow ? 'warning' : 'info')),
                [
                    'method' => $request->method(),
                    'route' => $route,
                    'status_code' => $status,
                    'duration_ms' => $durationMs,
                    'memory_bytes' => $memoryBytes,
                    'user_id' => $userId,
                    'ip_address' => $request->ip(),
                    'context' => ['queries' => $this->queries->queryCount()],
                ],
            );
        }

        $this->queries->checkBudget((string) $route);
    }
}
