<?php

namespace App\Services;

use App\Models\Card;
use App\Models\EventDetail;
use App\Models\Guest;
use App\Models\WhatsAppFaq;
use App\Models\WhatsAppSetting;
use Illuminate\Support\Str;

class EventAssistant
{
    public const INTENT_GREETING = 'greeting';
    public const INTENT_HELP = 'help';
    public const INTENT_DATE = 'date';
    public const INTENT_VENUE = 'venue';
    public const INTENT_DRESS_CODE = 'dress_code';
    public const INTENT_PROGRAM = 'program';
    public const INTENT_PARKING = 'parking';
    public const INTENT_ACCOMMODATION = 'accommodation';
    public const INTENT_GIFT = 'gift';
    public const INTENT_RSVP = 'rsvp';
    public const INTENT_CARD = 'card';
    public const INTENT_ALLOWANCE = 'allowance';
    public const INTENT_CONTACT = 'contact';
    public const INTENT_HANDOVER = 'handover';
    public const INTENT_THANKS = 'thanks';
    public const INTENT_FAQ = 'faq';
    public const INTENT_UNKNOWN = 'unknown';

    public function __construct(
        private readonly AiAssistant $ai,
        private readonly WhatsAppSettingsService $settings,
    ) {
    }

    /**
     * Build the assistant reply for a guest message.
     *
     * @return array{text: string, intent: string, language: string, send_menu: bool}
     */
    public function answer(string $message, ?string $fromE164 = null): array
    {
        $setting = $this->settings->settings();
        $event = EventDetail::singleton();

        $raw = trim($message);
        $normalized = $this->normalize($raw);
        $language = $this->detectLanguage($raw, $setting->default_language);

        if ($normalized === '') {
            return $this->reply('', self::INTENT_UNKNOWN, $language);
        }

        // Greetings & small talk first (they should never trigger FAQ/LLM).
        if ($this->isGreeting($normalized)) {
            return $this->reply($this->greetingText($event, $language), self::INTENT_GREETING, $language, true);
        }

        if ($this->isThanks($normalized)) {
            return $this->reply($this->thanksText($language), self::INTENT_THANKS, $language);
        }

        // FAQ knowledge base: a matching official FAQ wins over the rules.
        $faq = $this->matchFaq($normalized);
        if ($faq !== null) {
            return $this->reply($faq->answer, self::INTENT_FAQ, $language);
        }

        $intent = $this->detectIntent($normalized);

        if ($intent === self::INTENT_CARD) {
            return $this->handleCard($fromE164, $setting, $language);
        }

        if ($intent === self::INTENT_ALLOWANCE) {
            return $this->handleAllowance($fromE164, $setting, $language);
        }

        if ($intent === self::INTENT_HANDOVER) {
            return $this->handleHandover($setting, $language);
        }

        if ($intent === self::INTENT_CONTACT) {
            return $this->handleContact($setting, $language);
        }

        if ($intent === self::INTENT_RSVP) {
            return $this->handleRsvp($event, $setting, $language);
        }

        $known = [
            self::INTENT_DATE => fn () => $this->handleDate($event, $setting, $language),
            self::INTENT_VENUE => fn () => $this->handleVenue($event, $setting, $language),
            self::INTENT_DRESS_CODE => fn () => $this->handleDressCode($event, $setting, $language),
            self::INTENT_PROGRAM => fn () => $this->handleProgram($event, $setting, $language),
            self::INTENT_PARKING => fn () => $this->handleParking($event, $setting, $language),
            self::INTENT_ACCOMMODATION => fn () => $this->handleAccommodation($event, $setting, $language),
            self::INTENT_GIFT => fn () => $this->handleGift($event, $setting, $language),
        ];

        if (isset($known[$intent])) {
            return $known[$intent]();
        }

        if ($intent === self::INTENT_GREETING || $intent === self::INTENT_HELP) {
            return $this->reply($this->greetingText($event, $language), $intent, $language, true);
        }

        return $this->handleUnknown($raw, $event, $setting, $language);
    }

    private function reply(string $text, string $intent, string $language, bool $sendMenu = false): array
    {
        return [
            'text' => $text,
            'intent' => $intent,
            'language' => $language,
            'send_menu' => $sendMenu,
        ];
    }

    private function handleCard(?string $fromE164, WhatsAppSetting $setting, string $language): array
    {
        $guest = $this->findGuest($fromE164);

        if ($guest === null) {
            return $this->reply($setting->unknownGuestReply(), self::INTENT_CARD, $language);
        }

        $card = $guest->cards()
            ->where('status', '!=', 'cancelled')
            ->latest('id')
            ->first();

        if ($card === null) {
            return $this->reply($this->noCardText($guest->full_name, $setting, $language), self::INTENT_CARD, $language);
        }

        $cardUrl = url('/e-card/' . $card->qr_token);

        return $this->reply($this->cardText($guest->full_name, $card, $cardUrl, $language), self::INTENT_CARD, $language);
    }

