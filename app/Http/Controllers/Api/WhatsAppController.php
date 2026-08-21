<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WhatsAppController extends Controller
{
    public function __construct(private readonly WhatsAppService $whatsApp)
    {
    }

    public function sendCard(Request $request, Card $card): JsonResponse
    {
        $request->validate([
            'card_id' => 'sometimes|exists:cards,id',
        ]);

        $card->loadMissing('guest');

        if (! $card->guest) {
            throw ValidationException::withMessages([
                'card' => ['This card has no guest.'],
            ]);
        }

        $result = $this->whatsApp->sendCard($card);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => [
                'configured' => $result['configured'] ?? false,
                'wa_link' => $result['wa_link'] ?? null,
                'card_url' => $result['card_url'] ?? null,
            ],
        ], $result['success'] ? 200 : 422);
    }
}
