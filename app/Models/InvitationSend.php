<?php

namespace App\Models;

use App\Enums\InvitationChannel;
use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationSend extends Model
{
    protected $fillable = [
        'card_id',
        'channel',
        'status',
        'phone_e164',
        'provider_message_id',
        'error',
        'queued_at',
        'scheduled_at',
        'sent_at',
        'delivered_at',
    ];

    protected $casts = [
        'channel' => InvitationChannel::class,
        'status' => InvitationStatus::class,
        'queued_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
