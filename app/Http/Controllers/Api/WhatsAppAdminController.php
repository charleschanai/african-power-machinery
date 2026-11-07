<?php

namespace App\Http\Controllers\Api;

use App\Enums\WhatsAppConversationStatus;
use App\Http\Controllers\Controller;
use App\Models\EventDetail;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppFaq;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppSettingsService;
use App\Services\SecurityLogger;
use App\Services\WhatsAppWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppAdminController extends Controller
{
    public function __construct(
        private readonly WhatsAppSettingsService $settingsService,
        private readonly WhatsAppWebhookService $webhookService,
    ) {
    }

    public function status(): JsonResponse
    {
        $settings = $this->settingsService->all();

        $conversations = WhatsAppConversation::query()->count();
        $matchedGuests = WhatsAppConversation::query()->whereNotNull('guest_id')->count();
        $messages = WhatsAppMessage::query()->count();
        $activeFaqs = WhatsAppFaq::query()->where('status', 'active')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'stats' => [
                    'conversations' => $conversations,
                    'matched_guests' => $matchedGuests,
                    'messages' => $messages,
                    'active_faqs' => $activeFaqs,
                ],
            ],
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->settingsService->all(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsapp_business_phone' => 'nullable|string|max:40',
            'phone_number_id' => 'nullable|string|max:40',
            'business_account_id' => 'nullable|string|max:40',
            'webhook_verify_token' => 'nullable|string|max:100',
            'ai_enabled' => 'required|boolean',
            'organizer_name' => 'nullable|string|max:190',
            'organizer_phone' => 'nullable|string|max:40',
            'default_language' => 'required|in:sw,en',
            'ai_disabled_message' => 'nullable|string',
            'unknown_guest_message' => 'nullable|string',
            'no_info_message' => 'nullable|string',
        ]);

        $this->settingsService->update($data);

        SecurityLogger::logRequest('whatsapp.settings.update', $request, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Settings saved.',
            'data' => $this->settingsService->all(),
        ]);
    }

    public function eventDetails(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => EventDetail::singleton(),
        ]);
    }

    public function updateEventDetails(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_name' => 'nullable|string|max:190',
            'event_description' => 'nullable|string',
            'event_date' => 'nullable|date',
            'event_time' => 'nullable|string|max:40',
            'venue' => 'nullable|string|max:190',
            'venue_address' => 'nullable|string|max:190',
            'google_maps_url' => 'nullable|url|max:500',
            'dress_code' => 'nullable|string|max:190',
            'parking_information' => 'nullable|string',
            'accommodation_information' => 'nullable|string',
            'contact_number' => 'nullable|string|max:40',
            'contact_person' => 'nullable|string|max:190',
            'event_program' => 'nullable|string',
            'rsvp_link' => 'nullable|url|max:500',
            'rsvp_information' => 'nullable|string',
            'gift_information' => 'nullable|string',
            'important_instructions' => 'nullable|string',
            'other_information' => 'nullable|string',
        ]);

        $event = EventDetail::singleton();
        $event->update($data);

        SecurityLogger::logRequest('whatsapp.event_details.update', $request, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Event details saved.',
            'data' => $event->refresh(),
        ]);
    }

    public function faqs(Request $request): JsonResponse
    {
        $faqs = WhatsAppFaq::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where(function ($query) use ($term) {
                    $query->where('question', 'like', "%{$term}%")
                        ->orWhere('answer', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json(['success' => true, 'data' => $faqs]);
    }

    public function storeFaq(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $faq = WhatsAppFaq::query()->create([
            'question' => $data['question'],
            'answer' => $data['answer'],
            'status' => $data['status'] ?? WhatsAppFaq::STATUS_ACTIVE,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'FAQ added.',
            'data' => $faq,
        ], 201);
    }

    public function updateFaq(Request $request, WhatsAppFaq $faq): JsonResponse
    {
        $data = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $faq->update($data);

        return response()->json([
            'success' => true,
            'message' => 'FAQ updated.',
            'data' => $faq->refresh(),
        ]);
    }

    public function toggleFaq(WhatsAppFaq $faq): JsonResponse
    {
        $faq->update([
            'status' => $faq->isActive() ? WhatsAppFaq::STATUS_INACTIVE : WhatsAppFaq::STATUS_ACTIVE,
        ]);

        return response()->json([
            'success' => true,
            'message' => $faq->isActive() ? 'FAQ enabled.' : 'FAQ disabled.',
            'data' => $faq->refresh(),
        ]);
    }

    public function destroyFaq(WhatsAppFaq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json([
            'success' => true,
            'message' => 'FAQ deleted.',
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $conversations = WhatsAppConversation::query()
            ->with(['guest'])
            ->withCount('messages')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where('phone', 'like', "%{$term}%")
                    ->orWhere('guest_name', 'like', "%{$term}%")
                    ->orWhereHas('guest', fn ($q) => $q->where('full_name', 'like', "%{$term}%"));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->when($request->filled('matched'), function ($query) use ($request) {
                if ($request->boolean('matched')) {
                    $query->whereNotNull('guest_id');
                } else {
                    $query->whereNull('guest_id');
                }
            })
            ->orderByDesc('last_message_at')
            ->paginate($request->integer('per_page', 15));

        $conversations->through(function (WhatsAppConversation $conversation) {
            $last = WhatsAppMessage::query()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('id')
                ->first();

            return [
                'id' => $conversation->id,
                'phone' => $conversation->phone,
                'guest_name' => $conversation->guest_name,
                'guest' => $conversation->guest ? [
                    'id' => $conversation->guest->id,
                    'full_name' => $conversation->guest->full_name,
                    'phone' => $conversation->guest->phone,
                ] : null,
                'status' => $conversation->status,
                'status_label' => WhatsAppConversationStatus::tryFrom($conversation->status)?->label(),
                'messages_count' => (int) $conversation->messages_count,
                'last_message' => $last?->body,
                'last_message_direction' => $last?->direction,
                'last_message_at' => $conversation->last_message_at?->toDateTimeString(),
                'created_at' => $conversation->created_at?->toDateTimeString(),
            ];
        });

        return response()->json(['success' => true, 'data' => $conversations]);
    }

    public function showConversation(WhatsAppConversation $conversation): JsonResponse
    {
        $conversation->load('guest');

        $messages = WhatsAppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (WhatsAppMessage $message) => [
                'id' => $message->id,
                'direction' => $message->direction->value,
                'sender_type' => $message->sender_type->value,
                'sender_type_label' => $message->sender_type->label(),
                'message_type' => $message->message_type,
                'body' => $message->body,
                'created_at' => $message->created_at?->toDateTimeString(),
            ]);

        $card = $conversation->guest?->cards()
            ->where('status', '!=', 'cancelled')
            ->latest('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'phone' => $conversation->phone,
                    'guest_name' => $conversation->guest_name,
                    'status' => $conversation->status,
                    'status_label' => WhatsAppConversationStatus::tryFrom($conversation->status)?->label(),
                    'last_message_at' => $conversation->last_message_at?->toDateTimeString(),
                    'created_at' => $conversation->created_at?->toDateTimeString(),
                ],
                'guest' => $conversation->guest ? [
                    'id' => $conversation->guest->id,
                    'full_name' => $conversation->guest->full_name,
                    'phone' => $conversation->guest->phone,
                    'email' => $conversation->guest->email,
                    'card_number' => $card?->card_number,
                    'card_url' => $card ? $card->publicUrl() : null,
                    'allowed_people' => $card?->allowed_people,
                ] : null,
                'messages' => $messages,
            ],
        ]);
    }

    public function replyConversation(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:4000',
        ]);

        $result = $this->webhookService->sendManualReply($conversation, $data['message'], $request->user()->id);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success']
                ? 'The message was sent to the guest.'
                : ($result['configured'] ? 'The message was saved but the WhatsApp API failed to send it. Use the wa.me link.' : 'The WhatsApp API is not configured. The message was only saved.'),
            'data' => [
                'sent' => $result['success'] ?? false,
                'configured' => $result['configured'] ?? false,
            ],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function takeOverConversation(WhatsAppConversation $conversation): JsonResponse
    {
        $conversation->update(['status' => WhatsAppConversationStatus::Manual->value]);

        return response()->json([
            'success' => true,
            'message' => 'The conversation has been taken over by an admin.',
            'data' => ['status' => $conversation->status],
        ]);
    }

    public function releaseConversation(WhatsAppConversation $conversation): JsonResponse
    {
        $conversation->update(['status' => WhatsAppConversationStatus::Ai->value]);

        return response()->json([
            'success' => true,
            'message' => 'The conversation has been returned to AI.',
            'data' => ['status' => $conversation->status],
        ]);
    }

    public function blockConversation(WhatsAppConversation $conversation): JsonResponse
    {
        $conversation->update(['status' => WhatsAppConversationStatus::Blocked->value]);

        return response()->json([
            'success' => true,
            'message' => 'This number has been blocked. Messages will not be answered automatically.',
            'data' => ['status' => $conversation->status],
        ]);
    }

    public function unblockConversation(WhatsAppConversation $conversation): JsonResponse
    {
        $conversation->update(['status' => WhatsAppConversationStatus::Ai->value]);

        return response()->json([
            'success' => true,
            'message' => 'This number has been unblocked.',
            'data' => ['status' => $conversation->status],
        ]);
    }
}
