<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardImageController extends Controller
{
    public function show(Card $card): JsonResponse
    {
        $card->load('guest', 'cardTemplate');

        $cardUrl = $card->publicUrl();

        return response()->json([
            'success' => true,
            'data' => [
                'card' => $card,
                'card_url' => $cardUrl,
                'guest_name' => $card->guest?->full_name ?? 'Guest',
                'card_number' => $card->card_number,
                'event_name' => $card->event_name,
                'event_date' => $card->event_date?->format('d/m/Y'),
                'event_time' => $card->event_time,
                'venue' => $card->venue,
                'table_number' => $card->table_number,
                'seat_number' => $card->seat_number,
                'special_message' => $card->special_message,
            ],
        ]);
    }

    public function composite(Card $card, string $side = 'front'): StreamedResponse
    {
        $card->load('cardTemplate', 'guest');

        if (! $card->cardTemplate) {
            return response()->json([
                'success' => false,
                'message' => 'This card has no template assigned.',
            ], 422);
        }

        $imageUrl = $side === 'back'
            ? $card->cardTemplate->backUrl()
            : $card->cardTemplate->frontUrl();

        if (! $imageUrl) {
            return response()->json([
                'success' => false,
                'message' => 'No ' . $side . ' design found.',
            ], 422);
        }

        $cardUrl = $card->publicUrl();
        $outputPath = storage_path("app/public/composites/{$card->card_number}-{$side}.png");

        File::ensureDirectoryExists(dirname($outputPath));

        $script = base_path('generate_composite.mjs');
        $escapedImage = escapeshellarg($imageUrl);
        $escapedQr = escapeshellarg($cardUrl);
        $escapedOutput = escapeshellarg($outputPath);

        exec("node {$script} {$escapedImage} {$escapedQr} {$escapedOutput} 2>&1", $output, $exitCode);

        if ($exitCode !== 0 || ! file_exists($outputPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate composite image.',
                'debug' => implode("\n", $output),
            ], 500);
        }

        return response()->stream(function () use ($outputPath) {
            readfile($outputPath);
        }, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="card-composite.png"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
