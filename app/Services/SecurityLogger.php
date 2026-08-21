<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SecurityLogger
{
    public static function log(string $event, ?User $user = null, ?string $email = null, array $metadata = []): void
    {
        try {
            $request = request();

            AuditLog::query()->create([
                'user_id' => $user?->id,
                'email' => $email ?? $user?->email,
                'ip_address' => self::clientIp($request),
                'user_agent' => self::userAgent($request),
                'event' => $event,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to write audit log for event [{$event}]: {$e->getMessage()}");
        }
    }

    public static function logRequest(string $event, Request $request, ?User $user = null, array $metadata = []): void
    {
        self::log(
            $event,
            $user,
            $user?->email ?? $request->input('email'),
            array_merge($metadata, ['method' => $request->method(), 'path' => $request->path()]),
        );
    }

    private static function clientIp(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        return $request->ip();
    }

    private static function userAgent(?Request $request): ?string
    {
        if (! $request || ! $request->headers->has('User-Agent')) {
            return null;
        }

        $agent = $request->header('User-Agent');

        return is_string($agent) ? substr($agent, 0, 500) : null;
    }
}
