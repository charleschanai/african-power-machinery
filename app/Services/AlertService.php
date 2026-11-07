<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers health alerts to the organizer.
 *
 * The design rule here is "no alert storms". Every alert is keyed, and a key
 * that has already fired inside the throttle window is dropped. Without that,
 * a database that is down for ten minutes produces a few hundred WhatsApp
 * messages and makes a bad afternoon far worse.
 */
class AlertService
{
    /** @var array<string, true> alerts fired during this process, to avoid re-sending. */
    private array $sentThisRun = [];

    public function isEnabled(): bool
    {
        return (bool) config('monitoring.alerts.enabled', true)
            && filled(config('monitoring.alerts.to'));
    }

    /**
     * Report a failed health check.
     *
     * @param  array<string, mixed>  $context
     */
    public function health(string $check, string $status, array $context = []): void
    {
        if (! in_array($status, config('monitoring.alerts.levels', ['error', 'critical']), true)) {
            $this->log($check, $status, $context);

            return;
        }

        $this->send("health.{$check}.{$status}", $this->formatHealth($check, $status, $context), $context);
    }

    /**
     * Report an exception. Only escalated errors are pushed out; the rest
     * stay in the log so the organizer is not buried in noise.
     */
    public function error(Throwable $e, array $context = []): void
    {
        if (app()->isLocal() && ! app()->runningUnitTests()) {
            return;
        }

        $this->send(
            'error.'.\Illuminate\Support\Str::slug($e::class).'.'.substr(hash('sha256', $e->getFile().$e->getLine()), 0, 8),
            "System error:\n\n".$e::class."\n".
            \Illuminate\Support\Str::limit($e->getMessage(), 200)."\n".
            \Illuminate\Support\Str::afterLast($e->getFile(), '/').':'.$e->getLine(),
            $context,
        );
    }

    /**
     * Queue backlog grew past its threshold.
     */
    public function queueBacklog(string $queue, int $size, int $threshold): void
    {
        $this->send(
            "queue.{$queue}.backlog",
            "Queue warning:\n\nQueue '{$queue}' has {$size} waiting jobs (limit {$threshold}).".
            "\nA worker may not be running.",
            ['queue' => $queue, 'size' => $size],
        );
    }

    /**
     * Jobs are sitting in failed_jobs.
     */
    public function failedJobs(int $count): void
    {
        if ($count < 1) {
            return;
        }

        $this->send(
            'queue.failed_jobs',
            "Queue warning:\n\n{$count} job(s) have failed and need attention."
            ."\nRun: php artisan queue:failed",
            ['count' => $count],
        );
    }

    /**
     * Disk is filling up.
     */
    public function diskSpace(string $path, float $freeMb, float $thresholdMb): void
    {
        $this->send(
            'disk.free_space',
            sprintf(
                'Storage warning:\n\nOnly %s MB free (limit %s MB) on %s.',
                number_format($freeMb, 0),
                number_format($thresholdMb, 0),
                $path,
            ),
            ['path' => $path],
        );
    }

    public function databaseSize(float $sizeMb, float $thresholdMb): void
    {
        $this->send(
            'db.size',
            sprintf(
                'Database warning:\n\nDatabase is %s MB (limit %s MB).'
                ."\nConsider archiving old entry logs.",
                number_format($sizeMb, 0),
                number_format($thresholdMb, 0),
            ),
        );
    }

    public function slowQueryRate(float $ratio, float $threshold): void
    {
        if ($ratio < $threshold) {
            return;
        }

        $this->send(
            'db.slow_ratio',
            sprintf(
                'Performance warning:\n\n%s%% of database queries are slow (limit %s%%).'
                ."\nSee storage/logs/sql.log",
                number_format($ratio * 100, 1),
                number_format($threshold * 100, 1),
            ),
            ['ratio' => $ratio],
        );
    }

    /**
     * Queue worker stopped for too long. Detected by the scheduled check
     * comparing the last-seen heartbeat against now.
     */
    public function queueStalled(string $queue, int $minutes): void
    {
        $this->send(
            "queue.{$queue}.stalled",
            "Queue warning:\n\nNo job has run on '{$queue}' for {$minutes} minutes."
            ."\nCheck that a worker is running.",
            ['queue' => $queue, 'minutes' => $minutes],
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Delivery                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $context
     */
    private function send(string $key, string $message, array $context = []): void
    {
        $this->log($key, 'sent', $context);

        if (! $this->shouldSend($key)) {
            return;
        }

        try {
            $channel = (string) config('monitoring.alerts.channel', 'whatsapp');
            $to = (string) config('monitoring.alerts.to');

            $result = match ($channel) {
                'sms' => app(SmsService::class)->send($to, $this->plainText($message)),
                'whatsapp' => app(WhatsAppService::class)->sendText($to, $message),
                default => ['success' => false, 'message' => "Unknown alert channel '{$channel}'."],
            };

            if (($result['success'] ?? false) !== true) {
                // Alerts are best-effort. A broken provider must not stop the
                // rest of the health check from running.
                Log::channel('monitoring')->warning('Alert could not be delivered', [
                    'key' => $key,
                    'reason' => $result['message'] ?? 'unknown',
                ]);
            }
        } catch (Throwable $e) {
            Log::channel('monitoring')->error('Alert threw: '.$e->getMessage(), ['key' => $key]);
        }
    }

    /**
     * Rate limit per key, using the cache so the limit holds across workers.
     */
    private function shouldSend(string $key): bool
    {
        if (isset($this->sentThisRun[$key])) {
            return false;
        }

        $this->sentThisRun[$key] = true;

        if (! $this->isEnabled()) {
            return false;
        }

        $cacheKey = 'alert:'.$key;
        $minutes = (int) config('monitoring.alerts.throttle_minutes', 30);

        if ($minutes > 0 && Cache::has($cacheKey)) {
            return false;
        }

        try {
            if ($minutes > 0) {
                Cache::put($cacheKey, now()->timestamp, now()->addMinutes($minutes));
            }
        } catch (Throwable) {
            // If the cache is down we would rather send a duplicate alert
            // than swallow a real outage notification.
        }

        return true;
    }

    /** @param array<string, mixed> $context */
    private function log(string $check, string $status, array $context): void
    {
        Log::channel('monitoring')->info("Health check [{$check}]: {$status}", $context);
    }

    /** @param array<string, mixed> $context */
    private function formatHealth(string $check, string $status, array $context): string
    {
        $readable = str_replace(['_', '.'], [' ', ' / '], $check);
        $icon = $status === 'critical' ? '🛑' : '⚠️';

        $lines = ["{$icon} System alert: ".ucfirst($readable), '', "Status: {$status}"];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $lines[] = ucfirst(str_replace('_', ' ', (string) $key)).': '.
                    \Illuminate\Support\Str::limit((string) $value, 200);
            }
        }

        return implode("\n", $lines);
    }

    /** WhatsApp markdown is stripped for SMS, which is plain text. */
    private function plainText(string $message): string
    {
        return preg_replace('/[*_`]/', '', $message) ?? $message;
    }
}