    private function handleAllowance(?string $fromE164, WhatsAppSetting $setting, string $language): array
    {
        $guest = $this->findGuest($fromE164);

        if ($guest === null) {
            return $this->reply($setting->unknownGuestReply(), self::INTENT_ALLOWANCE, $language);
        }

        $card = $guest->cards()
            ->where('status', '!=', 'cancelled')
            ->latest('id')
            ->first();

        if ($card === null) {
            return $this->reply($setting->noInfoReply(), self::INTENT_ALLOWANCE, $language);
        }

        return $this->reply(
            "Welcome 😊 Your card allows {$card->allowed_people} guest(s).",
            self::INTENT_ALLOWANCE,
            $language,
        );
    }

    private function handleHandover(WhatsAppSetting $setting, string $language): array
    {
        $phone = $setting->organizer_phone ?: $setting->contactNumberFallback();

        $text = "Welcome 😊 I have received your request.\n\nPlease contact the organizer:\n\n📞 {$phone}\n\nOr wait for the organizer to reply.";

        return $this->reply($text, self::INTENT_HANDOVER, $language);
    }

    private function handleContact(WhatsAppSetting $setting, string $language): array
    {
        $name = $setting->organizer_name ?: EventDetail::singleton()->contact_person;
        $phone = $setting->organizer_phone ?: EventDetail::singleton()->contact_number;

        $text = ($name ? "Organizer: {$name}\n" : '') . "📞 {$phone}";

        return $this->reply($text, self::INTENT_CONTACT, $language);
    }

    private function handleDate(EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        if (! $event->event_date && ! $event->event_time) {
            return $this->reply($setting->noInfoReply(), self::INTENT_DATE, $language);
        }

        $date = $event->event_date?->toDateString();

        $time = $event->event_time ?: '';

        $text = 'The event will be held on ' . ($date ?? '') . ($time ? ' at ' . $time : '') . '.';

        if ($event->event_name) {
            $text .= "\n\n" . 'Event: ' . $event->event_name;
        }

        return $this->reply($text, self::INTENT_DATE, $language);
    }

    private function handleVenue(EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        if (! $event->venue) {
            return $this->reply($setting->noInfoReply(), self::INTENT_VENUE, $language);
        }

        $lines = [];

        $lines[] = 'Venue: ' . $event->venue . '.';

        if ($event->venue_address) {
            $lines[] = 'Address: ' . $event->venue_address;
        }

        if ($event->google_maps_url) {
            $lines[] = '';
            $lines[] = '📍 Google Maps:';
            $lines[] = $event->google_maps_url;
        }

        return $this->reply(implode("\n", $lines), self::INTENT_VENUE, $language);
    }

    private function handleDressCode(EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        if (! $event->dress_code) {
            return $this->reply($setting->noInfoReply(), self::INTENT_DRESS_CODE, $language);
        }

        $text = 'Dress code: ' . $event->dress_code . ' 👔✨';

        return $this->reply($text, self::INTENT_DRESS_CODE, $language);
    }

    private function handleProgram(EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        if (! $event->event_program) {
            return $this->reply($setting->noInfoReply(), self::INTENT_PROGRAM, $language);
        }

        $header = 'Event Program 🎊';

        return $this->reply($header . "\n\n" . $event->event_program, self::INTENT_PROGRAM, $language);
    }

    private function handleParking(EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        if (! $event->parking_information) {
            return $this->reply($setting->noInfoReply(), self::INTENT_PARKING, $language);
        }

        return $this->reply($event->parking_information, self::INTENT_PARKING, $language);
    }

    private function handleAccommodation(EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        if (! $event->accommodation_information) {
            return $this->reply($setting->noInfoReply(), self::INTENT_ACCOMMODATION, $language);
        }

        return $this->reply($event->accommodation_information, self::INTENT_ACCOMMODATION, $language);
    }

    private function handleGift(EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        if (! $event->gift_information) {
            return $this->reply($setting->noInfoReply(), self::INTENT_GIFT, $language);
        }

        return $this->reply($event->gift_information, self::INTENT_GIFT, $language);
    }

    private function handleRsvp(EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        if (! $event->rsvp_link && ! $event->rsvp_information) {
            return $this->reply($setting->noInfoReply(), self::INTENT_RSVP, $language);
        }

        $lines = [];

        $lines[] = 'You can confirm your attendance here:';

        if ($event->rsvp_link) {
            $lines[] = $event->rsvp_link;
        }

        if ($event->rsvp_information) {
            $lines[] = '';
            $lines[] = $event->rsvp_information;
        }

        return $this->reply(implode("\n", $lines), self::INTENT_RSVP, $language);
    }

