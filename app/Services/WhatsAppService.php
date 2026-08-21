<?php

namespace App\Services;

use App\Models\Card;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function driver(): string
    {
        return config('services.whatsapp.driver', 'meta');
    }

    public function isConfigured(): bool
    {
        if ($this->driver() === 'africas_talking') {
            return filled(config('services.whatsapp.at_api_key'))
                && filled(config('services.whatsapp.at_username'))
                && filled(config('services.whatsapp.at_wa_number'));
        }

        return filled(config('services.whatsapp.token'))
            && filled(config('services.whatsapp.phone_number_id'));
    }

    public function graphUrl(): string
    {
        return 'https://graph.facebook.com/'
            . config('services.whatsapp.graph_api_version', 'v21.0')
            . '/'
            . config('services.whatsapp.phone_number_id');
    }

    public function graphMessagesUrl(): string
    {
        return $this->graphUrl() . '/messages';
    }

    /**
     * Send a plain text message through the configured WhatsApp driver.
     */
    public function sendText(string $to, string $body, bool $previewUrl = true): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'configured' => false,
                'message' => $this->notConfiguredMessage(),
            ];
        }

        if ($this->driver() === 'africas_talking') {
            return $this->sendTextAt($to, $body);
        }

        return $this->sendTextMeta($to, $body, $previewUrl);
    }

    /**
     * Send an interactive menu with up to three quick-reply buttons.
     *
     * Each button may be a plain string label or an array with
     * ["id" => "card", "title" => "My E-Card"].
     */
    public function sendMenu(string $to, string $body, array $buttons): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'configured' => false,
                'message' => $this->notConfiguredMessage(),
            ];
        }

        $rows = $this->buildButtonRows($buttons);

        if (count($rows) === 0) {
            return $this->sendText($to, $body);
        }

        if ($this->driver() === 'africas_talking') {
            return $this->sendMenuAt($to, $body, $rows);
        }

        return $this->sendMenuMeta($to, $body, $rows);
    }

    /**
     * Build a wa.me deep link for a card so a message can still be sent even
     * when the WhatsApp API is not configured (useful during development).
     */
    public function waLink(Card $card): string
    {
        $phone = preg_replace('/\D+/', '', $card->guest?->phone ?? '');
        $url = config('app.url') . '/e-card/' . $card->qr_token;

        return "https://wa.me/{$phone}?text=" . rawurlencode($this->buildMessage($card, $url));
    }

    public function buildMessage(Card $card, string $cardUrl): string
    {
        $name = $card->guest?->full_name ?? 'guest';

        $lines = [
            "Hello {$name},",
            '',
            'You have been invited to the event.',
        ];

        if ($card->event_name) {
            $lines[] = '';
            $lines[] = "Event: {$card->event_name}";
        }

        if ($card->event_date) {
            $lines[] = 'Date: ' . $card->event_date->format('d/m/Y');
        }

        if ($card->event_time) {
            $lines[] = "Time: {$card->event_time}";
        }

        if ($card->venue) {
            $lines[] = "Venue: {$card->venue}";
        }

        if ($card->table_number) {
            $lines[] = "Table: {$card->table_number}";
        }

        if ($card->seat_number) {
            $lines[] = "Seat: {$card->seat_number}";
        }

        $lines[] = '';
        $lines[] = "Card type: {$card->card_type->label()}";
        $lines[] = "Card number: {$card->card_number}";
        $lines[] = "Allowed people: {$card->allowed_people}";
        $lines[] = '';
        $lines[] = 'Open your E-Card using this link and present it at the gate:';
        $lines[] = '';
        $lines[] = $cardUrl;

        if ($card->special_message) {
            $lines[] = '';
            $lines[] = $card->special_message;
        }

        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Send the E-Card via the configured WhatsApp driver. Returns an array
     * describing the outcome. If the API is not configured, the caller can
     * fall back to the wa.me deep link.
     */
    public function sendCard(Card $card): array
    {
        $card->loadMissing('guest');

        $cardUrl = url('/e-card/' . $card->qr_token);
        $message = $this->buildMessage($card, $cardUrl);

        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'configured' => false,
                'message' => 'The WhatsApp API is not configured. Use the WhatsApp link.',
                'wa_link' => $this->waLink($card),
                'card_url' => $cardUrl,
            ];
        }

        $phone = preg_replace('/\D+/', '', $card->guest?->phone ?? '');

        if (strlen($phone) < 10) {
            return [
                'success' => false,
                'configured' => true,
                'message' => 'The guest\'s phone number is invalid.',
                'wa_link' => $this->waLink($card),
                'card_url' => $cardUrl,
            ];
        }

        $result = $this->sendText($phone, $message);

        return [
            'success' => $result['success'],
            'configured' => true,
            'message' => $result['message'],
            'wa_link' => $this->waLink($card),
            'card_url' => $cardUrl,
        ];
    }

    private function notConfiguredMessage(): string
    {
        if ($this->driver() === 'africas_talking') {
            return 'The WhatsApp API is not configured. Set AT_WHATSAPP_USERNAME, AT_WHATSAPP_API_KEY and AT_WHATSAPP_WA_NUMBER.';
        }

        return 'The WhatsApp API is not configured. Set WHATSAPP_API_TOKEN and WHATSAPP_PHONE_NUMBER_ID.';
    }

    private function sendTextMeta(string $to, string $body, bool $previewUrl): array
    {
        $to = preg_replace('/\D+/', '', $to);

        if (strlen($to) < 10) {
            return [
                'success' => false,
                'configured' => true,
                'message' => 'The phone number is invalid.',
            ];
        }

        try {
            $response = Http::withToken(config('services.whatsapp.token'))
                ->timeout(30)
                ->post($this->graphMessagesUrl(), [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['preview_url' => $previewUrl, 'body' => $body],
                ]);

            if ($response->failed()) {
                Log::error('WhatsApp API failed', ['response' => $response->body()]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => 'Failed to send the WhatsApp message. Please try again.',
                ];
            }

            return [
                'success' => true,
                'configured' => true,
                'message' => 'The message was sent via WhatsApp.',
                'wa_message_id' => data_get($response->json('messages'), '0.id'),
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsApp API exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'configured' => true,
                'message' => 'Failed to use the WhatsApp API.',
            ];
        }
    }

    private function sendMenuMeta(string $to, string $body, array $rows): array
    {
        $to = preg_replace('/\D+/', '', $to);

        if (strlen($to) < 10) {
            return [
                'success' => false,
                'configured' => true,
                'message' => 'The phone number is invalid.',
            ];
        }

        try {
            $response = Http::withToken(config('services.whatsapp.token'))
                ->timeout(30)
                ->post($this->graphMessagesUrl(), [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'interactive',
                    'interactive' => [
                        'type' => 'button',
                        'body' => ['text' => mb_substr($body, 0, 1024)],
                        'action' => ['buttons' => $rows],
                    ],
                ]);

            if ($response->failed()) {
                Log::error('WhatsApp menu API failed', ['response' => $response->body()]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => 'Failed to send the WhatsApp menu.',
                ];
            }

            return [
                'success' => true,
                'configured' => true,
                'message' => 'The menu was sent via WhatsApp.',
                'wa_message_id' => data_get($response->json('messages'), '0.id'),
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsApp menu API exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'configured' => true,
                'message' => 'Failed to use the WhatsApp API.',
            ];
        }
    }

    private function sendTextAt(string $to, string $body): array
    {
        $phone = PhoneHelper::toE164($to);

        if ($phone === null || strlen($phone) < 10) {
            return [
                'success' => false,
                'configured' => true,
                'message' => 'The phone number is invalid.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'apikey' => config('services.whatsapp.at_api_key'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post(config('services.whatsapp.at_base_url'), [
                    'username' => config('services.whatsapp.at_username'),
                    'waNumber' => PhoneHelper::toDisplay(config('services.whatsapp.at_wa_number')),
                    'phoneNumber' => '+' . $phone,
                    'body' => ['message' => $body],
                ]);

            if ($response->failed()) {
                Log::error('Africa\'s Talking WhatsApp failed', [
                    'response' => $response->body(),
                    'status' => $response->status(),
                ]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => 'Failed to send the WhatsApp message. Please try again.',
                ];
            }

            $status = strtoupper((string) $response->json('status', ''));
            $messageId = $response->json('messageId');

            if ($status === 'FAILED' || $messageId === null) {
                Log::warning('Africa\'s Talking WhatsApp not accepted', ['response' => $response->json()]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => 'Failed to send the WhatsApp message. The service returned status: ' . ($status ?: 'unknown') . '.',
                ];
            }

            return [
                'success' => true,
                'configured' => true,
                'message' => 'The message was sent via WhatsApp.',
                'wa_message_id' => $messageId,
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            Log::error('Africa\'s Talking WhatsApp exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'configured' => true,
                'message' => 'Failed to use the WhatsApp API.',
            ];
        }
    }

    private function sendMenuAt(string $to, string $body, array $rows): array
    {
        $phone = PhoneHelper::toE164($to);

        if ($phone === null || strlen($phone) < 10) {
            return [
                'success' => false,
                'configured' => true,
                'message' => 'The phone number is invalid.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'apikey' => config('services.whatsapp.at_api_key'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post(config('services.whatsapp.at_base_url'), [
                    'username' => config('services.whatsapp.at_username'),
                    'waNumber' => PhoneHelper::toDisplay(config('services.whatsapp.at_wa_number')),
                    'phoneNumber' => '+' . $phone,
                    'body' => [
                        'action' => ['buttons' => $rows],
                        'body' => ['text' => mb_substr($body, 0, 1024)],
                    ],
                ]);

            if ($response->failed()) {
                Log::error('Africa\'s Talking WhatsApp menu failed', ['response' => $response->body()]);

                return [
                    'success' => false,
                    'configured' => true,
                    'message' => 'Failed to send the WhatsApp menu.',
                ];
            }

            $status = strtoupper((string) $response->json('status', ''));
            $messageId = $response->json('messageId');

            if ($status === 'FAILED' || $messageId === null) {
                return [
                    'success' => false,
                    'configured' => true,
                    'message' => 'Failed to send the WhatsApp menu. The service returned status: ' . ($status ?: 'unknown') . '.',
                ];
            }

            return [
                'success' => true,
                'configured' => true,
                'message' => 'The menu was sent via WhatsApp.',
                'wa_message_id' => $messageId,
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            Log::error('Africa\'s Talking WhatsApp menu exception', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'configured' => true,
                'message' => 'Failed to use the WhatsApp API.',
            ];
        }
    }

    private function buildButtonRows(array $buttons): array
    {
        return collect($buttons)
            ->take(3)
            ->values()
            ->map(fn ($button, int $index) => [
                'id' => is_array($button) ? ($button['id'] ?? 'menu_' . $index) : 'menu_' . $index . '_' . md5($button),
                'title' => mb_substr(is_array($button) ? ($button['title'] ?? '') : $button, 0, 20),
            ])
            ->filter(fn ($row) => $row['title'] !== '')
            ->values()
            ->all();
    }
}
