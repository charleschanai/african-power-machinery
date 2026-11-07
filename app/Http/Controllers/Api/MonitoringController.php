<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemMetric;
use App\Services\SystemMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonitoringController extends Controller
{
    public function __construct(
        private readonly SystemMonitor $monitor,
    ) {
    }

    /**
     * Everything the monitoring screen needs in one request.
     *
     * Only two things come back: the checks and the recent events. The
     * per-table row counts, per-route latency and 24h traffic series that used
     * to be here were never actionable on their own — every real problem they
     * hinted at is already reported by a check — and each one cost an extra
     * table scan on every page load.
     */
    public function index(Request $request): JsonResponse
    {
        $health = $this->monitor->all();

        return response()->json([
            'success' => true,
            'data' => [
                'health' => $health,
                'events' => $this->monitor->recentEvents(),
            ],
        ]);
    }

    /**
     * Just the health checks — cheap enough to poll.
     */
    public function health(): JsonResponse
    {
        $result = $this->monitor->all();

        return response()->json([
            'success' => $result['status'] !== SystemMonitor::DOWN,
            'data' => $result,
        ], $result['status'] === SystemMonitor::DOWN ? 503 : 200);
    }

    /**
     * Recent performance events, filterable.
     */
    public function metrics(Request $request): JsonResponse
    {
        $query = SystemMetric::query()
            ->since($this->sinceFrom($request))
            ->metric($request->input('metric'))
            ->when($request->filled('level'), fn ($q) => $q->atLeast((string) $request->input('level')));

        $metrics = $query->orderByDesc('id')
            ->paginate(min(100, max(10, $request->integer('per_page', 25))));

        return response()->json([
            'success' => true,
            'data' => $metrics,
        ]);
    }

    /**
     * Distinct metric names, for the filter dropdown.
     */
    public function metricNames(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => DB::table('system_metrics')
                ->select('metric')
                ->distinct()
                ->orderBy('metric')
                ->pluck('metric'),
        ]);
    }

    /**
     * Prune old metrics on demand. The same work runs daily from the
     * scheduler; this endpoint exists so an admin can reclaim space now.
     */
    public function prune(Request $request): JsonResponse
    {
        $days = (int) $request->input('days', (int) config('monitoring.retention.metrics_days', 30));
        $days = max(1, min($days, 365));

        $deleted = SystemMetric::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "Deleted {$deleted} metric record(s) older than {$days} day(s).",
            'data' => ['deleted' => $deleted, 'days' => $days],
        ]);
    }

    /**
     * Run the health checks now and raise any resulting alerts.
     */
    public function runChecks(): JsonResponse
    {
        $result = $this->monitor->all();

        return response()->json([
            'success' => true,
            'message' => $result['status'] === SystemMonitor::OK
                ? 'All checks passed.'
                : 'Some checks need attention.',
            'data' => $result,
        ]);
    }

    private function sinceFrom(Request $request): ?\DateTimeInterface
    {
        $hours = $request->integer('hours', 24);

        if ($hours <= 0) {
            return null;
        }

        return now()->subHours(min($hours, 24 * 30));
    }
}
