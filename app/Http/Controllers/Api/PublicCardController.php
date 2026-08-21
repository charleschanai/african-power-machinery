<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use Illuminate\Http\JsonResponse;

class PublicCardController extends Controller
{
    public function show(string $qrToken): JsonResponse
    {
        $card = Card::query()
            ->with(['guest', 'cardTemplate'])
            ->where('qr_token', $qrToken)
            ->first();

        if (! $card) {
            return response()->json([
                'success' => false,
                'message' => 'Kadi hii haipatikani.',
                'error_code' => 'CARD_NOT_FOUND',
            ], 404);
        }

        $expired = $card->isExpired();

        return response()->json([
            'success' => true,
            'data' => [
                'card' => [
                    'card_number' => $card->card_number,
                    'card_type' => $card->card_type->value,
                    'card_type_label' => $card->card_type->label(),
                    'status' => $expired ? 'expired' : $card->status->value,
                    'status_label' => $expired ? 'Expired' : $card->status->label(),
                    'allowed_people' => $card->allowed_people,
                    'used_people' => $card->used_people,
                    'qr_token' => $card->qr_token,
                    'event_name' => $card->event_name,
                    'event_date' => $card->event_date?->toDateString(),
                    'event_time' => $card->event_time,
                    'venue' => $card->venue,
                    'table_number' => $card->table_number,
                    'seat_number' => $card->seat_number,
                    'special_message' => $card->special_message,
                    'template' => $card->cardTemplate ? [
                        'name' => $card->cardTemplate->name,
                        'card_type' => $card->cardTemplate->card_type->value,
                        'front_design' => $card->cardTemplate->frontUrl(),
                        'back_design' => $card->cardTemplate->backUrl(),
                    ] : null,
                ],
                'guest' => [
                    'full_name' => $card->guest?->full_name,
                    'phone' => $card->guest?->phone,
                    'email' => $card->guest?->email,
                ],
            ],
        ]);
    }
}
