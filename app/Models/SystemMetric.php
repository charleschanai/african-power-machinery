<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemMetric extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'system_metrics';

    protected $fillable = [
        'metric',
        'level',
        'method',
        'route',
        'status_code',
        'duration_ms',
        'memory_bytes',
        'user_id',
        'ip_address',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'memory_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSince(Builder $query, ?\DateTimeInterface $moment): Builder
    {
        return $moment ? $query->where('created_at', '>=', $moment) : $query;
    }

    public function scopeMetric(Builder $query, ?string $metric): Builder
    {
        return $metric ? $query->where('metric', $metric) : $query;
    }

    public function scopeAtLeast(Builder $query, string $level): Builder
    {
        $order = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5];

        $wanted = $order[$level] ?? 1;

        $names = collect($order)
            ->filter(fn ($weight) => $weight >= $wanted)
            ->keys()
            ->all();

        return $query->whereIn('level', $names);
    }

    /** Metric names used across the app, kept in one place. */
    public const METRIC_REQUEST = 'http_request';
    public const METRIC_SLOW_QUERY = 'slow_query';
    public const METRIC_ERROR = 'error';
    public const METRIC_QUEUE = 'queue_job';
    public const METRIC_EXTERNAL = 'external_call';
    public const METRIC_JOB_FAILED = 'job_failed';
    public const METRIC_LOGIN = 'auth_login';
}
