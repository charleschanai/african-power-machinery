<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private const BASE_URL = 'https://api.nextsms.co.tz';

    public function isConfigured(): bool
    {
        return filled(config('services.sms.nextsms_username'))
            && filled(config('services.sms.nextsms_password'));
    }

    public function send(string $to, string $message): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'configured' => false,
                'message' => 'The SMS API is not configured. Set NEXTSMS_USERNAME and NEXTSMS_PASSWORD in .env.',
            ];
        }

        $phone = PhoneHelper::toE164($to);

        if ($phone === null || strlen($phone) < 10) {
            return [
                'success' => false,
                'configured' => true,
                'message' => 'The phone number is invalid.',
            ];
        }

        $username = config('services.sms.nextsms_username');
        $password = config('services.sms.nextsms_password');
        $senderId = config('services.sms.nextsms_sender_id', 'NEXTSMS');

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode("{$username}:{$password}"),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post(self::BASE_URL . '/api/sms/v1/text/single', [
                    'from' => $senderId,
                    'to' => $phone,
                    'text' => $message,
                ]);

            if ($response->failed()) {
                Log::error('NextSMS request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                $errorMsg = $response->json('error.message')
                    ?? $response->json('message')
                    ?? 'Failed to send the SMS. Please try again.';

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => $errorMsg,
                ];
            }

            $data = $response->json();

            if (isset($data['response'])) {
                $resp = $data['response'];
                if (isset($resp[0]['status']['statusCode']) && $resp[0]['status']['statusCode'] === 200) {
                    return [
                        'success' => true,
                        'configured' => true,
                        'message' => 'The SMS was sent successfully.',
                        'message_id' => $resp[0]['messageId'] ?? null,
                    ];
                }

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => $resp[0]['status']['description'] ?? 'SMS was not accepted by the provider.',
                ];
            }

            return [
                'success' => true,
                'configured' => true,
                'message' => 'The SMS was sent successfully.',
            ];
        } catch (\Throwable $e) {
            Log::error('NextSMS exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'configured' => true,
                'message' => 'Failed to connect to the SMS service. ' . $e->getMessage(),
            ];
        }
    }

    public function sendBulk(array $recipients, string $message): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'configured' => false,
                'message' => 'The SMS API is not configured.',
            ];
        }

        $username = config('services.sms.nextsms_username');
        $password = config('services.sms.nextsms_password');
        $senderId = config('services.sms.nextsms_sender_id', 'NEXTSMS');

        $messages = collect($recipients)->map(fn ($phone) => [
            'to' => $phone,
            'text' => $message,
        ])->values()->all();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode("{$username}:{$password}"),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(60)
                ->post(self::BASE_URL . '/api/sms/v1/text/multi', [
                    'from' => $senderId,
                    'messages' => $messages,
                ]);

            if ($response->failed()) {
                Log::error('NextSMS bulk request failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => 'Failed to send bulk SMS.',
                ];
            }

            return [
                'success' => true,
                'configured' => true,
                'message' => count($recipients) . ' SMS sent successfully.',
            ];
        } catch (\Throwable $e) {
            Log::error('NextSMS bulk exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'configured' => true,
                'message' => 'Failed to connect to the SMS service.',
            ];
        }
    }
}
