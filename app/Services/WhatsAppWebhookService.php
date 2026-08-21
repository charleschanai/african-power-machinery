<?php

namespace App\Services;

use App\Enums\WhatsAppConversationStatus;
use App\Enums\WhatsAppDirection;
use App\Enums\WhatsAppSenderType;
use App\Enums\InvitationChannel;
use App\Enums\InvitationStatus;
use App\Models\Guest;
use App\Models\InvitationSend;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly EventAssistant $assistant,
    ) {
    }

    /**
     * Handle a full webhook payload (may contain several conversations).
     */
    public function handle(array $payload): void
    {
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $message) {
                    try {
                        $this->handleMessage($value, $message);
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp message handling failed', [
                            'error' => $e->getMessage(),
                            'message_id' => $message['id'] ?? null,
                        ]);
                    }
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    try {
                        $this->handleStatus($status);
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp status handling failed', [
                            'error' => $e->getMessage(),
                            'message_id' => $status['id'] ?? null,
                        ]);
                    }
                }
            }
        }
    }

    private function handleMessage(array $value, array $message): void
    {
        $from = preg_replace('/\D+/', '', (string) ($message['from'] ?? ''));

        if ($from === '') {
            return;
        }

        $waId = $message['id'] ?? null;

        if ($waId !== null && WhatsAppMessage::query()
            ->where('whatsapp_message_id', $waId)
            ->where('direction', WhatsAppDirection::Incoming->value)
            ->exists()) {
            return;
        }

        $conversation = $this->findOrCreateConversation($from, $value);

        $type = $message['type'] ?? 'text';
        $body = $this->messageBody($message, $type);

        $stored = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $waId,
            'direction' => WhatsAppDirection::Incoming->value,
            'sender_type' => WhatsAppSenderType::Guest->value,
            'message_type' => $type,
            'body' => $body,
            'metadata' => ['raw_type' => $type, 'raw' => $message[$type] ?? null],
        ]);

        $conversation->update(['last_message_at' => now()]);

        $this->maybeRespond($conversation, $stored);
    }

    private function handleStatus(array $status): void
    {
        $statusName = (string) ($status['status'] ?? '');
        $messageId = (string) ($status['id'] ?? '');

        if ($messageId === '' || ! in_array($statusName, ['delivered', 'read'], true)) {
            return;
        }

        InvitationSend::query()
            ->where('provider_message_id', $messageId)
            ->where('channel', InvitationChannel::WhatsApp->value)
            ->where('status', '!=', InvitationStatus::Delivered->value)
            ->update([
                'status' => InvitationStatus::Delivered->value,
                'delivered_at' => now(),
            ]);
    }

    private function findOrCreateConversation(string $from, array $value): WhatsAppConversation
    {
        $profileName = data_get($value, 'contacts.0.profile.name');

        $conversation = WhatsAppConversation::query()
            ->where('phone', $from)
            ->first();

        if ($conversation !== null) {
            if ($conversation->guest_id === null) {
                $this->attachGuest($conversation, $from);
            }

            if ($conversation->guest_name === null && $profileName) {
                $conversation->update(['guest_name' => $profileName]);
            }

            return $conversation;
        }

        $conversation = WhatsAppConversation::query()->create([
            'phone' => $from,
            'guest_name' => $profileName,
            'status' => WhatsAppConversationStatus::Ai->value,
            'last_message_at' => now(),
        ]);

        $this->attachGuest($conversation, $from);

        return $conversation;
    }

    private function attachGuest(WhatsAppConversation $conversation, string $phone): void
    {
        $guest = (new Guest)->findByPhone($phone);

        if ($guest !== null) {
            $conversation->update([
                'guest_id' => $guest->id,
                'guest_name' => $guest->full_name,
            ]);
        }
    }

    private function maybeRespond(WhatsAppConversation $conversation, WhatsAppMessage $message): void
    {
        $setting = WhatsAppSetting::singleton();

        if ($conversation->status !== WhatsAppConversationStatus::Ai->value) {
            return;
        }

        if (! $setting->ai_enabled) {
            $this->sendAiReply($conversation, $setting->aiDisabledReply(), WhatsAppSenderType::Ai->value);

            return;
        }

        $answer = $this->assistant->answer($message->body, $conversation->phone);

        $menu = ($answer['send_menu'] ?? false)
            ? $this->menuItems()
            : [];

        $this->sendAiReply($conversation, $answer['text'], WhatsAppSenderType::Ai->value, $answer, $menu);
    }

    public function sendAiReply(WhatsAppConversation $conversation, string $text, string $senderType = WhatsAppSenderType::Ai->value, array $meta = [], array $menuItems = []): void
    {
        $isMenu = $menuItems !== [];

        $result = $isMenu
            ? $this->whatsApp->sendMenu($conversation->phone, $text, $menuItems)
            : $this->whatsApp->sendText($conversation->phone, $text);

        WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $result['wa_message_id'] ?? null,
            'direction' => WhatsAppDirection::Outgoing->value,
            'sender_type' => $senderType,
            'message_type' => $isMenu ? 'interactive' : 'text',
            'body' => $text,
            'metadata' => [
                'intent' => $meta['intent'] ?? null,
                'language' => $meta['language'] ?? null,
                'menu' => $isMenu ? $menuItems : null,
                'sent' => $result['success'] ?? false,
            ],
        ]);

        $conversation->update(['last_message_at' => now()]);
    }

    /**
     * Quick-reply buttons shown after greetings. Titles are chosen so that
     * a tap on them maps back to the matching intent during classification.
     */
    private function menuItems(): array
    {
        return [
            ['id' => 'card', 'title' => 'My E-Card'],
            ['id' => 'date', 'title' => 'Date & Time'],
            ['id' => 'venue', 'title' => 'Venue'],
        ];
    }

    public function sendManualReply(WhatsAppConversation $conversation, string $text, int $adminUserId): array
    {
        $conversation->update([
            'status' => WhatsAppConversationStatus::Manual->value,
            'last_message_at' => now(),
        ]);

        $result = $this->whatsApp->sendText($conversation->phone, $text);

        WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $result['wa_message_id'] ?? null,
            'direction' => WhatsAppDirection::Outgoing->value,
            'sender_type' => WhatsAppSenderType::Admin->value,
            'message_type' => 'text',
            'body' => $text,
            'metadata' => ['admin_user_id' => $adminUserId, 'sent' => $result['success'] ?? false],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $result;
    }

    private function messageBody(array $message, string $type): string
    {
        return match ($type) {
            'text' => (string) data_get($message, 'text.body'),
            'button' => (string) (data_get($message, 'button.text') ?: data_get($message, 'button.payload')),
            'interactive' => (string) (data_get($message, 'interactive.button_reply.title')
                ?: data_get($message, 'interactive.button_reply.id')
                ?: data_get($message, 'interactive.list_reply.title')),
            'image' => '[Picha]',
            'document' => '[Hati]',
            'audio', 'voice' => '[Sauti]',
            'video' => '[Video]',
            'location' => (string) (data_get($message, 'location.name') ?: '[Mahali]'),
            'contact' => '[Anwani]',
            'sticker' => '[Sticker]',
            'reaction' => (string) data_get($message, 'reaction.emoji'),
            default => '',
        };
    }
}
