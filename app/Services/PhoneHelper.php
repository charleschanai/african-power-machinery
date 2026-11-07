<?php

namespace App\Services;

class PhoneHelper
{
    /**
     * Normalize any local or international phone number to E.164
     * (digits only, without the leading "+").
     *
     * Handles the common Tanzanian formats:
     *  - 07XXXXXXXX / 0XXXXXXXXX  -> 255XXXXXXXXX
     *  - 7XXXXXXXX                -> 255XXXXXXXXX
     *  - 2557XXXXXXXX / +255...   -> 2557XXXXXXXX
     *
     * It also repairs the country code having been glued onto a number that was
     * already local, e.g. 2550650603711 is 255 followed by the local
     * 0650603711, and becomes 255650603711. Numbers like that are rejected by
     * every network, so the extra 0 is dropped rather than sent.
     */
    public static function toE164(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        // 255 followed by a zero-prefixed local number: 2550XXXXXXXX
        if (strlen($digits) === 13 && str_starts_with($digits, '2550')) {
            return '255' . substr($digits, 4);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '255')) {
            return $digits;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '255' . substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return '255' . $digits;
        }

        if (strlen($digits) === 13 && str_starts_with($digits, '255')) {
            return $digits;
        }

        return $digits;
    }

    /**
     * E.164 digits with a leading "+" (e.g. +255712345678).
     */
    public static function toDisplay(string $phone): ?string
    {
        $e164 = self::toE164($phone);

        return $e164 ? '+' . $e164 : null;
    }

    /**
     * Whether a number can actually receive a message.
     *
     * toE164() is deliberately permissive so that nothing is silently dropped
     * while a number is still being typed. That means it will happily hand
     * through something like "2550650603711" (a stray zero after the country
     * code, 13 digits). The SMS gateway accepts that request and reports
     * success, but the message is never delivered, which is far worse than a
     * clear rejection at the point of sending.
     *
     * A Tanzanian mobile number is the country code 255 followed by nine digits
     * beginning with 6 or 7. Landlines (022, 023, 024, 025, 026, 028) are
     * deliberately excluded because they cannot receive text.
     */
    public static function isDeliverable(?string $phone): bool
    {
        if (blank($phone)) {
            return false;
        }

        return (bool) preg_match('/^255[67]\d{8}$/', (string) self::toE164($phone));
    }

    /**
     * Whether the number has enough digits to be worth attempting at all.
     *
     * A number can fail isDeliverable() and still be a real number in some
     * other country, so this only rules out the hopeless cases (empty, a couple
     * of digits) rather than insisting on the Tanzanian shape.
     */
    public static function isPlausible(?string $phone): bool
    {
        $e164 = self::toE164((string) $phone);

        return $e164 !== null && strlen($e164) >= 9;
    }

    /**
     * A best-effort correction for a number that is nearly right.
     *
     * The common mistake is a zero left in after the country code, so
     * 2550650603711 becomes 255650603711. Returns null when nothing sensible
     * can be guessed, and the caller should then simply ask the admin to
     * re-check the number rather than silently rewriting real data.
     */
    public static function suggest(?string $phone): ?string
    {
        $e164 = self::toE164((string) $phone);

        if ($e164 === null || self::isDeliverable($e164)) {
            return null;
        }

        // 255 + zero-prefixed local number, when toE164 could not repair it
        // because the remainder is not a plausible mobile number.
        if (preg_match('/^2550([67]\d{8})$/', preg_replace('/\D+/', '', (string) $phone), $matches) === 1) {
            return '255' . $matches[1];
        }

        return null;
    }

    /**
     * A human explanation of what is wrong, for showing next to the input.
     */
    public static function problem(?string $phone): ?string
    {
        if (blank($phone)) {
            return 'The phone number is empty.';
        }

        if (self::isDeliverable($phone)) {
            return null;
        }

        $suggestion = self::suggest($phone);

        if ($suggestion !== null) {
            return 'The number has an extra digit. Did you mean ' . self::toDisplay($suggestion) . '?';
        }

        return 'This is not a valid Tanzanian mobile number. It should be 255 followed by 9 digits, for example 255712345678.';
    }
}
