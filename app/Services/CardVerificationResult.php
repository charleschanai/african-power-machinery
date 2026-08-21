<?php

namespace App\Services;

use App\Enums\EntryResult;
use App\Models\Card;
use App\Models\EntryLog;
use App\Models\Guest;

class CardVerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $errorCode = null,
        public readonly ?Card $card = null,
        public readonly ?Guest $guest = null,
        public readonly ?EntryResult $result = null,
        public readonly ?EntryLog $entryLog = null,
        public readonly ?int $entryNumber = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'error_code' => $this->errorCode,
            'result' => $this->result?->value,
            'entry_number' => $this->entryNumber,
            'data' => $this->card ? [
                'card' => [
                    'id' => $this->card->id,
                    'card_number' => $this->card->card_number,
                    'card_type' => $this->card->card_type->value,
                    'card_type_label' => $this->card->card_type->label(),
                    'status' => $this->card->isExpired() ? 'expired' : $this->card->status->value,
                    'status_label' => $this->card->displayStatusLabel(),
                    'allowed_people' => $this->card->allowed_people,
                    'used_people' => $this->card->used_people,
                    'remaining' => $this->card->remaining(),
                    'issued_at' => $this->card->issued_at?->toISOString(),
                    'expires_at' => $this->card->expires_at?->toISOString(),
                    'event_name' => $this->card->event_name,
                    'event_date' => $this->card->event_date?->toDateString(),
                    'event_time' => $this->card->event_time,
                    'venue' => $this->card->venue,
                    'table_number' => $this->card->table_number,
                    'seat_number' => $this->card->seat_number,
                    'special_message' => $this->card->special_message,
                    'template' => $this->card->relationLoaded('cardTemplate') && $this->card->cardTemplate
                        ? [
                            'id' => $this->card->cardTemplate->id,
                            'name' => $this->card->cardTemplate->name,
                            'card_type' => $this->card->cardTemplate->card_type->value,
                            'front_design' => $this->card->cardTemplate->frontUrl(),
                            'back_design' => $this->card->cardTemplate->backUrl(),
                        ]
                        : null,
                ],
                'guest' => $this->guest ? [
                    'id' => $this->guest->id,
                    'full_name' => $this->guest->full_name,
                    'phone' => $this->guest->phone,
                    'email' => $this->guest->email,
                    'notes' => $this->guest->notes,
                ] : null,
                'entry' => $this->entryLog ? [
                    'id' => $this->entryLog->id,
                    'entry_number' => $this->entryLog->entry_number,
                    'result' => $this->entryLog->result->value,
                    'result_label' => $this->entryLog->result->label(),
                    'scanned_at' => $this->entryLog->scanned_at?->toISOString(),
                    'gate_name' => $this->entryLog->gate_name,
                    'verification_method' => $this->entryLog->verification_method?->value,
                    'number_of_guests' => $this->entryLog->number_of_guests,
                ] : null,
            ] : null,
        ];
    }
}
