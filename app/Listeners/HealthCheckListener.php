<?php

namespace App\Listeners;

use App\Services\SystemMonitor;
use Illuminate\Foundation\Events\DiagnosingHealth;
use RuntimeException;

/**
 * Makes the framework's /up endpoint mean something.
 *
 * Without this, /up returns 200 as long as PHP boots — it never touches the
 * database or the disk. Both DEPLOYMENT.md and the Oracle setup script tell an
 * operator to use /up as the go-live gate, so it has to actually check.
 *
 * The framework turns any thrown exception here into a 500, which is exactly
 * the signal a load balancer or uptime monitor needs.
 */
class HealthCheckListener
{
    /**
     * Checks that must pass for /up to report healthy.
     *
     * Only unrecoverable ones. A failed job, a queue backlog or a missing
     * provider key is worth alerting about, but taking the server out of
     * rotation over it would turn a degraded service into no service.
     */
    private const CRITICAL = ['database', 'disk'];

    public function __construct(
        private readonly SystemMonitor $monitor,
    ) {
    }

    public function handle(DiagnosingHealth $event): void
    {
        // The framework calls /up on every container healthcheck tick. Running
        // the full suite each time is wasteful, so the expensive checks are
        // cached briefly.
        $result = $this->monitor->all();

        foreach (self::CRITICAL as $check) {
            if (($result['checks'][$check]['status'] ?? SystemMonitor::OK) === SystemMonitor::DOWN) {
                throw new RuntimeException(
                    'Health check failed: '.$check.' — '.($result['checks'][$check]['reason'] ?? 'unknown')
                );
            }
        }
    }
}
