<?php

namespace App\Services;

use App\Mail\InvitationMail;
use App\Models\Card;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    public function isConfigured(): bool
    {
        return filled(config('services.mail.username'))
            || filled(config('mail.mailers.smtp.username'));
    }

    public function send(Card $card): array
    {
        $card->loadMissing('guest');

        if (! $card->guest) {
            return [
                'success' => false,
                'message' => 'This card has no guest.',
            ];
        }

        $email = $card->guest->email;

        if (! $email || $email === '') {
            return [
                'success' => false,
                'message' => 'This guest has no email address.',
            ];
        }

        $cardUrl = $card->publicUrl();

        try {
            Mail::to($email)->send(new InvitationMail($card, $cardUrl));

            return [
                'success' => true,
                'message' => "Invitation email sent to {$email}.",
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to send invitation email', [
                'card_id' => $card->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send the email. ' . $e->getMessage(),
            ];
        }
    }

    public function sendMany(array $cardIds): array
    {
        $cards = Card::with('guest')->whereIn('id', $cardIds)->get();

        $sent = 0;
        $failed = 0;
        $errors = [];
        $skipped = 0;

        foreach ($cards as $card) {
            if (! $card->guest?->email || $card->guest->email === '') {
                $skipped++;
                $errors[] = "{$card->card_number}: No email address.";
                continue;
            }

            $result = $this->send($card);

            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = "{$card->card_number}: {$result['message']}";
            }
        }

        $message = "{$sent} invitation email(s) sent.";

        if ($skipped > 0) {
            $message .= " {$skipped} skipped (no email).";
        }

        if ($failed > 0) {
            $message .= " {$failed} failed.";
        }

        return [
            'success' => $failed === 0 && $sent > 0,
            'message' => $message,
            'data' => [
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
        ];
    }
}
