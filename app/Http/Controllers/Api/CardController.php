<?php

namespace App\Http\Controllers\Api;

use App\Enums\CardType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmEntryRequest;
use App\Http\Requests\ImportCardsRequest;
use App\Http\Requests\StoreCardRequest;
use App\Http\Requests\UpdateCardRequest;
use App\Http\Requests\VerifyCardRequest;
use App\Models\Card;
use App\Models\Guest;
use App\Services\CardVerificationService;
use App\Services\EmailService;
use App\Services\InvitationCampaignService;
use App\Services\PhoneHelper;
use App\Services\SecurityLogger;
use App\Services\SmsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardController extends Controller
{
    public function __construct(private readonly CardVerificationService $cardService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Card::class);

        $cards = Card::query()
            ->with(['guest', 'cardTemplate'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where(function ($sub) use ($term) {
                    $sub->where('card_number', 'like', "%{$term}%")
                        ->orWhereHas('guest', function ($q) use ($term) {
                            $q->where('full_name', 'like', "%{$term}%");
                        });
                });
            })
            ->when($request->filled('card_template_id'), function ($query) use ($request) {
                $query->where('card_template_id', $request->integer('card_template_id'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $status = $request->input('status');

                if ($status === 'expired') {
                    $query->where('status', 'active')
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', now());
                } else {
                    $query->where('status', $status);
                }
            })
            ->when($request->filled('card_type'), function ($query) use ($request) {
                $query->where('card_type', $request->input('card_type'));
            })
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $cards,
        ]);
    }

    public function store(StoreCardRequest $request): JsonResponse
    {
        $this->authorize('create', Card::class);

        $type = CardType::from($request->input('card_type'));

        $card = $this->createCard(
            $request->integer('guest_id'),
            $type,
            $request->input('expires_at'),
            $request->input('card_number'),
            $this->eventAttributes($request),
        );

        $card->load(['guest', 'cardTemplate']);

        SecurityLogger::logRequest('card.store', $request, $request->user(), ['card_id' => $card->id]);

        return response()->json([
            'success' => true,
            'message' => 'The card has been created.',
            'data' => [
                'card' => $card,
                'card_url' => $card->publicUrl(),
            ],
        ], 201);
    }

    public function update(UpdateCardRequest $request, Card $card): JsonResponse
    {
        $this->authorize('update', $card);

        // guest_id is dropped on purpose: a card belongs to the guest it was
        // issued to, and editing the event details must never move it to a
        // different person. Reassigning a card is a separate, deliberate action.
        $data = $request->safe()->except(['card_type', 'guest_id']);

        if ($request->filled('card_type') && $request->input('card_type') !== $card->card_type->value) {
            $type = CardType::from($request->input('card_type'));

            $data['card_type'] = $type;

            if ($card->used_people === 0) {
                $data['allowed_people'] = $type->allowedPeople();
            }
        }

        $card->update($data);

        $card->load(['guest', 'cardTemplate']);

        SecurityLogger::logRequest('card.update', $request, $request->user(), ['card_id' => $card->id]);

        return response()->json([
            'success' => true,
            'message' => 'The card has been updated.',
            'data' => [
                'card' => $card,
                'card_url' => $card->publicUrl(),
            ],
        ]);
    }

    public function importTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['name', 'phone', 'email', 'type', 'card_number', 'expires']);
            fputcsv($handle, ['Juma Ally', '0754123456', 'juma@example.com', 'single', 'EC-000100', '2026-12-31 23:59']);
            fputcsv($handle, ['Asha Hassan', '0711223344', '', 'double', '', '']);

            fclose($handle);
        }, 'card-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(ImportCardsRequest $request): JsonResponse
    {
        $this->authorize('create', Card::class);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return response()->json([
                'success' => false,
                'message' => 'The file could not be read. Make sure it is a valid CSV.',
            ], 422);
        }

        $headers = fgetcsv($handle) ?? [];
        $columnMap = $this->columnMap($this->normalizeHeaders($headers));

        if (! isset($columnMap['full_name'], $columnMap['phone'])) {
            fclose($handle);

            return response()->json([
                'success' => false,
                'message' => 'The file format is not valid. The first row must have "name" and "phone" headers.',
            ], 422);
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;
        $seenCardNumbers = [];

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                $record = $this->rowToRecord($row, $columnMap);

                $fullName = trim((string) ($record['full_name'] ?? ''));
                $phone = trim((string) ($record['phone'] ?? ''));
                $cardType = strtolower(trim((string) ($record['card_type'] ?? 'single')));
                $cardNumber = trim((string) ($record['card_number'] ?? ''));

                if ($fullName === '' || $phone === '') {
                    $skipped++;
                    $errors[] = "Row $rowNumber: Name and phone number are required.";

                    continue;
                }

                if (! in_array($cardType, ['single', 'double'], true)) {
                    $skipped++;
                    $errors[] = "Row $rowNumber: Card type \"$cardType\" is not valid. Use single or double.";

                    continue;
                }

                if ($cardNumber !== '') {
                    if (isset($seenCardNumbers[$cardNumber])
                        || Card::query()->where('card_number', $cardNumber)->exists()) {
                        $skipped++;
                        $errors[] = "Row $rowNumber: Card number \"$cardNumber\" is already in use.";

                        continue;
                    }

                    $seenCardNumbers[$cardNumber] = true;
                }

                $guest = Guest::query()->firstOrCreate(
                    ['phone' => $phone],
                    [
                        'full_name' => $fullName,
                        'email' => $this->nullableString($record['email'] ?? null),
                    ],
                );

                try {
                    $this->createCard(
                        $guest->id,
                        CardType::from($cardType),
                        $this->nullableDate($record['expires_at'] ?? null),
                        $cardNumber === '' ? null : $cardNumber,
                    );
                    $imported++;
                } catch (QueryException) {
                    $skipped++;
                    $errors[] = "Row $rowNumber: Failed to create a card for $fullName.";
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);

            throw $e;
        }

        DB::commit();
        fclose($handle);

        SecurityLogger::logRequest('card.import', $request, $request->user(), [
            'imported' => $imported,
            'skipped' => $skipped,
        ]);

        return response()->json([
            'success' => true,
            'message' => "$imported card(s) imported; $skipped skipped.",
            'data' => [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Card::class);

        $cards = Card::query()
            ->with(['guest', 'cardTemplate'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where(function ($sub) use ($term) {
                    $sub->where('card_number', 'like', "%{$term}%")
                        ->orWhereHas('guest', function ($q) use ($term) {
                            $q->where('full_name', 'like', "%{$term}%");
                        });
                });
            })
            ->when($request->filled('card_template_id'), function ($query) use ($request) {
                $query->where('card_template_id', $request->integer('card_template_id'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $status = $request->input('status');

                if ($status === 'expired') {
                    $query->where('status', 'active')
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', now());
                } else {
                    $query->where('status', $status);
                }
            })
            ->when($request->filled('card_type'), function ($query) use ($request) {
                $query->where('card_type', $request->input('card_type'));
            })
            ->orderByDesc('id')
            ->cursor();

        $filename = 'cards-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($cards) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Card Number', 'Guest', 'Phone Number', 'Type', 'Allowed People',
                'Entered', 'Status', 'Issued', 'Expires',
            ]);

            foreach ($cards as $card) {
                fputcsv($handle, [
                    $card->card_number,
                    $card->guest?->full_name ?? '-',
                    $card->guest?->phone ?? '-',
                    $card->card_type->label(),
                    $card->allowed_people,
                    $card->used_people,
                    $card->displayStatusLabel(),
                    $card->issued_at?->toDateTimeString() ?? '-',
                    $card->expires_at?->toDateTimeString() ?? '-',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Card $card): JsonResponse
    {
        $card->load('guest', 'cardTemplate', 'entryLogs');

        return response()->json([
            'success' => true,
            'data' => [
                'card' => $card,
                'card_url' => $card->publicUrl(),
            ],
        ]);
    }

    public function verify(VerifyCardRequest $request): JsonResponse
    {
        $result = $this->cardService->verifyCard($request->identifier());

        return response()->json(
            $result->toArray(),
            $result->success ? 200 : 422
        );
    }

    public function confirmEntry(ConfirmEntryRequest $request): JsonResponse
    {
        $result = $this->cardService->confirmEntry(
            $request->identifier(),
            $request->user(),
            $request->context(),
        );

        return response()->json(
            $result->toArray(),
            $result->success ? 200 : 422
        );
    }

    public function cancel(Request $request, Card $card): JsonResponse
    {
        $this->authorize('cancel', $card);

        if ($card->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This card has already been cancelled.',
                'error_code' => 'CARD_ALREADY_CANCELLED',
            ], 422);
        }

        $card->update(['status' => 'cancelled']);

        SecurityLogger::logRequest('card.cancel', $request, $request->user(), ['card_id' => $card->id]);

        return response()->json([
            'success' => true,
            'message' => 'The card has been cancelled.',
            'data' => $card,
        ]);
    }

    public function destroy(Request $request, Card $card): JsonResponse
    {
        $this->authorize('delete', $card);

        SecurityLogger::logRequest('card.destroy', $request, $request->user(), ['card_id' => $card->id]);

        $card->delete();

        return response()->json([
            'success' => true,
            'message' => 'The card has been deleted.',
        ]);
    }

    public function exportLinks(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Card::class);

        $cards = Card::query()
            ->with('guest')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where(function ($sub) use ($term) {
                    $sub->where('card_number', 'like', "%{$term}%")
                        ->orWhereHas('guest', function ($q) use ($term) {
                            $q->where('full_name', 'like', "%{$term}%");
                        });
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $status = $request->input('status');
                if ($status === 'expired') {
                    $query->where('status', 'active')
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', now());
                } else {
                    $query->where('status', $status);
                }
            })
            ->when($request->filled('card_type'), function ($query) use ($request) {
                $query->where('card_type', $request->input('card_type'));
            })
            ->orderByDesc('id')
            ->get();

        $links = $cards->map(function ($card) {
            return [
                'guest' => $card->guest?->full_name ?? '-',
                'phone' => $card->guest?->phone ?? '-',
                'email' => $card->guest?->email ?? '-',
                'card_number' => $card->card_number,
                'card_url' => $card->publicUrl(),
            ];
        });

        $plainText = $links->map(function ($link) {
            return "{$link['guest']} | {$link['card_number']} | {$link['card_url']}";
        })->implode("\n");

        return response()->json([
            'success' => true,
            'data' => [
                'links' => $links,
                'plain_text' => $plainText,
                'total' => $links->count(),
            ],
        ]);
    }

    public function sendEmail(Request $request, Card $card): JsonResponse
    {
        $this->authorize('viewAny', Card::class);

        $card->loadMissing('guest');

        if (! $card->guest) {
            return response()->json([
                'success' => false,
                'message' => 'This card has no guest.',
            ], 422);
        }

        $emailService = new EmailService();
        $result = $emailService->send($card);

        SecurityLogger::logRequest('card.send_email', $request, $request->user(), [
            'card_id' => $card->id,
            'success' => $result['success'],
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    public function sendEmails(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Card::class);

        $validated = $request->validate([
            'card_ids' => 'required|array|min:1',
            'card_ids.*' => 'integer|exists:cards,id',
        ]);

        $emailService = new EmailService();
        $result = $emailService->sendMany($validated['card_ids']);

        SecurityLogger::logRequest('card.send_emails', $request, $request->user(), [
            'card_ids' => $validated['card_ids'],
            'success' => $result['success'],
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $result['data'] ?? [],
        ], $result['success'] ? 200 : 422);
    }

    public function sendSms(Request $request, Card $card): JsonResponse
    {
        $this->authorize('viewAny', Card::class);

        $validated = $request->validate([
            'message' => 'nullable|string|max:1000',
        ]);

        $card->loadMissing('guest');

        if (! $card->guest) {
            return response()->json([
                'success' => false,
                'message' => 'This card has no guest.',
            ], 422);
        }

        $phone = PhoneHelper::toE164($card->guest->phone ?? '');

        if ($phone === null || strlen($phone) < 10) {
            return response()->json([
                'success' => false,
                'message' => "The guest's phone number is invalid.",
            ], 422);
        }

        $message = trim((string) ($validated['message'] ?? ''));

        if ($message === '') {
            $campaign = app(InvitationCampaignService::class);
            $message = $campaign->smsMessage($card, $card->publicUrl());
        }

        $smsService = new SmsService();
        $result = $smsService->send($phone, $message);

        SecurityLogger::logRequest('card.send_sms', $request, $request->user(), [
            'card_id' => $card->id,
            'success' => $result['success'],
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ], $result['success'] ? 200 : 422);
    }

    public function sendSmsBulk(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Card::class);

        $validated = $request->validate([
            'card_ids' => 'required|array|min:1',
            'card_ids.*' => 'integer|exists:cards,id',
            'message' => 'nullable|string|max:1000',
        ]);

        $customMessage = trim((string) ($validated['message'] ?? ''));
        $campaign = app(InvitationCampaignService::class);
        $smsService = new SmsService();

        $sent = 0;
        $failed = 0;

        $cards = Card::query()
            ->with('guest')
            ->whereIn('id', $validated['card_ids'])
            ->get();

        foreach ($cards as $card) {
            $phone = PhoneHelper::toE164($card->guest?->phone ?? '');

            if ($phone === null || strlen($phone) < 10) {
                $failed++;

                continue;
            }

            $message = $customMessage !== ''
                ? $customMessage
                : $campaign->smsMessage($card, $card->publicUrl());

            $result = $smsService->send($phone, $message);

            if ($result['success'] ?? false) {
                $sent++;
            } else {
                $failed++;
            }
        }

        SecurityLogger::logRequest('card.send_sms_bulk', $request, $request->user(), [
            'card_ids' => $validated['card_ids'],
            'sent' => $sent,
            'failed' => $failed,
        ]);

        return response()->json([
            'success' => $sent > 0,
            'message' => "$sent SMS sent; $failed failed.",
            'data' => [
                'sent' => $sent,
                'failed' => $failed,
            ],
        ]);
    }

    private function createCard(int $guestId, CardType $type, ?string $expiresAt = null, ?string $cardNumber = null, array $extra = []): Card
    {
        $attempts = 0;

        do {
            try {
                return Card::query()->create([
                    'guest_id' => $guestId,
                    'card_number' => $cardNumber ?? $this->nextCardNumber(),
                    'qr_token' => $this->uniqueQrToken(),
                    'card_type' => $type,
                    'allowed_people' => $type->allowedPeople(),
                    'used_people' => 0,
                    'status' => 'active',
                    'issued_at' => now(),
                    'expires_at' => $expiresAt,
                    ...$extra,
                ]);
            } catch (QueryException $e) {
                if ($cardNumber !== null || $attempts >= 5 || ! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                $attempts++;
            }
        } while (true);
    }

    private function eventAttributes(Request $request): array
    {
        return [
            'card_template_id' => $request->filled('card_template_id') ? $request->integer('card_template_id') : null,
            'event_name' => $request->input('event_name'),
            'event_date' => $request->input('event_date'),
            'event_time' => $request->input('event_time'),
            'venue' => $request->input('venue'),
            'table_number' => $request->input('table_number'),
            'seat_number' => $request->input('seat_number'),
            'special_message' => $request->input('special_message'),
        ];
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(fn (string $header) => $this->normalizeKey($header), $headers);
    }

    private function normalizeKey(string $key): string
    {
        $key = ltrim($key, "\xEF\xBB\xBF");
        $key = mb_strtolower(trim($key));
        $key = preg_replace('/[\x{00a0}\s_-]+/u', '_', $key);

        return trim((string) $key, '_');
    }

    private function columnMap(array $normalized): array
    {
        $aliases = [
            'full_name' => ['full_name', 'jina', 'jina_kamili', 'name'],
            'phone' => ['phone', 'namba_ya_simu', 'simu', 'telephone', 'mobile'],
            'email' => ['email', 'barua_pepe'],
            'card_type' => ['card_type', 'aina', 'aina_ya_kadi', 'type'],
            'card_number' => ['card_number', 'namba_ya_kadi', 'namba'],
            'expires_at' => ['expires_at', 'inayomalizika', 'inamalizika', 'expiry', 'expires', 'muda'],
        ];

        $map = [];

        foreach ($normalized as $index => $key) {
            foreach ($aliases as $field => $names) {
                if (in_array($key, $names, true)) {
                    $map[$field] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    private function rowToRecord(array $row, array $map): array
    {
        $record = [];

        foreach ($map as $field => $index) {
            $record[$field] = $row[$index] ?? null;
        }

        return $record;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (bool) preg_match('/Duplicate entry|UNIQUE constraint|SQLSTATE\[23000\]/i', $e->getMessage());
    }

    private function nextCardNumber(): string
    {
        $prefix = 'EC-';

        $last = Card::query()->orderByDesc('id')->value('card_number');

        if (! $last) {
            return $prefix . '000001';
        }

        preg_match('/(\d+)$/', (string) $last, $matches);
        $number = isset($matches[1]) ? (int) $matches[1] : 0;

        return $prefix . str_pad((string) ($number + 1), 6, '0', STR_PAD_LEFT);
    }

    private function uniqueQrToken(): string
    {
        do {
            $token = Str::random(32);
        } while (Card::query()->where('qr_token', $token)->exists());

        return $token;
    }
}
