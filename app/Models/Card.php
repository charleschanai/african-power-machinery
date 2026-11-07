<?php

namespace App\Models;

use App\Enums\CardStatus;
use App\Enums\CardType;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The public address travels with the card in every response.
     *
     * Appending it means a screen that shows a card cannot forget to use the
     * right address, because the correct one is simply there beside the rest of
     * the data. That is what stops a printed card going out with 127.0.0.1
     * printed inside its QR code, which is a mistake no one notices until a
     * guest stands at the gate and their camera opens nothing.
     */
    protected $appends = ['card_url'];

    /**
     * Relations that are needed on nearly every card screen. Declaring them
     * once here stops each controller from remembering to eager-load, which
     * is the usual cause of an N+1 slipping back in.
     */
    protected $with = ['guest'];

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

    /**
     * The one address a guest can open for this card.
     *
     * This is the single place a card link is built, and it deliberately does
     * not use url() or config('app.url'). Both of those describe the machine
     * the request happened to arrive on, which on a laptop is 127.0.0.1 and on
     * this network is 10.180.231.187. Neither can be opened by anyone standing
     * at a wedding gate with a phone, and both of those values used to end up
     * inside printed QR codes, where a guest's camera would scan a link that
     * goes nowhere.
     *
     * ECARD_PUBLIC_URL is the address the admin chose for guests, so that is the
     * only thing that belongs in a link, an SMS, a shared message, a printed
     * card, or a QR code.
     */
    public function publicUrl(): string
    {
        return rtrim(Invitation::publicBaseUrl(), '/').'/e-card/'.$this->qr_token;
    }

    /**
     * The same address, exposed as data so a screen never rebuilds it.
     *
     * @see publicUrl() for why the address is not built from the request.
     */
    public function getCardUrlAttribute(): string
    {
        return $this->publicUrl();
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

    /* ------------------------------------------------------------------ */
    /*  Query scopes                                                       */
    /* ------------------------------------------------------------------ */

    public function scopeStatus(Builder $query, CardStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof CardStatus ? $status->value : $status);
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', CardStatus::Cancelled->value);
    }

    public function scopeStillValid(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function scopeForGuest(Builder $query, int $guestId): Builder
    {
        return $query->where('guest_id', $guestId);
    }

    /**
     * Cards that could receive an invitation: has a guest, is not cancelled,
     * and that guest has a usable phone number.
     *
     * Shared by InvitationCampaignService and the preview/report counts so
     * the "how many will be sent" number can never disagree with the number
     * actually sent.
     */
    public function scopeInvitable(Builder $query): Builder
    {
        return $query->whereNotNull('guest_id')
            ->notCancelled()
            ->whereHas('guest', fn (Builder $q) => $q->contactable());
    }
}
