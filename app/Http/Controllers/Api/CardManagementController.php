<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Invitation;
use App\Models\InvitationSend;
use App\Services\InvitationMessageService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CardManagementController extends Controller
{
    private function type(Request $request): string
    {
        return $request->route('type');
    }

    public function recipients(Request $request): JsonResponse
    {
        $type = $this->type($request);
        abort_unless(in_array($type, ['single', 'double'], true), 404);
        $cards = Card::query()->where('card_type', $type)->with('guest')->get();
        $recipients = $cards->filter(fn (Card $card) => filled($card->guest?->phone))
            ->map(fn (Card $card) => [
                'card_id' => $card->id, 'guest_id' => $card->guest_id,
                'name' => $card->guest->full_name, 'phone' => $card->guest->phone,
                'card_number' => $card->card_number,
            ])->values();
        return response()->json(['data' => $recipients]);
    }

    public function smsHistory(Request $request, InvitationMessageService $messages): JsonResponse
    {
        $type = $this->type($request);
        abort_unless(in_array($type, ['single', 'double'], true), 404);

        $normalMessages = DB::table('card_normal_sms')
            ->where('card_type', $type)
            ->latest()
            ->limit(300)
            ->get()
            ->map(fn ($row) => [
                'id' => 'normal-'.$row->id,
                'created_at' => $row->created_at,
                'recipient_name' => $row->recipient_name,
                'phone' => $row->phone,
                'message' => $row->message,
                'status' => $row->status,
                'error' => $row->error,
                'source' => 'Normal SMS',
                'kind' => 'Message',
            ]);

        $invitationSends = InvitationSend::query()
            ->where('channel', 'sms')
            ->whereHas('card', fn ($query) => $query->where('card_type', $type))
            ->with('card.guest')
            ->orderByRaw('COALESCE(sent_at, queued_at, created_at) DESC')
            ->limit(300)
            ->get();

        $invitations = Invitation::query()
            ->whereIn('card_id', $invitationSends->pluck('card_id')->unique())
            ->with(['guest', 'card'])
            ->get()
            ->keyBy('card_id');

        $invitationHistory = $invitationSends->map(function (InvitationSend $send) use ($invitations, $messages) {
            $invitation = $invitations->get($send->card_id);
            $kind = $send->kind?->value ?? 'invitation';
            $body = $invitation
                ? ($kind === 'reminder' ? $messages->renderReminder($invitation) : $messages->renderMessage($invitation))
                : 'SMS sent through Invitations';

            return [
                'id' => 'invitation-'.$send->id,
                'created_at' => ($send->sent_at ?? $send->queued_at ?? $send->created_at)?->toDateTimeString(),
                'recipient_name' => $send->card?->guest?->full_name,
                'phone' => $send->phone_e164 ?? $invitation?->phone ?? $send->card?->guest?->phone,
                'message' => $body,
                'status' => $send->status?->value ?? (string) $send->getRawOriginal('status'),
                'error' => $send->error,
                'source' => 'Invitations',
                'kind' => $kind === 'reminder' ? 'Reminder' : 'Invitation',
            ];
        });

        // Some older Invitation sends only updated the reusable Invitation
        // timestamps and did not leave an invitation_sends row. Include those
        // records too, but only when the linked card has the requested type.
        $loggedKinds = $invitationSends
            ->map(fn (InvitationSend $send) => $send->card_id.':'.($send->kind?->value ?? 'invitation'))
            ->all();
        $legacyInvitations = Invitation::query()
            ->whereHas('card', fn ($query) => $query->where('card_type', $type))
            ->where(fn ($query) => $query->whereNotNull('last_sms_sent_at')->orWhereNotNull('last_reminder_sent_at'))
            ->with(['guest', 'card'])
            ->get();

        $legacyHistory = $legacyInvitations->flatMap(function (Invitation $invitation) use ($loggedKinds, $messages) {
            $entries = [];
            foreach ([
                'invitation' => [$invitation->last_sms_sent_at, $invitation->message],
                'reminder' => [$invitation->last_reminder_sent_at, $invitation->reminder_message],
            ] as $kind => [$sentAt, $template]) {
                if (! $sentAt || in_array($invitation->card_id.':'.$kind, $loggedKinds, true)) {
                    continue;
                }

                $entries[] = [
                    'id' => 'legacy-'.$kind.'-'.$invitation->id,
                    'created_at' => $sentAt->toDateTimeString(),
                    'recipient_name' => $invitation->guest?->full_name,
                    'phone' => $invitation->phone,
                    'message' => $kind === 'reminder'
                        ? $messages->renderReminder($invitation, $template)
                        : $messages->renderMessage($invitation),
                    'status' => $invitation->status?->value ?? 'sent',
                    'error' => $invitation->last_error,
                    'source' => 'Invitations',
                    'kind' => $kind === 'reminder' ? 'Reminder' : 'Invitation',
                ];
            }

            return $entries;
        });

        $history = $normalMessages->concat($invitationHistory)->concat($legacyHistory)
            ->sortByDesc('created_at')
            ->take(300)
            ->values();

        return response()->json(['data' => $history]);
    }

    public function sendSms(Request $request, SmsService $sms): JsonResponse
    {
        $type = $this->type($request);
        abort_unless(in_array($type, ['single', 'double'], true), 404);
        $data = $request->validate([
            'card_ids' => ['required', 'array', 'min:1'],
            'card_ids.*' => ['integer', 'distinct', Rule::exists('cards', 'id')->where('card_type', $type)],
            'message' => ['required', 'string', 'max:5000'],
        ]);
        $cards = Card::query()->where('card_type', $type)->whereIn('id', $data['card_ids'])->with('guest')->get();
        $results = [];
        foreach ($cards as $card) {
            $guest = $card->guest;
            $result = $guest && filled($guest->phone) ? $sms->send($guest->phone, $data['message']) : ['success' => false, 'message' => 'Recipient has no phone number.'];
            DB::table('card_normal_sms')->insert([
                'card_type' => $type, 'card_id' => $card->id, 'guest_id' => $guest?->id,
                'recipient_name' => $guest?->full_name, 'phone' => $guest?->phone ?? '', 'message' => $data['message'],
                'status' => ($result['success'] ?? false) ? 'sent' : 'failed',
                'provider_message_id' => $result['message_id'] ?? null, 'error' => ($result['success'] ?? false) ? null : ($result['message'] ?? 'SMS failed'),
                'sent_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $results[] = ['card_id' => $card->id, 'name' => $guest?->full_name, 'success' => $result['success'] ?? false, 'message' => $result['message'] ?? null];
        }
        return response()->json(['data' => $results, 'message' => count(array_filter($results, fn ($r) => $r['success'])).' sent; '.count(array_filter($results, fn ($r) => ! $r['success'])).' failed.']);
    }

    public function workHistory(Request $request): JsonResponse
    {
        $type = $this->type($request);
        abort_unless(in_array($type, ['single', 'double'], true), 404);
        return response()->json(['data' => DB::table('card_shared_work')->where('card_type', $type)->latest()->get()]);
    }

    public function saveWork(Request $request, string $type, ?string $id = null): JsonResponse
    {
        abort_unless(in_array($type, ['single', 'double'], true), 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:20000']]);
        if ($id) {
            $row = DB::table('card_shared_work')->where('id', (int) $id)->where('card_type', $type)->first();
            abort_unless($row, 404);
            DB::table('card_shared_work')->where('id', (int) $id)->where('card_type', $type)->update([...$data, 'updated_at' => now()]);
            return response()->json(['message' => 'Work updated.']);
        }
        $newId = DB::table('card_shared_work')->insertGetId([...$data, 'card_type' => $type, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $newId, 'message' => 'Work shared.'], 201);
    }

    public function deleteWork(Request $request, int $id): JsonResponse
    {
        $type = $this->type($request);
        abort_unless(in_array($type, ['single', 'double'], true), 404);
        $row = DB::table('card_shared_work')->where('id', $id)->where('card_type', $type)->first();
        abort_unless($row, 404);
        abort_unless($row->created_by === $request->user()->id || $request->user()->role === 'admin', 403);
        DB::table('card_shared_work')->where('id', $id)->delete();
        return response()->json(['message' => 'Work deleted.']);
    }
}
