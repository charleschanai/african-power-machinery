<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Services\AlertService;
use App\Services\SecurityLogger;
use App\Services\SystemMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class PasswordController extends Controller
{
    /**
     * The single response this endpoint ever gives.
     *
     * Every path — unknown email, mail sent, mail failed — returns exactly
     * this. Distinguishable responses turn "forgot password" into a way to
     * enumerate which email addresses are admins.
     */
    private const NEUTRAL_RESPONSE = [
        'success' => true,
        'message' => 'If this email exists in the system, a password reset link has been sent.',
    ];

    public function forgot(PasswordResetRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if ($user) {
            $this->sendResetLink($user, $request);
        }

        return response()->json(self::NEUTRAL_RESPONSE);
    }

    /**
     * Best-effort delivery. A failure here is logged and alerted on, but it
     * is never reported to the caller.
     *
     * The reset token must never appear in a response body. This endpoint is
     * unauthenticated, so a returned token is an account takeover for anyone
     * who can make the mail server fail — and a broken SMTP block in .env is
     * the single easiest way to cause exactly that.
     */
    private function sendResetLink(User $user, PasswordResetRequest $request): void
    {
        try {
            $token = Password::broker()->createToken($user);

            $resetUrl = url('/reset-password?token='.$token.'&email='.rawurlencode($user->email));

            Mail::raw(
                'Hello! Please follow this link to reset your password: '.$resetUrl,
                function ($message) use ($user) {
                    $message->to($user->email)->subject('Reset Password');
                }
            );

            SecurityLogger::logRequest('password.reset_requested', $request, $user);
        } catch (\Throwable $e) {
            // Recorded, alerted on, and swallowed. The caller learns nothing.
            Log::channel('errorlog_daily')->error('Password reset email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            app(AlertService::class)->health(
                'password_reset_email',
                SystemMonitor::DOWN,
                ['reason' => $e->getMessage()],
            );
        }
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->password = $password;
                $user->save();

                $user->tokens()->delete();

                SecurityLogger::logRequest('password.reset', request(), $user);
            }
        );

        return match ($status) {
            Password::PASSWORD_RESET => response()->json([
                'success' => true,
                'message' => 'Your password has been changed. You can now log in with your new password.',
            ]),
            Password::INVALID_TOKEN => response()->json([
                'success' => false,
                'message' => 'The reset token is invalid or has expired. Please request a new one.',
                'error_code' => 'INVALID_RESET_TOKEN',
            ], 422),
            default => response()->json([
                'success' => false,
                'message' => 'This email is not registered in the system.',
                'error_code' => 'USER_NOT_FOUND',
            ], 422),
        };
    }
}
