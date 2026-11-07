<?php

namespace App\Services;

use App\Models\Card;

class CardInvitationService
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly SmsService $sms,
    ) {
    }

    public function message(Card $card): string
    {
        $cardUrl = $card->publicUrl();

        return $this->whatsApp->buildMessage($card, $cardUrl);
    }

    public function send(Card $card, string $channel): array
    {
        $card->loadMissing('guest');

        if (! $card->guest) {
            return [
                'success' => false,
                'message' => 'This card has no guest.',
            ];
        }

        if ($channel === 'sms') {
            return $this->sms->send($card->guest->phone, $this->message($card));
        }

        return $this->whatsApp->sendCard($card);
    }

    public function sendMany(array $cardIds, string $channel): array
    {
        $cards = Card::with('guest')->whereIn('id', $cardIds)->get();

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($cards as $card) {
            $result = $this->send($card, $channel);

            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = "{$card->card_number}: {$result['message']}";
            }
        }

        $channelLabel = $channel === 'sms' ? 'SMS' : 'WhatsApp';

        $message = "{$sent} invitation(s) sent via {$channelLabel}.";

        if ($failed > 0) {
            $message .= " {$failed} invitation(s) failed.";
        }

        return [
            'success' => $failed === 0,
            'message' => $message,
            'data' => [
                'sent' => $sent,
                'failed' => $failed,
                'errors' => $errors,
            ],
        ];
    }
}
