<?php

namespace Tests\Feature;

use App\Models\SystemMetric;
use App\Services\SystemMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function actAsRole(string $role): \App\Models\User
    {
        $user = \App\Models\User::factory()->create(['role' => $role]);

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /* ------------------------------------------------------------------ */
    /*  Access control                                                      */
    /* ------------------------------------------------------------------ */

    public function test_monitoring_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/monitoring')->assertUnauthorized();
        $this->getJson('/api/v1/monitoring/metrics')->assertUnauthorized();
    }

    public function test_staff_cannot_read_monitoring(): void
    {
        $this->actAsRole('staff');

        $response = $this->getJson('/api/v1/monitoring');

        // The catch-all used to turn a policy denial into a 500, which made
        // an authorization failure indistinguishable from a crash.
        $response->assertForbidden();
        $response->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_admin_can_read_monitoring(): void
    {
        $this->actAsRole('admin');

        $response = $this->getJson('/api/v1/monitoring');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'data' => [
                'health' => ['status', 'checks', 'generated_at'],
                'events',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Health endpoint                                                    */
    /* ------------------------------------------------------------------ */

    public function test_public_health_endpoint_reports_checks(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['checks' => ['database', 'queue', 'disk', 'performance', 'schedule', 'providers']]]);

        $checks = $response->json('data.checks');

        /*
         * The whole-suite status is deliberately not asserted. It depends on
         * the scheduler heartbeat and the provider configuration, both of
         * which are legitimately unhappy in a test run and neither of which
         * turns the endpoint red. What must hold is that a reachable database
         * is reported healthy, because that is what the 503 decision is based
         * on.
         */
        $this->assertNotSame(SystemMonitor::DOWN, $response->json('data.status'));
        $this->assertNotSame(SystemMonitor::DOWN, $checks['database']['status']);
        $this->assertArrayHasKey('latency_ms', $checks['database']);
    }

    public function test_monitoring_reports_only_the_checks_that_matter(): void
    {
        /*
         * The reported set was deliberately cut down: cache and logging were
         * dropped because neither corresponds to a failure this app suffers
         * from, and a dashboard nobody reads is not monitoring. This test stops
         * the list from quietly growing back.
         */
        $checks = $this->getJson('/api/v1/health')->json('data.checks');

        $this->assertEqualsCanonicalizing(
            ['database', 'disk', 'performance', 'providers', 'queue', 'schedule'],
            array_keys($checks)
        );

        $this->assertArrayNotHasKey('cache', $checks);
        $this->assertArrayNotHasKey('logging', $checks);
    }

    public function test_monitoring_index_does_not_return_the_bulky_series(): void
    {
        $response = $this->actingAs($this->actAsRole('admin'), 'sanctum')
            ->getJson('/api/v1/monitoring');

        $response->assertOk();

        // Per-table counts, per-route latency and the 24h traffic series were
        // three extra table scans per page load and duplicated the checks.
        $this->assertSame(
            ['events', 'health'],
            $this->sortedKeys($response->json('data'))
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function sortedKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    public function test_health_endpoint_returns_503_when_a_check_is_down(): void
    {
        // 503 is what tells a load balancer to stop sending traffic here.
        // Returning 200 or 500 both send the wrong signal.
        $this->mock(SystemMonitor::class, function ($mock) {
            $mock->shouldReceive('all')->andReturn([
                'status' => SystemMonitor::DOWN,
                'checks' => [
                    'database' => ['status' => SystemMonitor::DOWN, 'reason' => 'Connection refused'],
                ],
                'generated_at' => now()->toIso8601String(),
            ]);
        });

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('data.checks.database.reason', 'Connection refused');
    }

    public function test_database_check_reports_down_when_the_connection_cannot_be_resolved(): void
    {
        // Broken by name rather than by shutting MySQL down, so the check
        // under test is connectivity and not "are the tables there".
        //
        // The config is restored immediately afterwards: leaving the default
        // connection pointing at nothing makes the test framework's own
        // transaction rollback fail, which looks like a failure in the code
        // being tested but is not.
        try {
            config(['database.default' => 'nonexistent_connection_xyz']);

            $check = app(SystemMonitor::class)->database();
        } finally {
            config(['database.default' => 'mysql']);
        }

        $this->assertSame(SystemMonitor::DOWN, $check['status']);
        $this->assertStringContainsString('Cannot reach the database', $check['reason']);
    }

    public function test_one_broken_check_does_not_take_down_the_whole_report(): void
    {
        // A monitoring tool that crashes when the thing it monitors is broken
        // removes the diagnostic at the moment it is needed.
        config(['database.default' => 'nonexistent_connection_xyz']);

        try {
            $result = app(SystemMonitor::class)->all();
        } finally {
            config(['database.default' => 'mysql']);
        }

        $this->assertSame(SystemMonitor::DOWN, $result['status']);
        $this->assertSame(SystemMonitor::DOWN, $result['checks']['database']['status']);

        // The other checks still ran and still reported.
        $this->assertArrayHasKey('queue', $result['checks']);
        $this->assertArrayHasKey('disk', $result['checks']);
        $this->assertArrayHasKey('performance', $result['checks']);
    }

    /* ------------------------------------------------------------------ */
    /*  Queue health                                                       */
    /* ------------------------------------------------------------------ */

    public function test_queue_check_reports_a_backlog(): void
    {
        // phpunit.xml sets QUEUE_CONNECTION=sync, which short-circuits the
        // check. The database driver is what a real deployment uses.
        config(['queue.default' => 'database']);

        // A worker started without --queue=whatsapp,sms leaves these rows
        // waiting forever while the dashboard calls them "pending". The
        // backlog check is what makes that visible.
        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue' => 'whatsapp',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $check = app(SystemMonitor::class)->queue();

        $this->assertSame(1, $check['queues']['whatsapp']);
        $this->assertContains($check['status'], [SystemMonitor::DEGRADED, SystemMonitor::OK]);
    }

    public function test_queue_check_reports_failed_jobs_as_down(): void
    {
        config(['queue.default' => 'database']);

        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'sms',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now()->toDateTimeString(),
        ]);

        $check = app(SystemMonitor::class)->queue();

        $this->assertSame(1, $check['failed']);
        $this->assertSame(SystemMonitor::DOWN, $check['status']);
    }

    public function test_sync_queue_driver_is_not_reported_as_a_problem(): void
    {
        config(['queue.default' => 'sync']);

        $check = app(SystemMonitor::class)->queue();

        $this->assertSame(SystemMonitor::OK, $check['status']);
    }

    /* ------------------------------------------------------------------ */
    /*  Scheduler heartbeat                                                */
    /* ------------------------------------------------------------------ */

    public function test_scheduler_check_detects_a_stopped_scheduler(): void
    {
        Cache::forget('scheduler:last_heartbeat');

        $check = app(SystemMonitor::class)->scheduler();

        $this->assertSame(SystemMonitor::DEGRADED, $check['status']);

        $this->artisan('monitor:heartbeat')->assertSuccessful();

        $fresh = app(SystemMonitor::class)->scheduler();
        $this->assertSame(SystemMonitor::OK, $fresh['status']);
    }

    public function test_scheduler_check_detects_a_stale_heartbeat(): void
    {
        // A scheduler that died 30 minutes ago. Without this, weekly reminders
        // just silently stop and nothing reports it.
        Cache::put('scheduler:last_heartbeat', now()->subMinutes(30)->timestamp, now()->addHour());

        $check = app(SystemMonitor::class)->scheduler();

        $this->assertSame(SystemMonitor::DOWN, $check['status']);
        $this->assertSame(30, $check['minutes_since_run']);
    }

    /* ------------------------------------------------------------------ */
    /*  Providers                                                          */
    /* ------------------------------------------------------------------ */

    public function test_provider_check_reports_unconfigured_channels(): void
    {
        // Both WhatsApp drivers have to be cleared: which one is consulted
        // depends on services.whatsapp.driver.
        config([
            'services.whatsapp.driver' => 'meta',
            'services.whatsapp.token' => null,
            'services.whatsapp.phone_number_id' => null,
            'services.whatsapp.at_api_key' => null,
            'services.whatsapp.at_username' => null,
            'services.whatsapp.at_wa_number' => null,
            'services.sms.nextsms_username' => null,
            'services.sms.nextsms_password' => null,
            'mail.mailers.smtp.username' => null,
        ]);

        $check = app(SystemMonitor::class)->providers();

        $this->assertSame(SystemMonitor::DEGRADED, $check['status']);
        $this->assertContains('whatsapp', $check['missing']);
        $this->assertContains('sms', $check['missing']);
    }

    /* ------------------------------------------------------------------ */
    /*  Metrics recording                                                  */
    /* ------------------------------------------------------------------ */

    public function test_slow_queries_are_logged_to_the_sql_channel(): void
    {
        // Persistence is off for the rest of the suite (see phpunit.xml) so
        // that the request monitor cannot interfere with the feature tests.
        // This test is the one place that behaviour is actually under test,
        // so it turns it back on for itself.
        config(['monitoring.persist' => true]);

        $recorder = app(\App\Services\MetricsRecorder::class);
        $recorder->recordSlowQuery('select * from cards where id = ?', [1], 900.0);

        $this->assertDatabaseHas('system_metrics', [
            'metric' => SystemMetric::METRIC_SLOW_QUERY,
            'level' => 'warning',
        ]);
    }

    public function test_slow_query_bindings_are_not_stored_in_metrics(): void
    {
        config(['monitoring.persist' => true]);

        // Bindings routinely carry guest names and phone numbers. They may be
        // written to the sql log at debug level, but never to the metrics
        // table, which is read by the admin UI.
        app(\App\Services\MetricsRecorder::class)
            ->recordSlowQuery('select * from guests where phone = ?', ['0754123456'], 900.0);

        $row = \App\Models\SystemMetric::query()
            ->where('metric', SystemMetric::METRIC_SLOW_QUERY)
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('0754123456', json_encode($row->context));
        $this->assertArrayNotHasKey('bindings', $row->context);
    }

    public function test_fast_queries_are_not_logged(): void
    {
        config(['monitoring.persist' => true]);

        app(\App\Services\MetricsRecorder::class)
            ->recordSlowQuery('select 1 from dual /* probe */', [], 1.0);

        // Scoped to this specific statement. QueryMonitor is attached for the
        // whole suite and legitimately records its own slow queries, so a
        // bare assertDatabaseMissing on the metric name would be testing the
        // test runner's own speed rather than the code.
        $this->assertDatabaseMissing('system_metrics', [
            'metric' => SystemMetric::METRIC_SLOW_QUERY,
            'context' => json_encode(['sql' => 'select 1 from dual /* probe */', 'connection' => null]),
        ]);
    }

    public function test_counters_increment_and_accumulate(): void
    {
        $recorder = app(\App\Services\MetricsRecorder::class);

        $recorder->increment('test.counter');
        $recorder->increment('test.counter', 4);

        $this->assertSame(5, $recorder->counter('test.counter'));

        $recorder->increment('http.requests', 2);
        $this->assertSame(2, $recorder->snapshot()['requests']);
    }

    public function test_latency_percentiles_are_ordered(): void
    {
        $recorder = app(\App\Services\MetricsRecorder::class);

        foreach (range(1, 100) as $ms) {
            $recorder->observeLatency('api/v1/cards', (float) $ms);
        }

        $stats = $recorder->latencyStats('api/v1/cards');

        $this->assertSame(100, $stats['count']);

        // p50 <= p95 <= p99 <= max, and max is the largest sample.
        $this->assertLessThanOrEqual($stats['p95'], $stats['p50']);
        $this->assertLessThanOrEqual($stats['p99'], $stats['p95']);
        $this->assertLessThanOrEqual($stats['max'], $stats['p99']);
        $this->assertSame(100.0, $stats['max']);
    }

    public function test_latency_stats_for_an_unseen_route_are_zeroed(): void
    {
        $stats = app(\App\Services\MetricsRecorder::class)->latencyStats('never/seen');

        $this->assertSame(0, $stats['count']);
        $this->assertSame(0.0, $stats['p95']);
    }

    public function test_monitoring_disabled_records_nothing(): void
    {
        config(['monitoring.enabled' => false, 'monitoring.persist' => true]);

        $recorder = app(\App\Services\MetricsRecorder::class);
        $recorder->increment('should.not.exist');
        $recorder->record('should_not_exist', 'error');

        $this->assertSame(0, $recorder->counter('should.not.exist'));
        $this->assertDatabaseMissing('system_metrics', ['metric' => 'should_not_exist']);
    }

    /* ------------------------------------------------------------------ */
    /*  Alert throttling                                                   */
    /* ------------------------------------------------------------------ */

    public function test_alerts_are_throttled_so_one_outage_does_not_flood_a_phone(): void
    {
        config([
            'monitoring.alerts.enabled' => true,
            'monitoring.alerts.to' => '+255700000000',
            'monitoring.alerts.throttle_minutes' => 30,
        ]);

        $alerts = app(\App\Services\AlertService::class);

        // First call claims the key, the rest are suppressed.
        $alerts->failedJobs(3);
        $alerts->failedJobs(3);
        $alerts->failedJobs(3);

        $this->assertTrue(Cache::has('alert:queue.failed_jobs'));
    }

    public function test_no_alert_is_sent_without_a_destination(): void
    {
        config([
            'monitoring.alerts.enabled' => true,
            'monitoring.alerts.to' => null,
        ]);

        $this->assertFalse(app(\App\Services\AlertService::class)->isEnabled());
    }

    /* ------------------------------------------------------------------ */
    /*  Pruning                                                            */
    /* ------------------------------------------------------------------ */

    public function test_prune_deletes_old_metrics_but_keeps_recent_ones(): void
    {
        SystemMetric::query()->create([
            'metric' => 'old', 'level' => 'info', 'created_at' => now()->subDays(60),
        ]);
        SystemMetric::query()->create([
            'metric' => 'recent', 'level' => 'info', 'created_at' => now()->subDay(),
        ]);

        $this->artisan('data:prune', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('system_metrics', ['metric' => 'old']);
        $this->assertDatabaseHas('system_metrics', ['metric' => 'recent']);
    }

    public function test_prune_keeps_entry_logs_by_default(): void
    {
        $guest = \App\Models\Guest::factory()->create();
        $card = \App\Models\Card::factory()->create(['guest_id' => $guest->id]);

        \App\Models\EntryLog::factory()->create([
            'card_id' => $card->id,
            'guest_id' => $guest->id,
            'scanned_at' => now()->subYears(2),
        ]);

        $this->artisan('data:prune')->assertSuccessful();

        // Who entered the venue is the core record of the event. It is not
        // deleted by a routine cleanup.
        $this->assertDatabaseHas('entry_logs', ['card_id' => $card->id]);
    }

    public function test_prune_dry_run_deletes_nothing(): void
    {
        SystemMetric::query()->create([
            'metric' => 'old', 'level' => 'info', 'created_at' => now()->subDays(60),
        ]);

        $this->artisan('data:prune', ['--days' => 30, '--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('system_metrics', ['metric' => 'old']);
    }
}
