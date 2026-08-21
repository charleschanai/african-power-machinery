<?php

namespace App\Services;

use App\Models\EventDetail;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Arr;

class WhatsAppSettingsService
{
    public function settings(): WhatsAppSetting
    {
        return WhatsAppSetting::singleton();
    }

    /**
     * All settings merged with the "secret" values that live in the
     * environment. Secret values are never returned by default.
     */
    public function all(bool $includeSecrets = false): array
    {
        $setting = $this->settings();
        $event = EventDetail::singleton();

        $data = [
            'whatsapp_business_phone' => $setting->whatsapp_business_phone,
            'phone_number_id' => $setting->phone_number_id,
            'business_account_id' => $setting->business_account_id,
            'webhook_verify_token' => $setting->webhook_verify_token,
            'ai_enabled' => (bool) $setting->ai_enabled,
            'organizer_name' => $setting->organizer_name,
            'organizer_phone' => $setting->organizer_phone,
            'default_language' => $setting->default_language,
            'ai_disabled_message' => $setting->ai_disabled_message,
            'unknown_guest_message' => $setting->unknown_guest_message,
            'no_info_message' => $setting->no_info_message,
            'access_token_configured' => filled(config('services.whatsapp.token')),
            'ai_key_configured' => filled(config('services.ai.key')),
            'phone_number_id_env' => config('services.whatsapp.phone_number_id'),
            'configured' => $this->isConfigured(),
            'webhook_url' => $this->webhookUrl(),
            'event_name' => $event->event_name,
            'wa_me_link' => $setting->whatsapp_business_phone
                ? $this->waMeLink($setting->whatsapp_business_phone)
                : null,
        ];

        if ($includeSecrets) {
            $data['access_token'] = config('services.whatsapp.token');
        }

        return $data;
    }

    public function isConfigured(): bool
    {
        return $this->settings()->whatsapp_business_phone !== null
            && app(WhatsAppService::class)->isConfigured();
    }

    public function update(array $data): WhatsAppSetting
    {
        $setting = $this->settings();

        $fillable = Arr::only($data, [
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
        ]);

        $setting->update($fillable);

        return $setting;
    }

    public function webhookUrl(): string
    {
        return url('/whatsapp/webhook');
    }

    public function waMeLink(string $phone): string
    {
        $e164 = PhoneHelper::toE164($phone);

        return 'https://wa.me/' . ($e164 ?? '');
    }
}
