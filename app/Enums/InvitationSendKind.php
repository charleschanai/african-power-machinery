<?php

namespace App\Enums;

/**
 * Distinguishes the original invitation from a later reminder in the shared
 * invitation_sends log.
 */
enum InvitationSendKind: string
{
    case Invitation = 'invitation';
    case Reminder = 'reminder';

    public function label(): string
    {
        return match ($this) {
            self::Invitation => 'Invitation',
            self::Reminder => 'Reminder',
        };
    }
}
