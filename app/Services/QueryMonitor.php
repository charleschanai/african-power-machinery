<?php

namespace App\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Watches every query the application runs and flags the slow ones.
 *
 * Attached once from AppServiceProvider. Uses a simple counter rather than
 * a per-request object, which keeps the listener allocation-free and means
 * a long-lived worker (queue) still resets correctly between jobs.
 */
class QueryMonitor
{
    private int $queryCount = 0;

    private float $totalMilliseconds = 0.0;

    private bool $attached = false;

    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {
    }

    public function attach(): void
    {
        if ($this->attached) {
            return;
        }

        DB::listen(function (QueryExecuted $event) {
            $this->handle($event);
        });

        $this->attached = true;
    }

    public function handle(QueryExecuted $event): void
    {
        if (! $this->metrics->isEnabled()) {
            return;
        }

        $this->queryCount++;
        $this->totalMilliseconds += $event->time;

        $threshold = (float) config('monitoring.slow_query_ms', 250);

        if ($event->time >= $threshold) {
            $this->metrics->recordSlowQuery(
                $event->sql,
                $event->bindings,
                $event->time,
                $event->connectionName,
            );
        }
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function totalMilliseconds(): float
    {
        return round($this->totalMilliseconds, 2);
    }

    /**
     * Report a request that ran an unusual number of queries.
     *
     * A jump from 8 to 80 queries is the signature of an N+1, and it is
     * invisible on a response-time chart until the data set grows.
     */
    public function checkBudget(?string $route): void
    {
        $budget = (int) config('monitoring.query_budget_per_request', 60);

        if ($budget <= 0 || $this->queryCount === 0 || $this->queryCount <= $budget) {
            return;
        }

        Log::channel('sql')->warning('Query budget exceeded', [
            'route' => $route,
            'queries' => $this->queryCount,
            'budget' => $budget,
            'total_ms' => $this->totalMilliseconds(),
            'hint' => 'Likely an N+1. Eager-load the relation or add it to a scope.',
        ]);

        $this->metrics->record('query_budget_exceeded', 'warning', [
            'route' => $route,
            'duration_ms' => $this->totalMilliseconds(),
            'context' => ['queries' => $this->queryCount, 'budget' => $budget],
        ]);
    }

    public function reset(): void
    {
        $this->queryCount = 0;
        $this->totalMilliseconds = 0.0;
    }
}
