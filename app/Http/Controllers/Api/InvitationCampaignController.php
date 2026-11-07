<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvitationSend;
use App\Services\InvitationCampaignService;
use App\Services\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationCampaignController extends Controller
{
    public function __construct(private readonly InvitationCampaignService $campaign)
    {
    }

    public function report(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->campaign->report(),
        ]);
    }

    public function preview(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->campaign->preview(),
        ]);
    }

    public function dispatchAll(): JsonResponse
    {
        $result = $this->campaign->dispatchAll();

        SecurityLogger::logRequest('invitation_campaign.dispatch_all', request(), request()->user(), [
            'dispatched' => $result['dispatched'],
            'skipped' => $result['skipped'],
            'messages' => $result['messages'],
        ]);

        return response()->json([
            'success' => $result['dispatched'] > 0,
            'message' => $result['dispatched'] > 0
                ? "Invitations queued. Guests: {$result['dispatched']}. Messages: {$result['messages']}."
                : 'No new invitations to queue. All guests have already received invitations.',
            'data' => $result,
        ], $result['dispatched'] > 0 ? 200 : 422);
    }

    public function clearSends(Request $request): JsonResponse
    {
        $deleted = InvitationSend::query()->delete();

        SecurityLogger::logRequest('invitation_campaign.clear_sends', $request, $request->user(), [
            'deleted' => $deleted,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Send history cleared. {$deleted} record(s) deleted.",
            'data' => ['deleted' => $deleted],
        ]);
    }

    public function retryFailed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => 'nullable|in:whatsapp,sms',
        ]);

        $result = $this->campaign->retryFailed($validated['channel'] ?? null);

        SecurityLogger::logRequest('invitation_campaign.retry_failed', $request, $request->user(), [
            'channel' => $validated['channel'] ?? null,
            'queued' => $result['queued'],
        ]);

        return response()->json([
            'success' => $result['queued'] > 0,
            'message' => $result['queued'] > 0
                ? "{$result['queued']} previously failed message(s) have been queued for a retry."
                : 'There are no failed messages to retry.',
            'data' => $result,
        ], $result['queued'] > 0 ? 200 : 422);
    }

    public function sends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'channel' => 'nullable|in:whatsapp,sms',
            'status' => 'nullable|in:queued,sent,delivered,failed',
            'q' => 'nullable|string|max:100',
        ]);

        $query = InvitationSend::query()
            ->with(['card.guest'])
            ->orderByDesc('id');

        if (filled($validated['channel'] ?? null)) {
            $query->where('channel', $validated['channel']);
        }

        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        if (filled($validated['q'] ?? null)) {
            $needle = trim($validated['q']);

            $query->where(function ($query) use ($needle) {
                $query->whereHas('card', function ($card) use ($needle) {
                    $card->where('card_number', 'like', "%{$needle}%");
                })->orWhereHas('card.guest', function ($guest) use ($needle) {
                    $guest->where('full_name', 'like', "%{$needle}%")
                        ->orWhere('phone', 'like', "%{$needle}%");
                });
            });
        }

        return response()->json($query->paginate($validated['per_page'] ?? 15));
    }

    public function sendSmsOnly(Request $request): JsonResponse
    {
        $result = $this->campaign->dispatchSmsOnly();

        SecurityLogger::logRequest('invitation_campaign.send_sms_only', $request, $request->user(), [
            'dispatched' => $result['dispatched'],
            'skipped' => $result['skipped'],
        ]);

        return response()->json([
            'success' => $result['dispatched'] > 0,
            'message' => $result['dispatched'] > 0
                ? "SMS invitations queued for {$result['dispatched']} guest(s)."
                : 'No guests available for SMS. Check that guests have phone numbers and SMS is configured.',
            'data' => $result,
        ], $result['dispatched'] > 0 ? 200 : 422);
    }

    public function sendSmsScheduled(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_at' => 'required|date|after:now',
        ]);

        $result = $this->campaign->dispatchSmsScheduled($validated['scheduled_at']);

        SecurityLogger::logRequest('invitation_campaign.send_sms_scheduled', $request, $request->user(), [
            'dispatched' => $result['dispatched'],
            'skipped' => $result['skipped'],
            'scheduled_at' => $validated['scheduled_at'],
        ]);

        return response()->json([
            'success' => $result['dispatched'] > 0,
            'message' => $result['dispatched'] > 0
                ? "SMS invitations scheduled for " . \Carbon\Carbon::parse($validated['scheduled_at'])->format('d/m/Y H:i') . ". Guests: {$result['dispatched']}."
                : 'No guests available for SMS scheduling.',
            'data' => $result,
        ], $result['dispatched'] > 0 ? 200 : 422);
    }
}
