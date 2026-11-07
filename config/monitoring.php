<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Set MONITORING_ENABLED=false to switch off every metric, slow-query
    | listener and request log at once. Useful when debugging a slow request
    | and you do not want the monitoring itself adding to the noise.
    |
    */

    'enabled' => env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | Anything slower than "slow_request_ms" is written to the performance log
    | and kept in the metrics table. Anything slower than
    | "slow_query_ms" is written to the sql log with its bindings.
    |
    */

    'slow_request_ms' => (int) env('MONITORING_SLOW_REQUEST_MS', 1000),
    'slow_query_ms' => (int) env('MONITORING_SLOW_QUERY_MS', 250),
    'query_budget_per_request' => (int) env('MONITORING_QUERY_BUDGET', 60),

    /*
    |--------------------------------------------------------------------------
    | What to keep
    |--------------------------------------------------------------------------
    |
    | "persist" controls whether a metric is written to the system_metrics
    | table. Aggregate counters are always kept in the cache regardless,
    | because the monitoring dashboard reads those and a dashboard should
    | never be able to fill the disk.
    |
    */

    'persist' => env('MONITORING_PERSIST', true),

    // Static assets and framework internals would drown out real traffic.
    'ignored_paths' => [
        'api/health',
        'up',
        'telescope*',
        'horizon*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Health checks
    |--------------------------------------------------------------------------
    |
    | Thresholds that flip a check from "ok" to "degraded" to "down".
    | Anything in the "down" band raises an alert.
    |
    */

    'thresholds' => [
        // Database.
        'db_connect_ms' => (int) env('MONITORING_DB_CONNECT_MS', 500),
        'db_slow_query_ratio' => (float) env('MONITORING_DB_SLOW_RATIO', 0.1),
        'db_size_mb' => (int) env('MONITORING_DB_SIZE_MB', 2048),

        // Application health.
        'error_rate' => (float) env('MONITORING_ERROR_RATE', 0.05),
        'failed_jobs' => (int) env('MONITORING_FAILED_JOBS', 0),
        'queue_backlog' => (int) env('MONITORING_QUEUE_BACKLOG', 100),

        // Storage.
        'disk_free_mb' => (int) env('MONITORING_DISK_FREE_MB', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting
    |--------------------------------------------------------------------------
    |
    | Alerts are delivered to the organizer over WhatsApp (or SMS) so a
    | problem is noticed without opening a dashboard. Delivery is rate
    | limited per key to stop a persistent failure from flooding a phone.
    |
    */

    'alerts' => [
        'enabled' => env('MONITORING_ALERTS', true),
        'channel' => env('MONITORING_ALERT_CHANNEL', 'whatsapp'),
        'to' => env('MONITORING_ALERT_TO'),
        // Minimum minutes between two alerts that share a key.
        'throttle_minutes' => (int) env('MONITORING_ALERT_THROTTLE', 30),
        // Levels that trigger a push. "warning" is deliberately not included:
        // a page should mean something is actually broken.
        'levels' => ['error', 'critical'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metric retention
    |--------------------------------------------------------------------------
    |
    | The metrics table is append-heavy. Old rows are pruned daily by the
    | scheduled data:prune command; these control how far back it keeps.
    |
    */

    'retention' => [
        'metrics_days' => (int) env('MONITORING_METRICS_DAYS', 30),
        'audit_days' => (int) env('MONITORING_AUDIT_DAYS', 180),
        'entry_logs_days' => (int) env('MONITORING_ENTRY_LOGS_DAYS', 0), // 0 = keep forever
        'whatsapp_messages_days' => (int) env('MONITORING_WA_MESSAGES_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | External provider timeouts
    |--------------------------------------------------------------------------
    |
    | Used by the monitoring layer to decide whether a slow provider call
    | should raise a warning.
    |
    */

    'provider_timeout_ms' => (int) env('MONITORING_PROVIDER_TIMEOUT_MS', 10000),

];
