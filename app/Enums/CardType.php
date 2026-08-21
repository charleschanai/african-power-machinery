<?php

namespace App\Enums;

enum CardType: string
{
    case Single = 'single';
    case Double = 'double';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single',
            self::Double => 'Double',
        };
    }

    public function allowedPeople(): int
    {
        return match ($this) {
            self::Single => 1,
            self::Double => 2,
        };
    }
}
