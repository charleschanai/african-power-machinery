<?php

namespace App\Enums;

enum EntryResult: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case AlreadyUsed = 'already_used';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Invalid = 'invalid';

    public function label(): string
    {
        return match ($this) {
            self::Allowed => 'Allowed',
            self::Denied => 'Denied',
            self::AlreadyUsed => 'Card already used',
            self::Cancelled => 'Card cancelled',
            self::Expired => 'Card expired',
            self::Invalid => 'Invalid card',
        };
    }
}
