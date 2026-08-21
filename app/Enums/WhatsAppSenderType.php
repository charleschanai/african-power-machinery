<?php

namespace App\Enums;

enum WhatsAppSenderType: string
{
    case Guest = 'guest';
    case Ai = 'ai';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Guest => 'Guest',
            self::Ai => 'AI',
            self::Admin => 'Admin',
        };
    }
}
