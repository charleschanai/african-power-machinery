<?php

namespace App\Enums;

enum VerificationMethod: string
{
    case Qr = 'qr';
    case CardNumber = 'card_number';
    case GuestName = 'guest_name';

    public function label(): string
    {
        return match ($this) {
            self::Qr => 'QR',
            self::CardNumber => 'Card Number',
            self::GuestName => 'Guest Name',
        };
    }
}
