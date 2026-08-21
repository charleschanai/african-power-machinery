<?php

namespace App\Models;

use App\Enums\CardType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CardTemplate extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'card_type',
        'front_design',
        'back_design',
        'description',
        'status',
    ];

    protected $casts = [
        'card_type' => CardType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDouble(): bool
    {
        return $this->card_type === CardType::Double;
    }

    public function frontUrl(): ?string
    {
        return $this->front_design ? Storage::disk('public')->url($this->front_design) : null;
    }

    public function backUrl(): ?string
    {
        return $this->back_design ? Storage::disk('public')->url($this->back_design) : null;
    }

    public function toFrontendArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'card_type' => $this->card_type->value,
            'card_type_label' => $this->card_type->label(),
            'front_design' => $this->frontUrl(),
            'back_design' => $this->backUrl(),
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->status === self::STATUS_ACTIVE ? 'Inatumika' : 'Haifanyi kazi',
            'cards_count' => $this->cards_count ?? $this->cards()->count(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
