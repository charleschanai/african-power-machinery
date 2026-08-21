<?php

namespace App\Http\Controllers;

use App\Jobs\HandleWhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Services\WhatsAppWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * WhatsApp verification challenge (GET).
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = WhatsAppSetting::singleton()->webhook_verify_token
            ?: config('services.whatsapp.webhook_verify_token');

        if ($mode === 'subscribe'
            && $expected !== null
            && $token !== null
            && hash_equals((string) $expected, (string) $token)) {
            return response((string) $challenge, 200, ['Content-Type' => 'text/plain']);
        }

        return response('Verification failed', 403);
    }

    /**
     * Incoming WhatsApp message delivery (POST).
     */
    public function receive(Request $request): JsonResponse
    {
        $secret = config('services.whatsapp.app_secret');

        if (filled($secret) && ! $this->signatureIsValid($request, $secret)) {
            Log::warning('WhatsApp webhook signature verification failed');

            return response()->json(['status' => 'rejected'], 403);
        }

        $payload = $request->all();

        try {
            $job = new HandleWhatsAppMessage($payload);

            if (config('services.whatsapp.queue_async')) {
                dispatch($job);
            } else {
                $job->handle(app(WhatsAppWebhookService::class));
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook failed', ['error' => $e->getMessage()]);
        }

        // Always acknowledge so WhatsApp does not retry delivery.
        return response()->json(['status' => 'received']);
    }

    private function signatureIsValid(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (! is_string($signature)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
