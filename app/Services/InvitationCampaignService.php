<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Enums\InvitationChannel;
use App\Enums\InvitationStatus;
use App\Jobs\SendSmsInvitationJob;
use App\Jobs\SendWhatsAppInvitationJob;
use App\Models\Card;
use App\Models\EventDetail;
use App\Models\InvitationSend;
use Illuminate\Database\Eloquent\Builder;

class InvitationCampaignService
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly SmsService $sms,
    ) {
    }

    /**
     * Cards eligible to receive invitations: they have a guest and a valid
     * phone number, and have not been cancelled.
     */
    public function eligibleCards(): Builder
    {
        return Card::query()
            ->whereNotNull('guest_id')
            ->where('status', '!=', CardStatus::Cancelled->value)
            ->whereHas('guest', function (Builder $query) {
                $query->whereNotNull('phone_e164')
                    ->where('phone_e164', '!=', '')
                    ->whereRaw('LENGTH(phone_e164) >= 10');
            });
    }

    /**
     * Dispatches one WhatsApp job and one SMS job for every eligible card.
     * Already sent/delivered messages are never re-queued.
     */
    public function dispatchAll(): array
    {
        $cards = $this->eligibleCards()->with('guest')->get();

        $dispatched = 0;
        $skipped = 0;

        foreach ($cards as $card) {
            $phone = PhoneHelper::toE164($card->guest?->phone ?? '');

            if ($phone === null || strlen($phone) < 10) {
                $skipped++;
                continue;
            }

            foreach ([InvitationChannel::WhatsApp, InvitationChannel::Sms] as $channel) {
                $send = InvitationSend::query()
                    ->where('card_id', $card->id)
                    ->where('channel', $channel->value)
                    ->first();

                if ($send !== null && in_array($send->status->value, [
                    InvitationStatus::Sent->value,
                    InvitationStatus::Delivered->value,
                ], true)) {
                    continue;
                }

                if ($send?->status === InvitationStatus::Queued) {
                    continue;
                }

                InvitationSend::query()->updateOrCreate(
                    ['card_id' => $card->id, 'channel' => $channel->value],
                    [
                        'status' => InvitationStatus::Queued->value,
                        'phone_e164' => $phone,
                        'provider_message_id' => null,
                        'error' => null,
                        'queued_at' => now(),
                        'sent_at' => null,
                        'delivered_at' => null,
                    ],
                );

                $job = $channel === InvitationChannel::WhatsApp
                    ? new SendWhatsAppInvitationJob($card->id)
                    : new SendSmsInvitationJob($card->id);

                dispatch($job)->onQueue($channel->value);
            }

            $dispatched++;
        }

        return [
            'dispatched' => $dispatched,
            'skipped' => $skipped,
            'messages' => $dispatched * 2,
            'expected_messages' => $this->preview()['total_messages'],
        ];
    }

    public function sendWhatsApp(int $cardId): void
    {
        $card = Card::with('guest')->find($cardId);

        if ($card === null || $card->guest === null) {
            return;
        }

        $phone = PhoneHelper::toE164($card->guest->phone ?? '');

        if ($phone === null || strlen($phone) < 10) {
            $this->markFailed($card, InvitationChannel::WhatsApp, "The guest's phone number is invalid.");

            return;
        }

        $send = $this->sendRecord($card, InvitationChannel::WhatsApp, $phone);

        if ($this->alreadySent($send)) {
            return;
        }

        $cardUrl = $this->publicCardUrl($card);
        $message = $this->whatsappMessage($card, $cardUrl);

        $result = $this->whatsApp->sendText($phone, $message);

        if (($result['success'] ?? false) === true) {
            $send->forceFill([
                'status' => InvitationStatus::Sent->value,
                'provider_message_id' => $result['wa_message_id'] ?? null,
                'error' => null,
                'sent_at' => now(),
            ])->save();
        } else {
            $send->forceFill([
                'status' => InvitationStatus::Failed->value,
                'provider_message_id' => null,
                'error' => $result['message'] ?? 'Failed to send WhatsApp.',
            ])->save();
        }
    }

    public function sendSms(int $cardId): void
    {
        $card = Card::with('guest')->find($cardId);

        if ($card === null || $card->guest === null) {
            return;
        }

        $phone = PhoneHelper::toE164($card->guest->phone ?? '');

        if ($phone === null || strlen($phone) < 10) {
            $this->markFailed($card, InvitationChannel::Sms, "The guest's phone number is invalid.");

            return;
        }

        $send = $this->sendRecord($card, InvitationChannel::Sms, $phone);

        if ($this->alreadySent($send)) {
            return;
        }

        $cardUrl = $this->publicCardUrl($card);
        $message = $this->smsMessage($card, $cardUrl);

        $result = $this->sms->send($phone, $message);

        if (($result['success'] ?? false) === true) {
            $send->forceFill([
                'status' => InvitationStatus::Sent->value,
                'provider_message_id' => null,
                'error' => null,
                'sent_at' => now(),
            ])->save();
        } else {
            $send->forceFill([
                'status' => InvitationStatus::Failed->value,
                'provider_message_id' => null,
                'error' => $result['message'] ?? 'Failed to send SMS.',
            ])->save();
        }
    }

    public function report(): array
    {
        $guests = $this->eligibleCards()->count();

        $whatsapp = $this->channelStats(InvitationChannel::WhatsApp);
        $sms = $this->channelStats(InvitationChannel::Sms);

        return [
            'guests' => $guests,
            'total_messages' => $guests * 2,
            'total_sent' => $whatsapp['sent'] + $sms['sent'],
            'total_delivered' => $whatsapp['delivered'] + $sms['delivered'],
            'total_failed' => $whatsapp['failed'] + $sms['failed'],
            'total_pending' => $whatsapp['pending'] + $sms['pending'],
            'whatsapp' => $whatsapp,
            'sms' => $sms,
            'configured' => [
                'whatsapp' => $this->whatsApp->isConfigured(),
                'sms' => $this->sms->isConfigured(),
            ],
        ];
    }

    public function preview(): array
    {
        $guests = $this->eligibleCards()->count();

        return [
            'guests' => $guests,
            'whatsapp_messages' => $guests,
            'sms_messages' => $guests,
            'total_messages' => $guests * 2,
        ];
    }

    /**
     * Re-queues only the failed messages, optionally for a single channel.
     */
    public function retryFailed(?string $channel = null): array
    {
        $query = InvitationSend::query()
            ->where('status', InvitationStatus::Failed->value)
            ->with('card.guest');

        if ($channel !== null && in_array($channel, [
            InvitationChannel::WhatsApp->value,
            InvitationChannel::Sms->value,
        ], true)) {
            $query->where('channel', $channel);
        }

        $queued = 0;
        $skipped = 0;

        foreach ($query->get() as $send) {
            $card = $send->card;

            if ($card === null || $card->guest === null) {
                $skipped++;
                continue;
            }

            $phone = PhoneHelper::toE164($card->guest->phone ?? '');

            if ($phone === null || strlen($phone) < 10) {
                $send->forceFill(['error' => "The guest's phone number is invalid."])->save();
                $skipped++;
                continue;
            }

            $send->forceFill([
                'status' => InvitationStatus::Queued->value,
                'phone_e164' => $phone,
                'provider_message_id' => null,
                'error' => null,
                'queued_at' => now(),
                'sent_at' => null,
                'delivered_at' => null,
            ])->save();

            $job = $send->channel === InvitationChannel::WhatsApp
                ? new SendWhatsAppInvitationJob($card->id)
                : new SendSmsInvitationJob($card->id);

            dispatch($job)->onQueue($send->channel->value);

            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    public function whatsappMessage(Card $card, string $cardUrl): string
    {
        $name = $card->guest?->full_name ?? 'guest';
        $event = $this->eventName($card);
        $officialNumber = $this->eventDetail()->contact_number;

        $lines = [
            "Hello {$name} 😊",
            '',
            "Your invitation to {$event} is ready.",
            '',
            '🎫 Your E-Card:',
            $cardUrl,
            '',
        ];

        if ($date = $this->eventDate($card)) {
            $lines[] = "📅 Date: {$date}";
        }

        if ($time = $this->eventTime($card)) {
            $lines[] = "⏰ Time: {$time}";
        }

        if ($venue = $this->eventVenue($card)) {
            $lines[] = "📍 Venue: {$venue}";
        }

        $lines[] = '';
        $lines[] = "🎟 Card Number: {$card->card_number}";

        if ($officialNumber) {
            $lines[] = '';
            $lines[] = 'For more details, message us on WhatsApp:';
            $lines[] = $officialNumber;
        }

        return implode(PHP_EOL, $lines);
    }

    public function smsMessage(Card $card, string $cardUrl): string
    {
        $name = $card->guest?->full_name ?? 'guest';
        $event = $this->eventName($card);

        $parts = [
            "Hello {$name}.",
            "You're invited to {$event}!",
        ];

        $details = [];

        if ($date = $this->eventDate($card)) {
            $details[] = "Date: {$date}";
        }

        if ($time = $this->eventTime($card)) {
            $details[] = "Time: {$time}";
        }

        if ($venue = $this->eventVenue($card)) {
            $details[] = "Venue: {$venue}";
        }

        if ($details !== []) {
            $parts[] = implode(', ', $details) . '.';
        }

        $parts[] = "Card: {$card->card_number}";
        $parts[] = '';
        $parts[] = "View your E-Card (tap to open):";
        $parts[] = $cardUrl;
        $parts[] = '';
        $parts[] = 'Present this at the gate. Thank you!';

        return implode(' ', $parts);
    }

    public function dispatchSmsOnly(): array
    {
        $cards = $this->eligibleCards()->with('guest')->get();

        $dispatched = 0;
        $skipped = 0;

        foreach ($cards as $card) {
            $phone = PhoneHelper::toE164($card->guest?->phone ?? '');

            if ($phone === null || strlen($phone) < 10) {
                $skipped++;
                continue;
            }

            $channel = InvitationChannel::Sms;

            $send = InvitationSend::query()
                ->where('card_id', $card->id)
                ->where('channel', $channel->value)
                ->first();

            if ($send !== null && in_array($send->status->value, [
                InvitationStatus::Sent->value,
                InvitationStatus::Delivered->value,
            ], true)) {
                continue;
            }

            if ($send?->status === InvitationStatus::Queued) {
                continue;
            }

            InvitationSend::query()->updateOrCreate(
                ['card_id' => $card->id, 'channel' => $channel->value],
                [
                    'status' => InvitationStatus::Queued->value,
                    'phone_e164' => $phone,
                    'provider_message_id' => null,
                    'error' => null,
                    'queued_at' => now(),
                    'sent_at' => null,
                    'delivered_at' => null,
                ],
            );

            $job = new SendSmsInvitationJob($card->id);
            dispatch($job)->onQueue('sms');

            $dispatched++;
        }

        return [
            'dispatched' => $dispatched,
            'skipped' => $skipped,
        ];
    }

    public function dispatchSmsScheduled(string $scheduledAt): array
    {
        $cards = $this->eligibleCards()->with('guest')->get();

        $dispatched = 0;
        $skipped = 0;

        foreach ($cards as $card) {
            $phone = PhoneHelper::toE164($card->guest?->phone ?? '');

            if ($phone === null || strlen($phone) < 10) {
                $skipped++;
                continue;
            }

            $channel = InvitationChannel::Sms;

            $send = InvitationSend::query()
                ->where('card_id', $card->id)
                ->where('channel', $channel->value)
                ->first();

            if ($send !== null && in_array($send->status->value, [
                InvitationStatus::Sent->value,
                InvitationStatus::Delivered->value,
            ], true)) {
                continue;
            }

            InvitationSend::query()->updateOrCreate(
                ['card_id' => $card->id, 'channel' => $channel->value],
                [
                    'status' => InvitationStatus::Queued->value,
                    'phone_e164' => $phone,
                    'scheduled_at' => $scheduledAt,
                    'queued_at' => now(),
                ],
            );

            $dispatched++;
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    private function sendRecord(Card $card, InvitationChannel $channel, string $phone): InvitationSend
    {
        $send = InvitationSend::query()
            ->where('card_id', $card->id)
            ->where('channel', $channel->value)
            ->first();

        if ($send === null) {
            $send = InvitationSend::query()->create([
                'card_id' => $card->id,
                'channel' => $channel->value,
                'status' => InvitationStatus::Queued->value,
                'phone_e164' => $phone,
                'queued_at' => now(),
            ]);
        } else {
            $send->forceFill([
                'status' => InvitationStatus::Queued->value,
                'phone_e164' => $phone,
                'queued_at' => now(),
            ])->save();
        }

        return $send;
    }

    private function alreadySent(InvitationSend $send): bool
    {
        return in_array($send->status->value, [
            InvitationStatus::Sent->value,
            InvitationStatus::Delivered->value,
        ], true);
    }

    private function markFailed(Card $card, InvitationChannel $channel, string $error): void
    {
        $send = InvitationSend::query()->firstOrCreate(
            ['card_id' => $card->id, 'channel' => $channel->value],
            ['status' => InvitationStatus::Failed->value, 'queued_at' => now()],
        );

        $send->forceFill([
            'status' => InvitationStatus::Failed->value,
            'error' => $error,
        ])->save();
    }

    private function channelStats(InvitationChannel $channel): array
    {
        $query = InvitationSend::query()->where('channel', $channel->value);

        return [
            'sent' => (clone $query)->whereIn('status', [
                InvitationStatus::Sent->value,
                InvitationStatus::Delivered->value,
            ])->count(),
            'delivered' => (clone $query)->where('status', InvitationStatus::Delivered->value)->count(),
            'failed' => (clone $query)->where('status', InvitationStatus::Failed->value)->count(),
            'pending' => (clone $query)->where('status', InvitationStatus::Queued->value)->count(),
        ];
    }

    private function publicCardUrl(Card $card): string
    {
        return config('app.url') . '/e-card/' . $card->qr_token;
    }

    private function eventDetail(): EventDetail
    {
        return EventDetail::singleton();
    }

    private function eventName(Card $card): string
    {
        return $card->event_name ?: ($this->eventDetail()->event_name ?: 'the event');
    }

    private function eventDate(Card $card): ?string
    {
        if ($card->event_date) {
            return $card->event_date->format('d/m/Y');
        }

        return $this->eventDetail()->event_date?->format('d/m/Y');
    }

    private function eventTime(Card $card): ?string
    {
        return $card->event_time ?: $this->eventDetail()->event_time;
    }

    private function eventVenue(Card $card): ?string
    {
        return $card->venue ?: $this->eventDetail()->venue;
    }
}
