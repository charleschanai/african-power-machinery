<?php

namespace App\Enums;

/**
 * Lifecycle of a saved invitation record.
 *
 * Deliberately separate from InvitationStatus, which describes the provider
 * delivery state of a single message (queued/sent/delivered/failed). This one
 * answers the admin's question: "has this guest been contacted yet?"
 */
enum InvitationState: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not Sent',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }
}
