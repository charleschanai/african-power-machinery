<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WeeklyReminderService;
use App\Services\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyReminderController extends Controller
{
    public function __construct(
        private readonly WeeklyReminderService $reminder,
    ) {
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $result = $this->reminder->getHistory(
            $validated['page'] ?? 1,
            $validated['per_page'] ?? 15,
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function weeks(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reminder->getAvailableWeeks(),
        ]);
    }

    public function weekSummary(Request $request, int $year, int $weekNumber): JsonResponse
    {
        $result = $this->reminder->getWeekSummary($year, $weekNumber);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function sendNow(Request $request): JsonResponse
    {
        $result = $this->reminder->sendWeeklyReminders();

        SecurityLogger::logRequest('weekly_reminder.send_now', $request, $request->user(), [
            'dispatched' => $result['dispatched'],
            'failed' => $result['failed'],
            'skipped' => $result['skipped'],
        ]);

        return response()->json([
            'success' => $result['dispatched'] > 0,
            'message' => $result['dispatched'] > 0
                ? "Ukumbusho umetumwa kwa wageni {$result['dispatched']}. (Wiki {$result['week_number']}/{$result['year']})"
                : "Hakuna ukumbusho mpya kutumwa wiki hii.",
            'data' => $result,
        ], $result['dispatched'] > 0 ? 200 : 422);
    }
}
