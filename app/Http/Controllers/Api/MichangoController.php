<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecurityLogger;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MichangoController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:1000',
            'numbers' => 'required|array|min:1|max:500',
            'numbers.*' => 'required|string|max:20',
        ]);

        $sms = new SmsService();

        if (! $sms->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'SMS is not configured. Set NEXTSMS credentials in .env.',
            ], 422);
        }

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach (array_unique($validated['numbers']) as $number) {
            $result = $sms->send(trim($number), $validated['message']);

            if ($result['success'] ?? false) {
                $sent++;
            } else {
                $failed++;
                $errors[] = ['phone' => $number, 'error' => $result['message'] ?? 'Failed'];
            }
        }

        SecurityLogger::logRequest('michango.send', $request, $request->user(), [
            'total' => count($validated['numbers']),
            'sent' => $sent,
            'failed' => $failed,
        ]);

        return response()->json([
            'success' => $sent > 0,
            'message' => "{$sent} SMS sent; {$failed} failed.",
            'data' => [
                'sent' => $sent,
                'failed' => $failed,
                'errors' => $errors,
            ],
        ]);
    }
}
