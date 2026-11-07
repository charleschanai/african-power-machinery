<?php

namespace App\Models;

use App\Enums\EntryResult;
use App\Enums\VerificationMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryLog extends Model
{
    use HasFactory;

    /**
     * The entry-log list always renders the guest, card and officer, so load
     * them together instead of letting the table render in three passes.
     */
    protected $with = ['guest', 'card', 'processor'];

    protected $fillable = [
        'card_id',
        'guest_id',
        'processed_by',
        'entry_number',
        'result',
        'verification_method',
        'number_of_guests',
        'scanned_at',
        'gate_name',
        'device_name',
        'ip_address',
        'user_agent',
        'notes',
    ];

    protected $casts = [
        'result' => EntryResult::class,
        'verification_method' => VerificationMethod::class,
        'scanned_at' => 'datetime',
        'entry_number' => 'integer',
        'number_of_guests' => 'integer',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Query scopes                                                       */
    /* ------------------------------------------------------------------ */

    public function scopeResult(Builder $query, EntryResult|string|null $result): Builder
    {
        if ($result === null || $result === '') {
            return $query;
        }

        return $query->where('result', $result instanceof EntryResult ? $result->value : $result);
    }

    public function scopeAllowed(Builder $query): Builder
    {
        return $query->where('result', EntryResult::Allowed->value);
    }

    public function scopeDenied(Builder $query): Builder
    {
        return $query->where('result', '!=', EntryResult::Allowed->value);
    }

    public function scopeSince(Builder $query, ?\DateTimeInterface $moment): Builder
    {
        return $moment ? $query->where('scanned_at', '>=', $moment) : $query;
    }

    public function scopeSearchGuestName(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->whereHas('guest', fn (Builder $q) => $q->where('full_name', 'like', "%{$term}%"));
    }
}
