<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvitationRequest;
use App\Http\Requests\UpdateInvitationRequest;
use App\Models\Card;
use App\Models\CardTemplate;
use App\Models\EventDetail;
use App\Models\Guest;
use App\Models\Invitation;
use App\Services\InvitationMessageService;
use App\Services\MessageTemplateRenderer;
use App\Services\PhoneHelper;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationMessageService $messages,
        private readonly MessageTemplateRenderer $renderer,
        private readonly SmsService $sms,
    ) {
    }

    /**
     * The saved invitations, one row per guest+card, with the reminder
     * filters the admin sidebar needs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invitation::query()
            ->search($request->query('q'))
            ->filter($request->query('filter'))
            ->when(in_array($request->query('card_type'), ['single', 'double'], true), fn ($query) =>
                $query->whereHas('card', fn ($cardQuery) => $cardQuery->where('card_type', $request->query('card_type')))
            );

        $paginator = $query
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15))
            ->withQueryString()
            ->through(fn (Invitation $invitation) => $invitation->toFrontendArray());

        return response()->json($paginator);
    }

    /**
     * Options for the create/edit form: guests, their cards, and the
     * event defaults already stored in EventDetail.
     */
    public function formOptions(): JsonResponse
    {
        $guests = Guest::query()
            ->search(request()->query('q'))
            ->orderBy('full_name')
            ->with(['cards' => fn ($query) => $query->whereNotNull('card_template_id')->with('cardTemplate')])
            ->get()
            ->map(fn (Guest $guest) => [
                'id' => $guest->id,
                'full_name' => $guest->full_name,
                'phone' => $guest->phone,
                'phone_display' => PhoneHelper::toDisplay($guest->phone),
                'cards' => $guest->cards
                    ->map(fn (Card $card) => [
                        'id' => $card->id,
                        'card_type' => $card->card_type?->value ?? $card->getRawOriginal('card_type'),
                        'card_number' => $card->card_number,
                        'template_name' => $card->cardTemplate?->name,
                        'image' => $card->cardTemplate?->frontUrl(),
                        'event_name' => $card->event_name,
                        'event_date' => $card->event_date?->format('Y-m-d'),
                        'event_time' => $card->event_time,
                        'venue' => $card->venue,
                        // The guest's own link, so the preview can show the
                        // message exactly as it will arrive rather than with an
                        // empty line where the link belongs.
                        'e_card_url' => filled($card->qr_token)
                            ? Invitation::publicBaseUrl() . '/e-card/' . $card->qr_token
                            : null,
                    ]),
            ]);

        $event = EventDetail::singleton();
        $cardDefaults = collect(['single', 'double'])->mapWithKeys(fn (string $type) => [
            $type => [
                'wedding_details' => $this->messages->defaultWeddingDetails($type),
                'message' => $this->messages->defaultMessage(),
                'reminder_message' => $this->messages->defaultReminder($type),
                'event_name' => 'Harusi ya Solomon Jamson & Winfrida Tesha',
                'event_date' => '2026-11-14',
                'event_time' => '',
                'venue' => '',
            ],
        ]);

        return response()->json([
            'data' => [
                'guests' => $guests,
                'templates' => CardTemplate::query()
                    ->where('status', 'active')
                    ->get()
                    ->map(fn (CardTemplate $template) => [
                        'id' => $template->id,
                        'name' => $template->name,
                        'image' => $template->frontUrl(),
                    ]),
                'event' => [
                    'event_name' => $event->event_name,
                    'event_date' => $event->event_date?->format('Y-m-d'),
                    'event_time' => $event->event_time,
                    'venue' => $event->venue,
                ],
                'default_message' => $this->messages->defaultMessage(),
                'default_reminder' => $this->messages->defaultReminder(),
                'default_wedding_details' => $this->messages->defaultWeddingDetails(),
                'card_defaults' => $cardDefaults,
                'public_url' => Invitation::publicBaseUrl(),
                'sms_configured' => $this->sms->isConfigured(),
            ],
        ]);
    }

    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Fill event fields from the selected card if the admin left them blank.
        if ($cardId = $data['card_id'] ?? null) {
            $card = Card::with('cardTemplate')->find($cardId);

            if ($card) {
                $data['event_name'] ??= $card->event_name ?: $card->cardTemplate?->name;
                $data['event_date'] ??= $card->event_date?->format('Y-m-d');
                $data['event_time'] ??= $card->event_time;
                $data['venue'] ??= $card->venue;
            }
        }

        $invitation = Invitation::query()->create($data);

        return response()->json([
            'success' => true,
            'message' => 'Invitation saved.',
            'data' => $invitation->fresh()->toFrontendArray(),
        ], 201);
    }

    public function show(Invitation $invitation): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$invitation->toFrontendArray(),
                'rendered_message' => $this->messages->renderMessage($invitation),
                'rendered_reminder' => $this->messages->renderReminder($invitation),
                'placeholders' => MessageTemplateRenderer::PLACEHOLDERS,
            ],
        ]);
    }

    public function update(UpdateInvitationRequest $request, Invitation $invitation): JsonResponse
    {
        $invitation->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Invitation updated.',
            'data' => $invitation->fresh()->toFrontendArray(),
        ]);
    }

    public function destroy(Invitation $invitation): JsonResponse
    {
        $invitation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Invitation deleted.',
        ]);
    }

    /**
     * Sends the saved invitation message to one guest.
     */
    public function sendSms(Invitation $invitation): JsonResponse
    {
        $result = $this->messages->sendInvitation($invitation);

        return response()->json([
            ...$result,
            'data' => $invitation->fresh()->toFrontendArray(),
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Sends the saved reminder message. An edited message can be passed in
     * without saving it first, as the brief requires.
     */
    public function sendReminder(Request $request, Invitation $invitation): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $override = $validated['message'] ?? null;

        // Persist an edited reminder so the next reminder starts from it.
        if ($override !== null && trim($override) !== '') {
            $invitation->forceFill(['reminder_message' => trim($override)])->save();
        }

        $result = $this->messages->sendReminder($invitation, $override);

        return response()->json([
            ...$result,
            'data' => $invitation->fresh()->toFrontendArray(),
        ], $result['success'] ? 200 : 422);
    }

    /**
     * Bulk SMS for the selected invitations, each using its own saved
     * message and phone number.
     */
    public function bulkSendSms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:invitations,id'],
        ]);

        $sent = 0;
        $failed = 0;
        $errors = [];

        $invitations = Invitation::query()
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($invitations as $invitation) {
            $result = $this->messages->sendInvitation($invitation);

            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = [
                    'guest' => $invitation->guest?->full_name ?? 'Guest',
                    'message' => $result['message'],
                ];
            }
        }

        return response()->json([
            'success' => $failed === 0,
            'message' => "Sent {$sent} of " . count($validated['ids']) . '. '
                . ($failed > 0 ? "{$failed} failed." : 'All delivered.'),
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    /**
     * Records that the admin shared the card via WhatsApp. The actual share
     * is done by the browser's share sheet, so there is no provider call.
     */
    public function markWhatsAppShared(Invitation $invitation): JsonResponse
    {
        $result = $this->messages->markWhatsAppShared($invitation);

        return response()->json([
            ...$result,
            'data' => $invitation->fresh()->toFrontendArray(),
        ]);
    }

    /**
     * Aggregate counts for the list header.
     *
     * These reuse the same scopes as the filters, so a count on a card always
     * agrees with the number of rows its filter returns.
     */
    public function summary(): JsonResponse
    {
        $base = Invitation::query();
        $publicUrl = Invitation::publicUrlStatus();
        $cardCounts = collect(['single', 'double'])->mapWithKeys(function (string $type) use ($base) {
            $typed = fn () => (clone $base)->whereHas('card', fn ($query) => $query->where('card_type', $type));

            return [$type => [
                'total' => $typed()->count(),
                'sent' => $typed()->sent()->count(),
                'not_sent' => $typed()->notSent()->count(),
            ]];
        });

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'card_counts' => $cardCounts,
                'sent' => (clone $base)->sent()->count(),
                'not_sent' => (clone $base)->notSent()->count(),
                'failed' => (clone $base)
                    ->where('status', InvitationState::Failed->value)
                    ->count(),
                'reminder_sent' => (clone $base)->whereNotNull('last_reminder_sent_at')->count(),
                'reminder_not_sent' => (clone $base)
                    ->sent()
                    ->whereNull('last_reminder_sent_at')
                    ->count(),
                'sms_configured' => $this->sms->isConfigured(),

                // Shared links are built from ECARD_PUBLIC_URL, so the admin is
                // told plainly when that address cannot be opened on a guest's
                // phone, rather than finding out after paying for the SMS.
                // The status carries the reason: a tunnel that is off, and one
                // that answers with a warning page, both need different fixes.
                'public_url' => Invitation::publicBaseUrl(),
                'public_url_ok' => $publicUrl['ok'],
                'public_url_status' => $publicUrl,
            ],
        ]);
    }
}
