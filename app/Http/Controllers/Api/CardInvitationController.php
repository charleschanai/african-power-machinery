<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Services\CardInvitationService;
use App\Services\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CardInvitationController extends Controller
{
    public function __construct(private readonly CardInvitationService $invitations)
    {
    }

    public function send(Request $request, Card $card): JsonResponse
    {
        $validated = $request->validate([
            'channel' => 'required|in:whatsapp,sms',
        ]);

        $card->loadMissing('guest');

        if (! $card->guest) {
            throw ValidationException::withMessages([
                'card' => ['This card has no guest.'],
            ]);
        }

        $result = $this->invitations->send($card, $validated['channel']);

        SecurityLogger::logRequest('invitation.send', $request, $request->user(), [
            'card_id' => $card->id,
            'channel' => $validated['channel'],
            'success' => $result['success'],
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => [
                'configured' => $result['configured'] ?? false,
                'wa_link' => $result['wa_link'] ?? null,
                'card_url' => $result['card_url'] ?? url('/e-card/' . $card->qr_token),
            ],
        ], $result['success'] ? 200 : 422);
    }

    public function sendMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => 'required|in:whatsapp,sms',
            'card_ids' => 'required|array|min:1',
            'card_ids.*' => 'integer|exists:cards,id',
        ]);

        $result = $this->invitations->sendMany($validated['card_ids'], $validated['channel']);

        SecurityLogger::logRequest('invitation.send_many', $request, $request->user(), [
            'card_ids' => $validated['card_ids'],
            'channel' => $validated['channel'],
            'success' => $result['success'],
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? [],
        ], $result['success'] ? 200 : 422);
    }
}
