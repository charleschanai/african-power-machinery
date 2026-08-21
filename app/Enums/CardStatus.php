<?php

namespace App\Enums;

enum CardStatus: string
{
    case Active = 'active';
    case Used = 'used';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Used => 'Used',
            self::Cancelled => 'Cancelled',
        };
    }
}
