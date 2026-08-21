<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSetting extends Model
{
    protected $table = 'whatsapp_settings';

    public const DEFAULT_AI_DISABLED_MESSAGE = 'The AI assistant is currently unavailable. Please wait for the organizer to reply to you.';

    public const DEFAULT_UNKNOWN_GUEST_MESSAGE = 'Welcome 😊 I could not find an E-Card associated with this number. Please contact the organizer for more help.';

    public const DEFAULT_NO_INFO_MESSAGE = 'Sorry, I do not have accurate information about that at the moment. Please contact the organizer via {contact_number}.';

    protected $fillable = [
        'whatsapp_business_phone',
        'phone_number_id',
        'business_account_id',
        'webhook_verify_token',
        'ai_enabled',
        'organizer_name',
        'organizer_phone',
        'default_language',
        'ai_disabled_message',
        'unknown_guest_message',
        'no_info_message',
    ];

    protected $casts = [
        'ai_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function singleton(): self
    {
        $setting = static::query()->find(1);

        if ($setting === null) {
            $setting = new static;
            $setting->forceFill(['id' => 1])->save();
        }

        return $setting->refresh();
    }

    public function aiDisabledReply(): string
    {
        return $this->ai_disabled_message ?: self::DEFAULT_AI_DISABLED_MESSAGE;
    }

    public function unknownGuestReply(): string
    {
        return $this->unknown_guest_message ?: self::DEFAULT_UNKNOWN_GUEST_MESSAGE;
    }

    public function noInfoReply(): string
    {
        $message = $this->no_info_message ?: self::DEFAULT_NO_INFO_MESSAGE;

        return str_replace('{contact_number}', $this->organizer_phone ?: $this->contactNumberFallback(), $message);
    }

    public function contactNumberFallback(): string
    {
        return EventDetail::singleton()->contact_number ?? $this->organizer_phone ?? '';
    }
}
