<?php

namespace App\Models;

use App\Services\PhoneHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'phone',
        'phone_e164',
        'email',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Guest $guest) {
            if ($guest->isDirty('phone')) {
                $guest->phone_e164 = PhoneHelper::toE164($guest->phone ?? '');
            }
        });
    }

    public function findByPhone(string $phone): ?Guest
    {
        $e164 = PhoneHelper::toE164($phone);

        if ($e164 === null) {
            return null;
        }

        return static::query()
            ->where('phone_e164', $e164)
            ->orWhere('phone', $phone)
            ->first();
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function entryLogs(): HasMany
    {
        return $this->hasMany(EntryLog::class);
    }

    public function latestCard(): ?Card
    {
        return $this->cards()->latest('id')->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Query scopes                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * A guest we can actually send a WhatsApp or SMS message to.
     *
     * The length check mirrors InvitationCampaignService so that a guest who
     * is filtered out of a campaign is also filtered out of its preview count
     * — previously the two used separate copies of this rule.
     */
    public function scopeContactable(Builder $query): Builder
    {
        return $query->whereNotNull('phone_e164')
            ->where('phone_e164', '!=', '')
            ->whereRaw('LENGTH(phone_e164) >= 10');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $sub) use ($term) {
            $sub->where('full_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }
}
