<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\EventDetail;
use App\Models\WeeklyReminderLog;
use Illuminate\Database\Eloquent\Builder;

class WeeklyReminderService
{
    public function __construct(
        private readonly SmsService $sms,
    ) {
    }

    public function eligibleCards(): Builder
    {
        return Card::query()
            ->where('status', CardStatus::Active->value)
            ->whereNotNull('guest_id')
            ->whereHas('guest', function (Builder $query) {
                $query->whereNotNull('phone_e164')
                    ->where('phone_e164', '!=', '')
                    ->whereRaw('LENGTH(phone_e164) >= 10');
            });
    }

    public function sendWeeklyReminders(): array
    {
        $now = now();
        $reminderDate = $now->toDateString();
        $year = (int) $now->format('Y');
        $weekNumber = (int) $now->format('W');
        $alreadySentCardIds = $this->alreadySentThisWeek($year, $weekNumber);

        $cards = $this->eligibleCards()
            ->with('guest')
            ->when($alreadySentCardIds->isNotEmpty(), function ($query) use ($alreadySentCardIds) {
                $query->whereNotIn('id', $alreadySentCardIds);
            })
            ->get();

        $dispatched = 0;
        $failed = 0;
        $skipped = 0;
        $logs = [];

        foreach ($cards as $card) {
            $guest = $card->guest;

            if ($guest === null) {
                $skipped++;
                continue;
            }

            $phone = PhoneHelper::toE164($guest->phone ?? '');

            if ($phone === null || strlen($phone) < 10) {
                $skipped++;
                continue;
            }

            $message = $this->buildReminderMessage($card, $guest->full_name);

            $result = $this->sms->send($phone, $message);

            $status = ($result['success'] ?? false) ? 'sent' : 'failed';
            $error = ($result['success'] ?? false) ? null : ($result['message'] ?? 'Failed to send SMS');

            $log = WeeklyReminderLog::create([
                'card_id' => $card->id,
                'phone_e164' => $phone,
                'guest_name' => $guest->full_name,
                'message_sent' => $message,
                'status' => $status,
                'error' => $error,
                'reminder_date' => $reminderDate,
                'week_number' => $weekNumber,
                'year' => $year,
                'sent_at' => $now,
            ]);

            $logs[] = $log;

            if ($status === 'sent') {
                $dispatched++;
            } else {
                $failed++;
            }
        }

        return [
            'dispatched' => $dispatched,
            'failed' => $failed,
            'skipped' => $skipped,
            'total_eligible' => $cards->count() + $alreadySentCardIds->count(),
            'week_number' => $weekNumber,
            'year' => $year,
            'reminder_date' => $reminderDate,
        ];
    }

    public function buildReminderMessage(Card $card, string $guestName): string
    {
        $event = EventDetail::singleton();
        $eventName = $card->event_name ?: ($event->event_name ?: 'sherehe zetu');
        $cardUrl = config('app.url') . '/e-card/' . $card->qr_token;

        $parts = [
            "Habari {$guestName},",
            '',
            "Ukumbusho wa {$eventName}!",
            '',
        ];

        if ($date = $this->resolveEventDate($card, $event)) {
            $parts[] = "Tarehe: {$date}";
        }

        if ($time = $card->event_time ?: $event->event_time) {
            $parts[] = "Muda: {$time}";
        }

        if ($venue = $card->venue ?: $event->venue) {
            $parts[] = "Mahali: {$venue}";
        }

        if ($event->important_instructions) {
            $parts[] = '';
            $parts[] = "Taarifa muhimu: {$event->important_instructions}";
        }

        if ($event->gift_information) {
            $parts[] = "Zawadi: {$event->gift_information}";
        }

        $parts[] = '';
        $parts[] = "Kadi Namba: {$card->card_number}";
        $parts[] = '';
        $parts[] = "Fungua kadi yako ya dijitali:";
        $parts[] = $cardUrl;
        $parts[] = '';
        $parts[] = "Asante na karibu!";

        return implode("\n", $parts);
    }

    public function getHistory(int $page = 1, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return WeeklyReminderLog::query()
            ->with('card.guest')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function getWeekSummary(int $year, int $weekNumber): array
    {
        $logs = WeeklyReminderLog::query()
            ->where('year', $year)
            ->where('week_number', $weekNumber)
            ->get();

        return [
            'year' => $year,
            'week_number' => $weekNumber,
            'total_sent' => $logs->where('status', 'sent')->count(),
            'total_failed' => $logs->where('status', 'failed')->count(),
            'logs' => $logs,
        ];
    }

    public function getAvailableWeeks(): array
    {
        return WeeklyReminderLog::query()
            ->select('year', 'week_number')
            ->distinct()
            ->orderByDesc('year')
            ->orderByDesc('week_number')
            ->get()
            ->toArray();
    }

    private function alreadySentThisWeek(int $year, int $weekNumber): \Illuminate\Support\Collection
    {
        return WeeklyReminderLog::query()
            ->where('year', $year)
            ->where('week_number', $weekNumber)
            ->where('status', 'sent')
            ->pluck('card_id');
    }

    private function resolveEventDate(Card $card, EventDetail $event): ?string
    {
        if ($card->event_date) {
            return $card->event_date->format('d/m/Y');
        }

        return $event->event_date?->format('d/m/Y');
    }
}
