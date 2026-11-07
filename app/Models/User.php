<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_STAFF = 'staff';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_STAFF];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $appends = [
        'two_factor_enabled',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Users who may still sign in.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDeactivated($query)
    {
        return $query->where('is_active', false);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Shape sent to the admin users screen.
     *
     * Never includes the password or either 2FA secret, and never the
     * recovery codes, even hashed.
     */
    public function toFrontendArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->isActive(),
            'two_factor_enabled' => $this->twoFactorEnabled(),
            'processed_entries_count' => $this->processed_entries_count ?? null,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    public function twoFactorEnabled(): bool
    {
        return ! empty($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    public function getTwoFactorEnabledAttribute(): bool
    {
        return $this->twoFactorEnabled();
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function processedEntries(): HasMany
    {
        return $this->hasMany(EntryLog::class, 'processed_by');
    }
}
