<?php

namespace App\Models;

use App\Enums\WhatsAppDirection;
use App\Enums\WhatsAppSenderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'conversation_id',
        'whatsapp_message_id',
        'direction',
        'sender_type',
        'message_type',
        'body',
        'metadata',
    ];

    protected $casts = [
        'direction' => WhatsAppDirection::class,
        'sender_type' => WhatsAppSenderType::class,
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }
}
