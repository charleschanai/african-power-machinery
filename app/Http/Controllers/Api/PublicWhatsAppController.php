<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventDetail;
use App\Services\PhoneHelper;
use App\Services\WhatsAppSettingsService;
use Illuminate\Http\JsonResponse;

class PublicWhatsAppController extends Controller
{
    public function __construct(private readonly WhatsAppSettingsService $settingsService)
    {
    }

    /**
     * Public, safe endpoint used by public pages (E-Card, event page)
     * to display the official event WhatsApp number. Never exposes secrets.
     */
    public function number(): JsonResponse
    {
        $setting = $this->settingsService->settings();
        $event = EventDetail::singleton();

        $phone = $setting->whatsapp_business_phone;
        $e164 = $phone ? PhoneHelper::toE164($phone) : null;

        return response()->json([
            'success' => true,
            'data' => [
                'whatsapp_business_phone' => $phone,
                'event_name' => $event->event_name,
                'wa_me_link' => $e164 ? 'https://wa.me/' . $e164 : null,
            ],
        ]);
    }
}