    private function handleUnknown(string $raw, EventDetail $event, WhatsAppSetting $setting, string $language): array
    {
        $fallback = $setting->noInfoReply();

        $generated = $this->ai->generate(
            $this->systemPrompt($event, $setting, $language),
            $raw,
        );

        return $this->reply($generated ?? $fallback, self::INTENT_UNKNOWN, $language);
    }

    private function greetingText(EventDetail $event, string $language): string
    {
        $eventName = $event->event_name ?: 'the event';

        return "Hello 😊 Welcome to the official WhatsApp of {$eventName}.\n\nI can help you with:\n\n📅 Date & time\n📍 Venue & location\n🎫 E-Card\n👗 Dress code\n🚗 Parking\n📋 Program\n📞 Organizer\n\nTell me what you would like to know.";
    }

    private function thanksText(string $language): string
    {
        return 'You are welcome 😊';
    }

    private function noCardText(string $name, WhatsAppSetting $setting, string $language): string
    {
        return "Welcome {$name} 😊 You do not have a card yet. Please contact the organizer for more help.";
    }

    private function cardText(string $name, Card $card, string $cardUrl, string $language): string
    {
        return "Welcome {$name} 😊\n\nHere is your E-Card:\n\n🎫 {$cardUrl}\n\nCard Number: {$card->card_number}";
    }

    private function findGuest(?string $fromE164): ?Guest
    {
        if ($fromE164 === null) {
            return null;
        }

        return (new Guest)->findByPhone($fromE164);
    }

    /* ------------------------------------------------------------------ */
    /*  Intent classification                                              */
    /* ------------------------------------------------------------------ */

    private function detectIntent(string $text): string
    {
        // Specific intents win over generic location/date words.
        if ($this->matchesAny($text, ['card', 'kadi', 'ecard', 'e-card', 'e_kadi', 'mwaliko', 'invitation', 'invite', 'tiket', 'ticket'])) {
            return self::INTENT_CARD;
        }

        if ($this->matchesAny($text, ['watu wangapi', 'wageni wangapi', 'how many people', 'how many', 'plus one', 'plus-one', 'na watu', 'na wageni', 'allowance', 'idadi ya watu', 'bring people', 'people can i'])) {
            return self::INTENT_ALLOWANCE;
        }

        if ($this->matchesAny($text, ['dress code', 'dresscode', 'mavazi', 'vazi', 'nguo', 'vaa', 'outfit'])) {
            return self::INTENT_DRESS_CODE;
        }

        if ($this->matchesAny($text, ['parking', 'maegesho', 'kuegesha', 'park', 'egesha', 'parking spot'])) {
            return self::INTENT_PARKING;
        }

        if ($this->matchesAny($text, ['malazi', 'accommodation', 'hotel', 'pa kulala', 'sehemu ya kulala', 'kulala', 'lodging', 'stay overnight'])) {
            return self::INTENT_ACCOMMODATION;
        }

        if ($this->matchesAny($text, ['program', 'ratiba', 'agenda', 'schedule', 'mpango', 'programu', 'order of events', 'timeline', 'kuna nini', 'itaendeleaje'])) {
            return self::INTENT_PROGRAM;
        }

        if ($this->matchesAny($text, ['zawadi', 'gift', 'present', 'regalo', 'gifts'])) {
            return self::INTENT_GIFT;
        }

        if ($this->matchesAny($text, ['rsvp', 'mahudhurio', 'thibitisha', 'confirm', 'attendance', 'nitakuja', 'nimekuja', 'kuja naweza'])) {
            return self::INTENT_RSVP;
        }

        if ($this->matchesAny($text, ['kuongea na mtu', 'naomba kuongea', 'naomba mtu', 'i need a human', 'need a human', 'human', 'talk to a person', 'admin please', 'operator', 'naomba human'])) {
            return self::INTENT_HANDOVER;
        }

        if ($this->matchesAny($text, ['namba', 'simu', 'number', 'contact', 'wasiliana', 'kuwasiliana', 'piga simu', 'call', 'organizer'])) {
            return self::INTENT_CONTACT;
        }

        if ($this->matchesAny($text, ['wapi', 'where', 'venue', 'mahali', 'location', 'eneo', 'maps', 'google map', 'ramani', 'directions', 'address', 'anwani', 'get there', 'mtaani', 'direction'])) {
            return self::INTENT_VENUE;
        }

        if ($this->matchesAny($text, ['lini', 'when', 'tarehe', 'date', 'saa ngapi', 'saa gani', 'siku gani', 'itafanyika lini', 'inaanza lini', 'what time', 'when is', 'time'])) {
            return self::INTENT_DATE;
        }

        if ($this->isGreeting($text)) {
            return self::INTENT_GREETING;
        }

        if ($this->matchesAny($text, ['help', 'msaada', 'menu', 'options', 'unaweza', 'can you', 'what can you', 'nini unajua', 'niambie'])) {
            return self::INTENT_HELP;
        }

        return self::INTENT_UNKNOWN;
    }

