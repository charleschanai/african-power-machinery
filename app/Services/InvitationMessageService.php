<?php

namespace App\Services;

use App\Enums\InvitationChannel;
use App\Enums\InvitationSendKind;
use App\Enums\InvitationState;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\InvitationSend;
use Illuminate\Support\Facades\Log;

/**
 * Turns a saved invitation into an actual message and delivers it through the
 * existing NextSMS integration.
 *
 * Nothing here creates a new SMS provider: SmsService is reused as-is, so the
 * credentials, delivery reports and retry behaviour already in the project
 * keep working unchanged.
 */
class InvitationMessageService
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly MessageTemplateRenderer $renderer,
    ) {
    }

    /**
     * The wording printed on the wedding card, ready to save.
     *
     * The admin can edit any line, but starting from the real card text saves
     * retyping the schedule on every invitation. The date is deliberately left
     * out: it is already a field of its own and repeating it here would make
     * the SMS longer and give two places to correct if it changes.
     */
    public function defaultWeddingDetails(string $cardType = 'single'): string
    {
        $salutation = $cardType === 'double' ? 'MR&MRS/PROF/DR/MR/MS' : 'MR&MS/PROF/DR/MR/MS';
        $cardLabel = strtoupper($cardType === 'double' ? 'Double' : 'Single');

        return implode("\n", [
            'Familia ya Jamson Lusige Mwangunguru inayo furaha kukualika/kuwaalika',
            $salutation,
            'Kwenye harusi ya',
            'SOLOMON JAMSON & WINFRIDA TESHA',
            'Itakayofanyika 14/11/2026',
            $cardLabel,
            '',
            'IBADA',
            'Ibada itafanyika katika Kanisa la KKKT Tumbi Kibaha',
            'Muda: 8:00 Mchana',
            '',
            'UKUMBI',
            'La-Dariot Social Hall Magari Saba Mbezi',
            'Muda: 12:00 Jioni',
            '',
            'DRESSING CODE: Emerald green, gold, brown & teal blue',
            '',
            'MAWASILIANO: 0683205067 / 0787615263',
        ]);
    }

    /**
     * A ready-to-edit starter message. It uses placeholders rather than baked
     * in values so one template works for every guest.
     *
     * Card wording comes from the selected card type's wedding_details value.
     * The placeholder keeps the invitation and reminder previews in sync with
     * the exact Single or Double card details saved with that invitation.
     */
    public function defaultMessage(): string
    {
        return implode("\n", [
            'Habari {first_name},',
            '',
            '{wedding_details}',
            '',
            'Kadi yako ya kidijitali: {card_link}',
            'Namba ya kadi: {card_number}',
            '',
            'Tafadhali onyesha kadi yako mlangoni. Tunatazamia kusherehekea pamoja nawe!',
        ]);
    }

    public function defaultReminder(string $cardType = 'single'): string
    {
        return implode("\n", [
            'Habari {first_name},',
            '',
            'Tunakukumbusha kwa furaha kuhusu mwaliko huu:',
            '{wedding_details}',
            '',
            'Kadi yako ya kidijitali: {card_link}',
            'Namba ya kadi: {card_number}',
            '',
            'Tafadhali onyesha kadi yako mlangoni. Tunatazamia kukuona!',
        ]);
    }

    /**
     * The message as the guest will actually receive it.
     */
    public function renderMessage(Invitation $invitation): string
    {
        return $this->renderer->render($invitation->message, $invitation);
    }

    public function renderReminder(Invitation $invitation, ?string $override = null): string
    {
        $template = $override ?? $invitation->reminder_message ?? '';

        if (trim($template) === '') {
            $template = $this->defaultReminder($invitation->card?->card_type?->value ?? 'single');
        }

        return $this->renderer->render($template, $invitation);
    }

    /**
     * Sends the saved invitation message over normal SMS.
     */
    public function sendInvitation(Invitation $invitation): array
    {
        return $this->deliver(
            $invitation,
            $this->renderMessage($invitation),
            InvitationSendKind::Invitation
        );
    }

    /**
     * Sends the saved reminder message. $override lets the admin edit the
     * reminder in the dialog without saving it first.
     */
    public function sendReminder(Invitation $invitation, ?string $override = null): array
    {
        $message = $this->renderReminder($invitation, $override);

        if ($message === '') {
            return $this->failure($invitation, 'The reminder message is empty. Add a reminder message first.');
        }

        return $this->deliver($invitation, $message, InvitationSendKind::Reminder);
    }

    /**
     * WhatsApp is a manual, user-driven share, so there is no provider call
     * here. The frontend has already handed the image and text to the device's
     * share sheet; this only records that it happened.
     */
    public function markWhatsAppShared(Invitation $invitation): array
    {
        $now = now();

        $invitation->forceFill([
            'last_whatsapp_shared_at' => $now,
            'last_sent_at' => $invitation->last_sent_at ?? $now,
        ])->save();

        return [
            'success' => true,
            'message' => 'WhatsApp share recorded.',
        ];
    }

    /**
     * Shared delivery path for both the invitation and the reminder.
     */
    private function deliver(Invitation $invitation, string $message, InvitationSendKind $kind): array
    {
        $raw = $invitation->phone_e164 ?? $invitation->phone ?? '';
        $phone = PhoneHelper::toE164((string) $raw);

        // Only genuinely hopeless numbers are refused here. A number that is
        // merely unusual is still sent, because the admin may know something
        // the format check does not, and it is not this system's place to
        // decide their guest's number is wrong.
        if (! PhoneHelper::isPlausible($phone)) {
            return $this->failure(
                $invitation,
                PhoneHelper::problem($raw) ?? 'The phone number is not valid. Edit the invitation and correct it before sending.'
            );
        }

        if (! $this->sms->isConfigured()) {
            return $this->failure(
                $invitation,
                'SMS is not configured. Set NEXTSMS_USERNAME and NEXTSMS_PASSWORD in .env, then run php artisan config:clear.'
            );
        }

        if (trim($message) === '') {
            return $this->failure($invitation, 'The message is empty.');
        }

        $result = $this->sms->send($phone, $message);

        $this->logSend($invitation, $kind, $phone, $message, $result);

        if (($result['success'] ?? false) === true) {
            $sent = $this->markSent($invitation, $kind, $result['message'] ?? 'The SMS was sent successfully.');

            // The gateway reports success even for numbers it cannot reach, so
            // a number that failed the format check carries a warning back with
            // it. Without this the admin is told everything is fine while the
            // guest hears nothing at all.
            if (! PhoneHelper::isDeliverable($phone)) {
                $sent['warning'] = PhoneHelper::problem($raw);
            }

            return $sent;
        }

        return $this->failure($invitation, $result['message'] ?? 'Failed to send the SMS.');
    }

    /**
     * Writes to the existing invitation_sends log, now keyed by kind so an
     * invitation and its reminder can coexist for the same card.
     *
     * Two constraints of that legacy table shape this method. card_id is
     * declared NOT NULL, so a card-less invitation simply has nowhere to be
     * recorded and is skipped; the invitations table is the authoritative
     * record in that case anyway. And there is one row per
     * (card, channel, kind), so a repeated send updates that row rather than
     * colliding with it, which a plain create used to do.
     */
    private function logSend(
        Invitation $invitation,
        InvitationSendKind $kind,
        string $phone,
        string $message,
        array $result
    ): void {
        $success = ($result['success'] ?? false) === true;

        if ($invitation->card_id === null) {
            // invitation_sends.card_id is NOT NULL, so this cannot be logged
            // there. The invitations row already carries status, timestamps and
            // the error, so nothing is actually lost by skipping it.
            return;
        }

        try {
            InvitationSend::query()->updateOrCreate(
                [
                    'card_id' => $invitation->card_id,
                    'channel' => InvitationChannel::Sms->value,
                    'kind' => $kind->value,
                ],
                [
                    'status' => $success
                        ? InvitationStatus::Sent->value
                        : InvitationStatus::Failed->value,
                    'phone_e164' => $phone,
                    'provider_message_id' => $result['message_id'] ?? null,
                    'error' => $success ? null : ($result['message'] ?? 'Unknown error'),
                    'queued_at' => now(),
                    'sent_at' => $success ? now() : null,
                ]
            );
        } catch (\Throwable $e) {
            // A log write must never lose the actual delivery result.
            Log::warning('Could not write invitation_sends row', [
                'invitation_id' => $invitation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markSent(Invitation $invitation, InvitationSendKind $kind, string $message): array
    {
        $now = now();

        $attributes = [
            'status' => InvitationState::Sent->value,
            'last_error' => null,
            'last_sent_at' => $now,
        ];

        if ($kind === InvitationSendKind::Reminder) {
            $attributes['last_reminder_sent_at'] = $now;
            $attributes['reminders_count'] = $invitation->reminders_count + 1;
        } else {
            $attributes['last_sms_sent_at'] = $now;
        }

        $invitation->forceFill($attributes)->save();

        return [
            'success' => true,
            'message' => $message,
            'sent_at' => $now->toDateTimeString(),
        ];
    }

    private function failure(Invitation $invitation, string $error): array
    {
        $invitation->forceFill([
            'status' => InvitationState::Failed->value,
            'last_error' => $error,
        ])->save();

        Log::warning('Invitation send failed', [
            'invitation_id' => $invitation->id,
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $error,
        ];
    }
}
