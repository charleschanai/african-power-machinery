<?php

namespace App\Models;

use App\Enums\EntryResult;
use App\Enums\VerificationMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryLog extends Model
{
    use HasFactory;

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
}