    private function matchFaq(string $text): ?WhatsAppFaq
    {
        $faqs = WhatsAppFaq::query()->active()->get();

        if ($faqs->isEmpty()) {
            return null;
        }

        $tokens = $this->tokens($text);

        if ($tokens === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.45;

        foreach ($faqs as $faq) {
            $faqTokens = $this->tokens($this->normalize($faq->question));

            if ($faqTokens === []) {
                continue;
            }

            $intersection = count(array_intersect($tokens, $faqTokens));
            $score = $intersection / max(1, count(array_unique(array_merge($tokens, $faqTokens))));

            // Exact question text should always match.
            if ($this->normalize($faq->question) === $text) {
                return $faq;
            }

            if ($score >= $bestScore) {
                $bestScore = $score;
                $best = $faq;
            }
        }

        return $best;
    }

    private function isGreeting(string $text): bool
    {
        return $this->matchesAny($text, [
            'hi', 'hey', 'hello', 'habari', 'mambo', 'jambo', 'hujambo',
            'salama', 'niaje', 'vipi', 'sasa', 'sup', 'hola', 'yo',
        ]);
    }

    private function isThanks(string $text): bool
    {
        return $this->matchesAny($text, ['asante', 'thank', 'thanks', 'shukrani', 'merci', 'thx']);
    }

    private function matchesAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function tokens(string $text): array
    {
        $text = $this->normalize($text);

        return array_values(array_filter(array_unique(preg_split('/\s+/', $text) ?: [])));
    }

    private function normalize(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function detectLanguage(string $text, string $default): string
    {
        return 'en';
    }

    /* ------------------------------------------------------------------ */
    /*  LLM system prompt (used only for unknown/free-form questions)      */
    /* ------------------------------------------------------------------ */

    private function systemPrompt(EventDetail $event, WhatsAppSetting $setting, string $language): string
    {
        $facts = [
            'event_name' => $event->event_name,
            'event_description' => $event->event_description,
            'event_date' => $event->event_date?->toDateString(),
            'event_time' => $event->event_time,
            'venue' => $event->venue,
            'venue_address' => $event->venue_address,
            'google_maps_url' => $event->google_maps_url,
            'dress_code' => $event->dress_code,
            'parking_information' => $event->parking_information,
            'accommodation_information' => $event->accommodation_information,
            'contact_number' => $event->contact_number ?: $setting->organizer_phone,
            'contact_person' => $event->contact_person ?: $setting->organizer_name,
            'event_program' => $event->event_program,
            'rsvp_link' => $event->rsvp_link,
            'rsvp_information' => $event->rsvp_information,
            'gift_information' => $event->gift_information,
            'important_instructions' => $event->important_instructions,
            'other_information' => $event->other_information,
        ];

        $facts = array_filter($facts, fn ($v) => filled($v));

        $faqs = WhatsAppFaq::query()->active()->get()
            ->map(fn (WhatsAppFaq $faq) => 'Q: ' . $faq->question . "\nA: " . $faq->answer)
            ->implode("\n\n");

        $factBlock = '';
        foreach ($facts as $key => $value) {
            $factBlock .= '- ' . $key . ': ' . $value . "\n";
        }

        $languageName = 'English';

        $eventLabel = $event->event_name ?: 'this event';
        $noInfo = $setting->noInfoReply();

        return "You are the official WhatsApp assistant for the event \"" . $eventLabel . "\".\n\n"
            . "OFFICIAL EVENT INFORMATION (only this is true):\n" . rtrim($factBlock)
            . ($faqs !== '' ? "\n\nOFFICIAL FAQS:\n" . $faqs : '')
            . "\n\nRULES:\n"
            . "- Always reply in {$languageName}.\n"
            . "- NEVER invent any information. Answer ONLY using the official event information and FAQs above.\n"
            . "- If the answer is not in the official information, reply: \"" . $noInfo . "\"\n"
            . "- Do not reveal any personal/private information of other guests.\n"
            . "- Keep replies short, clear, friendly and professional.\n"
            . "- If the user asks for their E-Card, invite card, invitation or allowance, ask them to send the exact word \"card\" so the system can verify their number.\n";
    }
}
