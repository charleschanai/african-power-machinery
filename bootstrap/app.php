<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException as ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        $middleware->appendToGroup('web', [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->appendToGroup('api', [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        // Performance monitoring. Appended (not prepended) so it wraps the
        // real work and can measure it, and so a failure inside monitoring
        // cannot stop the response from being produced.
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\RequestMonitor::class,
        ]);

        $middleware->appendToGroup('api', [
            \App\Http\Middleware\RequestMonitor::class,
        ]);

        /*
         * TLS is normally terminated by nginx, Cloudflare or a load balancer,
         * so $request->isSecure() is false without this. That in turn breaks
         * the HTTPS redirect, silently disables HSTS, and makes Meta's
         * webhook POST come back as a 302 — which a webhook client follows
         * with GET, discarding the body, so the HMAC signature check then
         * hashes nothing and the WhatsApp integration dies silently.
         */
        $middleware->trustProxies(
            at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', '*'))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        if ($hosts = array_filter(explode(',', (string) env('TRUSTED_HOSTS', '')))) {
            $middleware->trustHosts(at: $hosts);
        }

        $middleware->prependToGroup('web', [
            \App\Http\Middleware\ForceHttps::class,
        ]);

        $middleware->prependToGroup('api', [
            \App\Http\Middleware\ForceHttps::class,
        ]);

        $middleware->validateCsrfTokens(except: ['whatsapp/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Haujaruhusiwa. Tafadhali ingia.',
                    'error_code' => 'UNAUTHENTICATED',
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Taarifa zilizowekwa si sahihi.',
                    'error_code' => 'VALIDATION_ERROR',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kitu unachotafuta hakipo.',
                    'error_code' => 'NOT_FOUND',
                ], 404);
            }
        });

        /*
         * Rate limiting.
         *
         * This used to fall through to the catch-all below and come back as
         * a 500 with no Retry-After header, which meant a throttled client
         * had no way to back off and the monitoring dashboard counted every
         * rate limit as a server fault. Laravel throws
         * ThrottleRequestsException from the ThrottleRequests middleware and
         * TooManyRequestsHttpException when a controller rejects manually;
         * both are handled here.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Majaribio mengi sana. Tafadhali subiri kidogo kisha jaribu tena.',
                    'error_code' => 'RATE_LIMITED',
                    'retry_after' => $e->getHeaders()['Retry-After'] ?? 60,
                ], 429, $e->getHeaders());
            }
        });

        // A policy denial must read as 403, not as an unexplained 500.
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Huruhusiwi kufanya kitendo hiki.',
                    'error_code' => 'FORBIDDEN',
                ], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kitu unachotafuta hakipo.',
                    'error_code' => 'NOT_FOUND',
                ], 404);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() && ! $e instanceof \Illuminate\Auth\AuthenticationException) {
                // Record the failure before responding so a 5xx is visible in
                // the monitoring screen even though the client is told nothing
                // about the internals.
                app(\App\Services\MetricsRecorder::class)->recordError($e, [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'user_id' => $request->user()?->id,
                ]);

                return new JsonResponse([
                    'success' => false,
                    'message' => 'Hitilafu imetokea. Tafadhali jaribu tena.',
                    'error_code' => 'SERVER_ERROR',
                ], 500);
            }
        });
    })->create();
