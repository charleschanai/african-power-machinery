<?php

namespace App\Models;

use App\Enums\InvitationState;
use App\Services\MessageTemplateRenderer;
use App\Services\PhoneHelper;
use App\Services\PublicLinkChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Invitation extends Model
{
    protected $fillable = [
        'guest_id',
        'card_id',
        'phone',
        'event_name',
        'event_date',
        'event_time',
        'venue',
        'wedding_details',
        'message',
        'reminder_message',
        'status',
        'last_error',
        'last_sms_sent_at',
        'last_reminder_sent_at',
        'last_whatsapp_shared_at',
        'last_sent_at',
        'reminders_count',
    ];

    /**
     * Mirrors the column defaults so a freshly built model already has a
     * usable status instead of null before it is saved.
     */
    protected $attributes = [
        'status' => 'pending',
        'reminders_count' => 0,
    ];

    protected $casts = [
        'status' => InvitationState::class,
        'event_date' => 'date',
        'last_sms_sent_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
        'last_whatsapp_shared_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'reminders_count' => 'integer',
    ];

    /**
     * Card, guest and template are needed by nearly every screen, and the list
     * endpoint renders a card thumbnail per row, so they are always eager
     * loaded here rather than in each caller.
     */
    protected $with = ['guest', 'card.cardTemplate'];

    protected static function booted(): void
    {
        static::saving(function (Invitation $invitation) {
            if ($invitation->isDirty('phone')) {
                $invitation->phone_e164 = PhoneHelper::toE164($invitation->phone ?? '');
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Relations                                                          */
    /* ------------------------------------------------------------------ */

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(InvitationSend::class, 'card_id', 'card_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Derived state                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Whether the invitation actually reached the guest.
     *
     * Reads the status rather than the timestamp, because a refused send still
     * stamps last_sms_sent_at. A reminder for a guest who never received the
     * invitation would only add to the confusion, so a failure counts as not
     * sent everywhere.
     */
    public function hasBeenSent(): bool
    {
        return $this->status === InvitationState::Sent;
    }

    public function hasReminderBeenSent(): bool
    {
        return $this->last_reminder_sent_at !== null;
    }

    public function needsReminder(): bool
    {
        return $this->hasBeenSent() && ! $this->hasReminderBeenSent();
    }

    /**
     * The card image that should be shared on WhatsApp. Prefers the guest's
     * own eCard URL, but the requirement is to share the actual design file,
     * so the template artwork is what gets handed to the share sheet.
     */
    public function shareableImageUrl(): ?string
    {
        $template = $this->card?->cardTemplate;

        return $template?->frontUrl();
    }

    /**
     * The link the guest receives.
     *
     * Built from ECARD_PUBLIC_URL rather than from the host the admin happens
     * to be browsing. Deriving it from the request meant the same saved
     * invitation produced a different link depending on where the admin was
     * sitting when they pressed send, and a link built from localhost or a LAN
     * address cannot be opened on a guest's phone at all.
     */
    public function eCardUrl(): ?string
    {
        $token = $this->card?->qr_token;

        if (blank($token)) {
            return null;
        }

        return self::publicBaseUrl() . '/e-card/' . $token;
    }

    /**
     * The message a WhatsApp message should carry.
     *
     * Taken from the saved message rather than rebuilt in the browser, so the
     * text a guest receives on WhatsApp is the same text the admin wrote and
     * the same text the SMS carries. The card link and number are appended when
     * the template omits them, because on WhatsApp the guest gets the artwork
     * as an attachment and still needs a way to open their own card.
     */
    public function whatsAppText(): string
    {
        $renderer = app(MessageTemplateRenderer::class);

        $text = $renderer->render((string) $this->message, $this);

        if (trim($text) === '') {
            // An invitation saved without a message still has to reach the
            // guest with something readable.
            $text = $renderer->render(
                "Dear {first_name},\n\nYou are warmly invited to {event_name}.\n\n{wedding_details}",
                $this
            );
        }

        // A template written before the link token existed, or one where the
        // admin deleted it, would otherwise reach the guest with no way in.
        $link = (string) $this->eCardUrl();

        if ($link !== '' && ! str_contains($text, $link)) {
            $text = rtrim($text)
                . "\n\nYour E-Card: " . $link
                . "\nCard No: " . (string) $this->card?->card_number;
        }

        return trim($text);
    }

    /** The address every shared link is built from, with no trailing slash. */
    public static function publicBaseUrl(): string
    {
        return rtrim((string) config('ecard.public_url', config('app.url')), '/');
    }

    /**
     * Whether the configured address will actually hand a guest their card.
     *
     * Nothing here touches the network, because a page load may not open the
     * address it is being asked about: that address normally leads back to this
     * same machine, so the fetch would queue behind the request asking for it.
     * See PublicLinkChecker for the whole of that.
     *
     * What is settled here, for free and on every load, is the part that is a
     * property of the address rather than of the tunnel's mood: an address that
     * only this machine can open. The rest is read from the answer
     * `php artisan ecard:check-link` last left behind, and when there is nothing
     * to read it says so rather than guessing.
     *
     * The levels are deliberate. broken means a guest cannot open the link at
     * all, which is the one that wastes paid SMS. unknown means nobody has
     * checked, which is not the same as broken and must not be shown as if it
     * were. A tunnel that puts a one click notice in front of the site is not
     * reported as a fault, because the guest does reach the card and the admin
     * has chosen that tunnel.
     *
     * @return array{ok: bool, level: string, reason: string}
     */
    public static function publicUrlStatus(): array
    {
        $host = parse_url(self::publicBaseUrl(), PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return self::publicUrlVerdict(false, 'broken', 'No address is configured, so links cannot be built.');
        }

        $host = trim($host, '[]');

        foreach ((array) config('ecard.unreachable_hosts', []) as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && strcasecmp($candidate, $host) === 0) {
                return self::publicUrlVerdict(false, 'broken', $host.' only works on this machine, so a guest cannot open it.');
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Rejects RFC 1918 private space plus loopback, link-local and the
            // other reserved blocks. Only a public literal passes.
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return self::publicUrlVerdict(false, 'broken', $host.' is a private address, so a guest cannot open it.');
            }
        }

        // Past this point the address looks plausible but has not been opened.
        // Opening it is not something a page load may do, because the address
        // normally leads back to this same machine, so the fetch would queue
        // behind the request asking for it. The answer is read from what
        // `php artisan ecard:check-link` left behind, and when there is nothing
        // to read the honest answer is that it has not been checked.
        $stored = Cache::get(self::publicUrlProbeKey());

        if (is_array($stored) && isset($stored['level'], $stored['reason'], $stored['ok'])) {
            return self::publicUrlVerdict(
                (bool) $stored['ok'],
                // A stored "warning" is read as fine on purpose. The admin has
                // chosen this tunnel, and a guest who clicks once does get the
                // card, so it is not a fault worth putting in front of them.
                (string) $stored['level'] === 'warning' ? 'ok' : (string) $stored['level'],
                (string) $stored['reason'].' (last checked '.($stored['checked_at'] ?? 'at an unknown time').')'
            );
        }

        return self::publicUrlVerdict(
            true,
            'unknown',
            $host.' looks like a usable address but has not been checked from here. '
            .'Run "php artisan ecard:check-link", or just open the link on a phone, before sending any SMS.'
        );
    }

    /** Where the command leaves its answer for the page to read. */
    public static function publicUrlProbeKey(): string
    {
        return 'ecard.public-url-status.'.md5(self::publicBaseUrl());
    }

    /**
     * A plain yes or no for guests opening a link, for the callers that only
     * need a light or a dark. publicUrlStatus() carries the reason.
     */
    public static function publicUrlIsReachable(): bool
    {
        return (bool) (self::publicUrlStatus()['ok'] ?? false);
    }

    /**
     * Opens the public address for real and stores the answer for the page.
     *
     * Only ever called from the command line, never from a request, for the
     * reason set out on PublicLinkChecker. The stored answer is what the
     * Invitations page reads, and the host name checks in publicUrlStatus()
     * have already ruled out a dead or local address by then.
     *
     * @return array{ok: bool, level: string, reason: string}
     */
    public static function probePublicUrl(): array
    {
        $verdict = app(PublicLinkChecker::class)->check(self::publicBaseUrl());

        Cache::put(
            self::publicUrlProbeKey(),
            $verdict + ['checked_at' => now()->toDateTimeString()],
            (int) config('ecard.public_url_probe_seconds', 900),
        );

        return $verdict;
    }

    /**
     * @return array{ok: bool, level: string, reason: string}
     */
    private static function publicUrlVerdict(bool $ok, string $level, string $reason): array
    {
        return ['ok' => $ok, 'level' => $level, 'reason' => $reason];
    }

    /* ------------------------------------------------------------------ */
    /*  Query scopes                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Invitations the gateway actually accepted.
     *
     * These deliberately read the status column rather than testing
     * last_sms_sent_at. A send that was attempted and then rejected still
     * leaves a timestamp behind, so keying off the timestamp reported failed
     * invitations as sent, which is the one thing this list must never do.
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', InvitationState::Sent->value);
    }

    /** Invitations that have not reached the guest, whether never tried or refused. */
    public function scopeNotSent(Builder $query): Builder
    {
        return $query->where('status', '!=', InvitationState::Sent->value);
    }

    public function scopeFilter(Builder $query, ?string $filter): Builder
    {
        return match ($filter) {
            'sent' => $query->sent(),
            'not_sent' => $query->notSent(),
            'reminder_sent' => $query->whereNotNull('last_reminder_sent_at'),
            // A reminder only makes sense once the invitation itself landed.
            'reminder_not_sent' => $query->sent()->whereNull('last_reminder_sent_at'),
            default => $query,
        };
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $sub) use ($term) {
            $sub->whereHas('guest', function (Builder $g) use ($term) {
                $g->where('full_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            })
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('event_name', 'like', "%{$term}%")
                ->orWhere('venue', 'like', "%{$term}%");
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Serialisation                                                      */
    /* ------------------------------------------------------------------ */

    public function toFrontendArray(): array
    {
        return [
            'id' => $this->id,
            'guest_id' => $this->guest_id,
            'guest_name' => $this->guest?->full_name,
            'phone' => $this->phone,
            'phone_display' => PhoneHelper::toDisplay($this->phone),
            'phone_problem' => PhoneHelper::problem($this->phone),
            'can_send' => PhoneHelper::isDeliverable($this->phone),
            'card_id' => $this->card_id,
            'card_type' => $this->card?->card_type?->value,
            'card_number' => $this->card?->card_number,
            'card_template_id' => $this->card?->card_template_id,
            'card_image' => $this->shareableImageUrl(),
            'e_card_url' => $this->eCardUrl(),
            'public_url' => self::publicBaseUrl(),
            'public_url_ok' => self::publicUrlIsReachable(),
            'public_url_status' => self::publicUrlStatus(),
            'event_name' => $this->event_name,
            'event_date' => $this->event_date?->format('Y-m-d'),
            'event_time' => $this->event_time,
            'venue' => $this->venue,
            'wedding_details' => $this->wedding_details,
            'message' => $this->message,
            'reminder_message' => $this->reminder_message,
            // Sent with the WhatsApp share so the caption and the SMS can never
            // drift apart, both coming from the saved message.
            'whatsapp_text' => $this->whatsAppText(),
            // A row can be mid-save or pre-default, so fall back rather than
            // assume the cast produced an enum.
            'status' => ($this->status ?? InvitationState::Pending)->value,
            'status_label' => ($this->status ?? InvitationState::Pending)->label(),
            'last_error' => $this->last_error,
            'last_sms_sent_at' => $this->last_sms_sent_at?->toDateTimeString(),
            'last_reminder_sent_at' => $this->last_reminder_sent_at?->toDateTimeString(),
            'last_whatsapp_shared_at' => $this->last_whatsapp_shared_at?->toDateTimeString(),
            'last_sent_at' => $this->last_sent_at?->toDateTimeString(),
            'reminders_count' => $this->reminders_count,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
