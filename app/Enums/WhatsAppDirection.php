<?php

namespace App\Enums;

enum WhatsAppDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';

    public function label(): string
    {
        return match ($this) {
            self::Incoming => 'Incoming',
            self::Outgoing => 'Outgoing',
        };
    }
}
