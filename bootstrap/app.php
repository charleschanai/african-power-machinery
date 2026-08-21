<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() && ! $e instanceof \Illuminate\Auth\AuthenticationException) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Hitilafu imetokea. Tafadhali jaribu tena.',
                    'error_code' => 'SERVER_ERROR',
                ], 500);
            }
        });
    })->create();
