<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EntryLog;
use App\Services\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntryLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = EntryLog::query()
            ->with(['guest', 'card', 'processor'])
            ->when($request->filled('result'), function ($query) use ($request) {
                $query->where('result', $request->input('result'));
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $query->whereHas('guest', function ($q) use ($request) {
                    $q->where('full_name', 'like', "%{$request->input('q')}%");
                });
            })
            ->orderByDesc('scanned_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    public function destroy(Request $request, EntryLog $entryLog): JsonResponse
    {
        SecurityLogger::logRequest('entry_log.destroy', $request, $request->user(), [
            'entry_log_id' => $entryLog->id,
        ]);

        $entryLog->delete();

        return response()->json([
            'success' => true,
            'message' => 'Entry log deleted.',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $logs = EntryLog::query()
            ->with(['guest', 'card', 'processor'])
            ->when($request->filled('result'), function ($query) use ($request) {
                $query->where('result', $request->input('result'));
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $query->whereHas('guest', function ($q) use ($request) {
                    $q->where('full_name', 'like', "%{$request->input('q')}%");
                });
            })
            ->orderByDesc('scanned_at')
            ->cursor();

        $filename = 'entry-logs-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Guest', 'Card Number', 'Attempt', 'Result', 'Gate', 'Processor', 'Time', 'Notes']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->guest?->full_name ?? 'Unknown',
                    $log->card?->card_number ?? '-',
                    $log->entry_number,
                    $log->result->label(),
                    $log->gate_name ?? '-',
                    $log->processor?->name ?? '-',
                    $log->scanned_at?->toDateTimeString() ?? '-',
                    $log->notes ?? '-',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
