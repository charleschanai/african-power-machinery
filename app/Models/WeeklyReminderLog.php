<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyReminderLog extends Model
{
    protected $fillable = [
        'card_id',
        'phone_e164',
        'guest_name',
        'message_sent',
        'status',
        'error',
        'reminder_date',
        'week_number',
        'year',
        'sent_at',
    ];

    protected $casts = [
        'reminder_date' => 'date',
        'sent_at' => 'datetime',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
