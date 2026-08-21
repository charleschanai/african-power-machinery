<?php

namespace App\Models;

use App\Enums\CardStatus;
use App\Enums\CardType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'card_template_id',
        'card_number',
        'qr_token',
        'card_type',
        'allowed_people',
        'used_people',
        'status',
        'issued_at',
        'expires_at',
        'event_name',
        'event_date',
        'event_time',
        'venue',
        'table_number',
        'seat_number',
        'special_message',
    ];

    protected $casts = [
        'card_type' => CardType::class,
        'status' => CardStatus::class,
        'allowed_people' => 'integer',
        'used_people' => 'integer',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'event_date' => 'date',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function cardTemplate(): BelongsTo
    {
        return $this->belongsTo(CardTemplate::class);
    }

    public function entryLogs(): HasMany
    {
        return $this->hasMany(EntryLog::class);
    }

    public function invitationSends(): HasMany
    {
        return $this->hasMany(InvitationSend::class);
    }

    public function isActive(): bool
    {
        return $this->status === CardStatus::Active;
    }

    public function isCancelled(): bool
    {
        return $this->status === CardStatus::Cancelled;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function displayStatusLabel(): string
    {
        if ($this->isExpired()) {
            return 'Expired';
        }

        return $this->status->label();
    }

    public function hasCapacity(): bool
    {
        return $this->used_people < $this->allowed_people;
    }

    public function usedCount(): int
    {
        return $this->used_people;
    }

    public function remaining(): int
    {
        return max(0, $this->allowed_people - $this->used_people);
    }
}
