<?php

namespace App\Enums;

enum WhatsAppConversationStatus: string
{
    case Ai = 'ai';
    case Manual = 'manual';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI',
            self::Manual => 'Admin',
            self::Blocked => 'Blocked',
        };
    }
}
