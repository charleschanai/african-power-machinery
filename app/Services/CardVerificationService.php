<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Enums\EntryResult;
use App\Models\Card;
use App\Models\EntryLog;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CardVerificationService
{
    public function findCard(string $identifier): ?Card
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '/')) {
            $parts = explode('/', $identifier);
            $identifier = trim((string) end($parts));
        }

        if ($identifier === '') {
            return null;
        }

        return Card::query()
            ->with('cardTemplate')
            ->where('card_number', $identifier)
            ->orWhere('qr_token', $identifier)
            ->first();
    }

    public function search(string $term, int $limit = 12): Collection
    {
        $term = trim($term);

        return Card::query()
            ->with('guest')
            ->where('card_number', 'like', "%{$term}%")
            ->orWhereHas('guest', function ($q) use ($term) {
                $q->where('full_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Card $card) => $this->decorate($card))
            ->unique('id');
    }

    public function searchGuests(string $term, int $limit = 12): Collection
    {
        $term = trim($term);

        return Guest::query()
            ->with('cards')
            ->where('full_name', 'like', "%{$term}%")
            ->orWhere('phone', 'like', "%{$term}%")
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Read-only check. Never mutates state. Used to show card details before
     * the officer decides to confirm the entry.
     */
    public function verifyCard(string $identifier): CardVerificationResult
    {
        $card = $this->findCard($identifier);

        if (! $card) {
            return new CardVerificationResult(
                success: false,
                message: 'No card matches the details you entered.',
                errorCode: 'CARD_NOT_FOUND',
                result: EntryResult::Invalid,
            );
        }

        $denial = $this->denialReason($card);

        if ($denial) {
            [$message, $code, $result] = $denial;

            return new CardVerificationResult(
                success: false,
                message: $message,
                errorCode: $code,
                card: $card,
                guest: $card->guest,
                result: $result,
                entryNumber: $card->used_people + 1,
            );
        }

        return new CardVerificationResult(
            success: true,
            message: 'This card is ready to allow entry.',
            card: $card,
            guest: $card->guest,
            result: EntryResult::Allowed,
            entryNumber: $card->used_people + 1,
        );
    }

    /**
     * Confirms the entry atomically. Locks the card row so two concurrent
     * scans of the same card can never both be allowed beyond the limit.
     */
    public function confirmEntry(string $identifier, ?User $user, array $context = []): CardVerificationResult
    {
        return DB::transaction(function () use ($identifier, $user, $context) {
            $card = $this->findCard($identifier);

            if (! $card) {
                $log = $this->recordAttempt(null, null, $user, EntryResult::Invalid, 1, $context);

                return new CardVerificationResult(
                    success: false,
                    message: 'No card matches the details you entered.',
                    errorCode: 'CARD_NOT_FOUND',
                    result: EntryResult::Invalid,
                    entryLog: $log,
                    entryNumber: 1,
                );
            }

            $card = Card::query()
                ->with('cardTemplate')
                ->whereKey($card->id)
                ->lockForUpdate()
                ->first();

            $requestedCount = $this->requestedCount($context);

            $denial = $this->denialReason($card, $requestedCount);

            if ($denial) {
                [$message, $code, $result] = $denial;
                $entryNumber = $card->used_people + 1;
                $log = $this->recordAttempt($card, $card->guest, $user, $result, $entryNumber, $context, $message);

                return new CardVerificationResult(
                    success: false,
                    message: $message,
                    errorCode: $code,
                    card: $card,
                    guest: $card->guest,
                    result: $result,
                    entryLog: $log,
                    entryNumber: $entryNumber,
                );
            }

            $entryNumber = $card->used_people + 1;

            $card->used_people = $card->used_people + $requestedCount;
            if ($card->used_people >= $card->allowed_people) {
                $card->status = CardStatus::Used;
            }
            $card->save();

            $log = $this->recordAttempt($card, $card->guest, $user, EntryResult::Allowed, $entryNumber, $context);

            $card->refresh();

            return new CardVerificationResult(
                success: true,
                message: 'Entry permission has been granted.',
                card: $card,
                guest: $card->guest,
                result: EntryResult::Allowed,
                entryLog: $log,
                entryNumber: $entryNumber,
            );
        });
    }

    private function requestedCount(array $context): int
    {
        $count = (int) ($context['number_of_guests'] ?? 1);

        return max(1, min($count, 2));
    }

    /**
     * Returns [message, error_code, EntryResult] when the card must be denied,
     * otherwise null.
     */
    private function denialReason(Card $card, int $requestedCount = 1): ?array
    {
        if ($card->isCancelled()) {
            return [
                'This card has been cancelled.',
                'CARD_CANCELLED',
                EntryResult::Cancelled,
            ];
        }

        if ($card->isExpired()) {
            return [
                'This card has expired.',
                'CARD_EXPIRED',
                EntryResult::Expired,
            ];
        }

        if ($requestedCount > $card->remaining()) {
            $message = $card->remaining() > 0
                ? "The number of people entering exceeds the remaining allowance. Only {$card->remaining()} more can enter."
                : ($card->allowed_people === 1
                    ? 'This card has already been used.'
                    : 'The allowed number of people has been reached.');

            return [
                $message,
                'CARD_ALREADY_USED',
                EntryResult::AlreadyUsed,
            ];
        }

        if (! $card->hasCapacity()) {
            $message = $card->allowed_people === 1
                ? 'This card has already been used.'
                : 'The allowed number of people has been reached.';

            return [
                $message,
                'CARD_ALREADY_USED',
                EntryResult::AlreadyUsed,
            ];
        }

        return null;
    }

    private function recordAttempt(
        ?Card $card,
        ?Guest $guest,
        ?User $user,
        EntryResult $result,
        int $entryNumber,
        array $context,
        ?string $notes = null,
    ): EntryLog {
        return EntryLog::query()->create([
            'card_id' => $card?->id,
            'guest_id' => $guest?->id,
            'processed_by' => $user?->id,
            'entry_number' => $entryNumber,
            'result' => $result,
            'verification_method' => $context['verification_method'] ?? null,
            'number_of_guests' => $context['number_of_guests'] ?? 1,
            'scanned_at' => now(),
            'gate_name' => $context['gate_name'] ?? null,
            'device_name' => $context['device_name'] ?? null,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'notes' => $notes,
        ]);
    }

    private function decorate(Card $card): Card
    {
        $card->setAttribute('guest_name', $card->guest?->full_name);
        $card->setAttribute('card_type_label', $card->card_type->label());
        $card->setAttribute('status', $card->isExpired() ? 'expired' : $card->status->value);
        $card->setAttribute('status_label', $card->displayStatusLabel());
        $card->setAttribute('remaining', $card->remaining());
        $card->setAttribute('event_name', $card->event_name);
        $card->setAttribute('event_date', $card->event_date?->toDateString());
        $card->setAttribute('venue', $card->venue);

        return $card;
    }
}
