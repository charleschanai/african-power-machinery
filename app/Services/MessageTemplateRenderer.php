<?php

namespace App\Services;

use App\Models\Invitation;

/**
 * Fills {placeholder} tokens in a saved message.
 *
 * Kept separate from the message-building code so the exact same renderer is
 * used for the stored preview, the actual send, and the edit dialog. An admin
 * therefore always sees precisely what the guest will receive.
 */
class MessageTemplateRenderer
{
    /**
     * Every placeholder an admin can use, with a human label for the UI.
     */
    public const PLACEHOLDERS = [
        'guest_name' => 'Guest full name',
        'first_name' => 'Guest first name',
        'event_name' => 'Event name',
        'event_date' => 'Event date',
        'event_time' => 'Event time',
        'venue' => 'Venue',
        'wedding_details' => 'Everything printed on the card',
        'card_number' => 'Card number',
        'card_link' => 'Guest e-card link',
        'phone' => 'Guest phone number',
    ];

    /**
     * Builds the replacement map for one invitation.
     *
     * Missing values collapse to an empty string so a template never leaks a
     * raw {token} to the guest. Single-line values are flattened onto one line;
     * the card details are the exception and keep their own lines, because that
     * text is a block the guest is meant to read.
     */
    public function replacements(Invitation $invitation): array
    {
        $guest = $invitation->guest;
        $fullName = trim((string) ($guest?->full_name ?? ''));

        $values = [
            'guest_name' => $fullName,
            'first_name' => $fullName !== '' ? (explode(' ', $fullName)[0] ?? $fullName) : '',
            'event_name' => (string) ($invitation->event_name ?? ''),
            'event_date' => $invitation->event_date?->format('d/m/Y') ?? '',
            'event_time' => (string) ($invitation->event_time ?? ''),
            'venue' => (string) ($invitation->venue ?? ''),
            'card_number' => (string) ($invitation->card?->card_number ?? ''),
            'card_link' => (string) ($invitation->eCardUrl() ?? ''),
            'phone' => (string) ($invitation->phone ?? ''),
        ];

        // Newlines inside a single-line value would break the line structure the
        // admin wrote in the textarea, so those are folded to one space.
        $flat = array_map(
            fn ($value) => trim(preg_replace('/\s*[\r\n]+\s*/', ' ', (string) $value)),
            $values
        );

        /*
         * The card details are the opposite case: they are a block of lines
         * (couple, schedule, RSVP) and folding them onto one line would produce
         * an unreadable wall of text in the SMS. Newlines are kept, stray
         * carriage returns and double blanks are tidied, and the block is
         * trimmed so an empty field leaves no gap behind.
         */
        $details = str_replace(["\r\n", "\r"], "\n", (string) $invitation->wedding_details);
        $details = preg_replace('/[ \t]+$/m', '', $details);
        $details = preg_replace('/\n{3,}/', "\n\n", $details);

        $flat['wedding_details'] = trim((string) $details);

        return $flat;
    }

    /**
     * Replaces every known placeholder in $template.
     */
    public function render(?string $template, Invitation $invitation): string
    {
        $template = (string) $template;

        if (trim($template) === '') {
            return '';
        }

        $search = [];
        $replace = [];

        foreach ($this->replacements($invitation) as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }

        // strtr replaces in one pass, so a value that itself contains a
        // {token} is never re-expanded.
        $rendered = strtr($template, array_combine($search, $replace));

        // Tidy up: collapse runs of blank lines left behind by empty tokens and
        // trim the edges, without touching deliberate indentation.
        $rendered = preg_replace("/[ \t]+$/m", '', $rendered);
        $rendered = preg_replace("/\n{3,}/", "\n\n", $rendered);

        return trim($rendered);
    }

    /**
     * Placeholders present in a template, for highlighting in the editor.
     *
     * @return array<int, string>
     */
    public function usedPlaceholders(?string $template): array
    {
        preg_match_all('/\{([a-z_]+)\}/i', (string) $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
