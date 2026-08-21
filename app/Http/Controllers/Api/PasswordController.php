<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Services\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class PasswordController extends Controller
{
    public function forgot(PasswordResetRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->input('email'))->first();

        if (! $user) {
            return response()->json([
                'success' => true,
                'message' => 'If this email exists in the system, a password reset link will be sent.',
            ]);
        }

        $token = Password::broker()->createToken($user);

        $resetUrl = url('/reset-password?token=' . $token . '&email=' . rawurlencode($user->email));

        try {
            Mail::raw(
                'Hello! Please follow this link to reset your password: ' . $resetUrl,
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('Reset Password');
                }
            );
        } catch (\Throwable $e) {
            return response()->json([
                'success' => true,
                'message' => 'The email could not be sent. Use the provided password reset link.',
                'data' => [
                    'reset_url' => $resetUrl,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'The password reset link has been sent to your email.',
        ]);
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
