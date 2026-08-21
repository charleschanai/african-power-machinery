<?php

namespace App\Models;

use App\Enums\WhatsAppConversationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'phone',
        'guest_id',
        'guest_name',
        'status',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }

    public function latestMessages(int $limit = 30): HasMany
    {
        return $this->messages()->orderByDesc('created_at')->limit($limit);
    }

    public function isAi(): bool
    {
        return $this->status === WhatsAppConversationStatus::Ai->value;
    }

    public function isBlocked(): bool
    {
        return $this->status === WhatsAppConversationStatus::Blocked->value;
    }
}
