<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\SecurityLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $limiterKey = $this->limiterKey($request);

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $retryAfter = RateLimiter::availableIn($limiterKey);

            SecurityLogger::logRequest('login.locked_out', $request);

            throw new TooManyRequestsHttpException($retryAfter, 'Login attempts exceeded. Please wait before trying again.');
        }

        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($limiterKey);

            SecurityLogger::logRequest('login.failed', $request);

            throw ValidationException::withMessages([
                'email' => ['The email or password is incorrect.'],
            ]);
        }

        RateLimiter::clear($limiterKey);

        if ($user->twoFactorEnabled()) {
            $twoFactorToken = Str::random(64);

            Cache::put(
                '2fa_token:'.hash('sha256', $twoFactorToken),
                $user->id,
                now()->addMinutes(5),
            );

            $otp = $this->twoFactor->generateEmailOtp();

            Cache::put(
                '2fa_email_otp:'.hash('sha256', $twoFactorToken),
                $otp,
                now()->addMinutes(5),
            );

            try {
                $this->twoFactor->sendEmailOtp($user, $otp);
            } catch (Throwable $e) {
                SecurityLogger::logRequest('login.two_factor_email_failed', $request, $user);
            }

            SecurityLogger::logRequest('login.two_factor_challenge', $request, $user);

            return response()->json([
                'success' => true,
                'message' => 'Two-factor authentication is required.',
                'two_factor_required' => true,
                'data' => [
                    'two_factor_token' => $twoFactorToken,
                    'two_factor_channel' => 'email_and_app',
                ],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        SecurityLogger::logRequest('login.success', $request, $user);

        return response()->json([
            'success' => true,
            'message' => 'You have logged in successfully.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'two_factor_enabled' => $user->twoFactorEnabled(),
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        SecurityLogger::logRequest('logout', $request, $user);

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'You have been logged out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $request->user(),
            ],
        ]);
    }

    private function limiterKey(Request $request): string
    {
        return strtolower((string) $request->input('email')).'|'.$request->ip();
    }
}
