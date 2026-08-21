<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class TwoFactorController extends Controller
{
    private const VERIFY_TTL = 300; // seconds

    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $request->user()->twoFactorEnabled(),
            ],
        ]);
    }

    public function enable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            SecurityLogger::logRequest('2fa.enable.password_mismatch', $request, $user);

            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        if ($user->twoFactorEnabled()) {
            throw ValidationException::withMessages([
                'password' => ['Two-factor authentication is already enabled.'],
            ]);
        }

        $secret = $this->twoFactor->generateSecret();
        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = array_map(
            fn (string $code) => $this->twoFactor->hashRecoveryCode($code),
            $recoveryCodes,
        );
        $user->two_factor_confirmed_at = null;
        $user->save();

        $emailOtp = $this->twoFactor->generateEmailOtp();

        Cache::put('2fa_email_otp:'.$user->id, $emailOtp, now()->addMinutes(5));

        try {
            $this->twoFactor->sendEmailOtp($user, $emailOtp);
        } catch (\Throwable $e) {
            SecurityLogger::logRequest('2fa.enable.email_failed', $request, $user);
        }

        SecurityLogger::logRequest('2fa.enabled.pending_confirm', $request, $user);

        return response()->json([
            'success' => true,
            'message' => 'Scan the QR code (or use the email code) and enter a verification code to confirm.',
            'data' => [
                'secret' => $secret,
                'provisioning_uri' => $this->twoFactor->provisioningUri($user, $secret),
                'recovery_codes' => $recoveryCodes,
                'email_otp_sent' => true,
            ],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (empty($user->two_factor_secret)) {
            throw ValidationException::withMessages([
                'code' => ['Enable two-factor authentication first.'],
            ]);
        }

        if ($user->twoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication is already enabled.'],
            ]);
        }

        $emailOtp = Cache::get('2fa_email_otp:'.$user->id);

        $valid = $this->twoFactor->verify($user->two_factor_secret, $validated['code'])
            || ($emailOtp !== null && hash_equals($emailOtp, $validated['code']));

        if (! $valid) {
            SecurityLogger::logRequest('2fa.confirm.failed', $request, $user);

            throw ValidationException::withMessages([
                'code' => ['The verification code is incorrect.'],
            ]);
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        SecurityLogger::logRequest('2fa.confirmed', $request, $user);

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication is now active.',
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user->twoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication is not enabled.'],
            ]);
        }

        $code = $validated['code'];

        $valid = $this->twoFactor->verify($user->two_factor_secret, $code)
            || $this->twoFactor->verifyRecoveryCode($user, $code);

        if (! $valid) {
            SecurityLogger::logRequest('2fa.disable.failed', $request, $user);

            throw ValidationException::withMessages([
                'code' => ['The code is incorrect.'],
            ]);
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        SecurityLogger::logRequest('2fa.disabled', $request, $user);

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication has been disabled.',
        ]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user->twoFactorEnabled()) {
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication is not enabled.'],
            ]);
        }

        $code = $validated['code'];

        $valid = $this->twoFactor->verify($user->two_factor_secret, $code)
            || $this->twoFactor->verifyRecoveryCode($user, $code);

        if (! $valid) {
            SecurityLogger::logRequest('2fa.regenerate.failed', $request, $user);

            throw ValidationException::withMessages([
                'code' => ['The code is incorrect.'],
            ]);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();

        $user->two_factor_recovery_codes = array_map(
            fn (string $code) => $this->twoFactor->hashRecoveryCode($code),
            $recoveryCodes,
        );
        $user->save();

        SecurityLogger::logRequest('2fa.recovery_codes_regenerated', $request, $user);

        return response()->json([
            'success' => true,
            'message' => 'New recovery codes generated. Save them somewhere safe.',
            'data' => [
                'recovery_codes' => $recoveryCodes,
            ],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'two_factor_token' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $cacheKey = '2fa_token:'.hash('sha256', $validated['two_factor_token']);
        $userId = Cache::get($cacheKey);

        if (! $userId) {
            throw ValidationException::withMessages([
                'code' => ['This verification session has expired. Please sign in again.'],
            ]);
        }

        $user = User::query()->find($userId);

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['This verification session has expired. Please sign in again.'],
            ]);
        }

        if (RateLimiter::tooManyAttempts('2fa:'.$user->id, 5)) {
            $retryAfter = RateLimiter::availableIn('2fa:'.$user->id);

            throw new TooManyRequestsHttpException($retryAfter, 'Too many verification attempts. Please wait before trying again.');
        }

        RateLimiter::hit('2fa:'.$user->id);

        $code = $validated['code'];

        $emailOtp = Cache::get('2fa_email_otp:'.hash('sha256', $validated['two_factor_token']));

        $valid = $this->twoFactor->verify($user->two_factor_secret, $code)
            || ($emailOtp !== null && hash_equals($emailOtp, $code))
            || $this->twoFactor->verifyRecoveryCode($user, $code);

        if (! $valid) {
            SecurityLogger::logRequest('login.2fa.failed', $request, $user);

            throw ValidationException::withMessages([
                'code' => ['The verification code is incorrect.'],
            ]);
        }

        RateLimiter::clear('2fa:'.$user->id);
        Cache::forget($cacheKey);

        $token = $user->createToken('auth-token')->plainTextToken;

        SecurityLogger::logRequest('login.2fa.success', $request, $user);

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
}
