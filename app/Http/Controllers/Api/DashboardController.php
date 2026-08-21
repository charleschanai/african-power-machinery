<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardTemplate;
use App\Models\EntryLog;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function statistics(Request $request): JsonResponse
    {
        $totalGuests = Guest::query()->count();
        $totalCards = Card::query()->count();
        $totalTemplates = CardTemplate::query()->count();
        $singleCards = Card::query()->where('card_type', 'single')->count();
        $doubleCards = Card::query()->where('card_type', 'double')->count();

        $allowedPeople = Card::query()->sum('allowed_people');
        $enteredPeople = Card::query()->sum('used_people');

        $allowedEntries = EntryLog::query()->where('result', 'allowed')->count();
        $deniedEntries = EntryLog::query()->whereNot('result', 'allowed')->count();

        $activeCards = Card::query()->where('status', 'active')->count();
        $usedCards = Card::query()->where('status', 'used')->count();
        $cancelledCards = Card::query()->where('status', 'cancelled')->count();

        $entriesByHour = EntryLog::query()
            ->where('result', 'allowed')
            ->selectRaw('HOUR(scanned_at) as hour, COUNT(*) as total')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->pluck('total', 'hour')
            ->toArray();

        $hourLabels = [];
        $hourValues = [];
        for ($h = 0; $h < 24; $h++) {
            $hourLabels[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
            $hourValues[] = $entriesByHour[$h] ?? 0;
        }

        $typeBreakdown = [
            ['label' => 'Single', 'value' => $singleCards],
            ['label' => 'Double', 'value' => $doubleCards],
        ];

        $resultBreakdown = [
            ['label' => 'Allowed', 'value' => $allowedEntries],
            ['label' => 'Denied', 'value' => $deniedEntries],
        ];

        $recentLogs = EntryLog::query()
            ->with(['guest', 'card', 'processor'])
            ->orderByDesc('scanned_at')
            ->limit(10)
            ->get()
            ->map(fn (EntryLog $log) => [
                'id' => $log->id,
                'guest_name' => $log->guest?->full_name ?? 'Unknown',
                'card_number' => $log->card?->card_number ?? '-',
                'result' => $log->result->value,
                'result_label' => $log->result->label(),
                'scanned_at' => $log->scanned_at?->toDateTimeString(),
                'processor_name' => $log->processor?->name ?? '-',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => [
                    'guests' => $totalGuests,
                    'cards' => $totalCards,
                    'templates' => $totalTemplates,
                    'single_cards' => $singleCards,
                    'double_cards' => $doubleCards,
                    'allowed_people' => $allowedPeople,
                    'entered_people' => $enteredPeople,
                    'remaining_people' => max(0, $allowedPeople - $enteredPeople),
                    'allowed_entries' => $allowedEntries,
                    'denied_entries' => $deniedEntries,
                    'active_cards' => $activeCards,
                    'used_cards' => $usedCards,
                    'cancelled_cards' => $cancelledCards,
                ],
                'entries_by_hour' => [
                    'labels' => $hourLabels,
                    'values' => $hourValues,
                ],
                'type_breakdown' => $typeBreakdown,
                'result_breakdown' => $resultBreakdown,
                'recent_logs' => $recentLogs,
            ],
        ]);
    }
}
